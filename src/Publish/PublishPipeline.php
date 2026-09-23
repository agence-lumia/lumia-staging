<?php
/**
 * Pipeline de publication (F5, F7, F8, §7).
 *
 * verrou → contrôles → sauvegarde → copie des métas → CSS Bricks → caches
 * → validation des sauvegardes → suppression des versions → journal → verrou libéré.
 *
 * Une publication simple est un lot d'un seul élément : même code, même
 * garantie « tout ou rien ». Échec à n'importe quelle étape : restauration
 * automatique des sauvegardes, versions conservées.
 *
 * Hooks : `lmv_before_step` / `lmv_after_step` ( $step, $item ) autour de chaque
 * étape ; une exception levée dans un hook fait échouer la publication
 * (utilisé par les tests d'échec simulé).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Publish;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Adapter\CacheAdapter;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Preview\FeedbackRepository;
use Lumia\Staging\Preview\TokenRepository;
use Lumia\Staging\Service\ConflictDetector;
use Lumia\Staging\Service\ContentCopier;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Lock;
use Lumia\Staging\Support\Settings;
use Lumia\Staging\Support\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Item array{version_id: int, post_id: int, title: string, is_template: bool, snapshot_id: int}
 */
class PublishPipeline {

	public function __construct(
		private BricksAdapter $bricks,
		private CacheAdapter $cache,
		private SnapshotRepository $snapshots,
		private ContentCopier $copier,
		private ConflictDetector $conflicts,
		private VersionService $versions,
		private TokenRepository $tokens,
		private FeedbackRepository $feedback,
		private Lock $lock,
		private Transaction $tx,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * @param list<int>                                                            $version_ids
	 * @param array{force?: bool, note?: string, batch_id?: int, context?: string} $options
	 * @return array{items: list<Item>, warnings: list<string>}
	 */
	public function publish( array $version_ids, array $options = array() ): array {
		$force    = ! empty( $options['force'] );
		$note     = sanitize_textarea_field( (string) ( $options['note'] ?? '' ) );
		$batch_id = (int) ( $options['batch_id'] ?? 0 );
		$context  = (string) ( $options['context'] ?? 'manual' );

		$items = $this->build_items( $version_ids );
		$this->acquire_locks( $items, $batch_id );

		$done     = array();
		$current  = null;
		$warnings = array();
		$step     = 'check';

		try {
			foreach ( $items as $item ) {
				$this->check( $item, $force );
			}

			foreach ( $items as $item ) {
				$current = $item;

				$step = 'snapshot';
				$this->before( $step, $item );
				$item['snapshot_id'] = $this->snapshots->create(
					$item['post_id'],
					array(
						'kind'       => 'publish',
						'version_id' => $item['version_id'],
						'batch_id'   => $batch_id,
						'note'       => '' !== $note ? $note : $this->versions->note( $item['version_id'] ),
						'validation' => $this->feedback->validation_summary( $item['version_id'] ),
						'status'     => 'pending',
					)
				);
				$current             = $item;
				$this->after( $step, $item );

				$step = 'copy';
				$this->before( $step, $item );
				$this->tx->begin();
				try {
					$this->copier->publish_into( $item['version_id'], $item['post_id'], $this->settings->bool( 'publish_post_fields' ) );
					$this->tx->commit();
				} catch ( \Throwable $e ) {
					$this->tx->rollback();
					throw $e;
				}
				$this->after( $step, $item );

				$step = 'css';
				$this->before( $step, $item );
				$css = $this->bricks->regenerate_css( $item['post_id'] );
				if ( '' !== $css['warning'] ) {
					$warnings[] = $css['warning'];
					$this->logger->warning( 'css_fallback', $item['post_id'], $css['warning'] );
				}
				$this->after( $step, $item );

				$step = 'cache';
				$this->before( $step, $item );
				$this->cache->purge( $item['post_id'], $item['is_template'] );
				$this->after( $step, $item );

				$done[]  = $item;
				$current = null;
			}
		} catch ( \Throwable $e ) {
			$restore = $done;
			if ( null !== $current && $current['snapshot_id'] > 0 ) {
				$restore[] = $current;
			}
			$this->rollback( array_reverse( $restore ) );
			$this->release_locks( $items, $batch_id );

			if ( 'check' === $step && $e instanceof VersionException ) {
				throw $e;
			}
			$this->logger->log(
				'publish_failed',
				null !== $current ? $current['post_id'] : 0,
				null !== $current ? $current['version_id'] : 0,
				sprintf( 'Publication échouée à l\'étape « %s » : %s. Contenus restaurés : %d.', $step, $e->getMessage(), count( $restore ) ),
				array(
					'batch_id' => $batch_id,
					'context'  => $context,
				),
				'error'
			);
			throw new VersionException(
				sprintf(
					/* translators: 1: step name, 2: error message */
					__( 'La publication a échoué à l\'étape « %1$s » (%2$s). La version en ligne a été restaurée à l\'identique et la version de travail est conservée : vous pouvez réessayer.', 'lumia-staging' ),
					self::step_label( $step ),
					$e->getMessage()
				),
				'lmv_publish_failed',
				500,
				array( 'step' => $step ),
				$e
			);
		}

		// Tout a réussi : les sauvegardes deviennent l'historique, en une seule requête.
		$this->snapshots->mark( array_column( $done, 'snapshot_id' ), 'committed' );

		foreach ( $done as $item ) {
			update_post_meta( $item['version_id'], Meta::STATE, State::Published->value );

			/**
			 * Une version vient d'être publiée (R4 : brancher d'autres caches ici).
			 *
			 * @param int $post_id     Contenu original, désormais à jour.
			 * @param int $version_id  Version publiée (supprimée juste après).
			 * @param int $snapshot_id Sauvegarde de l'état précédent.
			 */
			do_action( 'lmv_after_publish', $item['post_id'], $item['version_id'], $item['snapshot_id'] );

			$this->tokens->delete_for_version( $item['version_id'] );
			$this->versions->delete( $item['version_id'] );
			$this->logger->log(
				'published',
				$item['post_id'],
				$item['version_id'],
				sprintf( '« %s » publié%s.', $item['title'], $batch_id > 0 ? ' (lot #' . $batch_id . ')' : '' ),
				array(
					'snapshot_id' => $item['snapshot_id'],
					'context'     => $context,
					'forced'      => $force,
				)
			);
		}
		$this->release_locks( $items, $batch_id );

		return array(
			'items'    => $done,
			'warnings' => $warnings,
		);
	}

	/**
	 * P5 : revenir à une sauvegarde. Une restauration est elle-même une
	 * publication : elle crée une sauvegarde de l'état courant, donc annulable.
	 *
	 * @return int ID de la sauvegarde de l'état remplacé (pour « Annuler »).
	 */
	public function restore( int $snapshot_id, bool $confirmed = false ): int {
		$snapshot = $this->snapshots->get( $snapshot_id );
		if ( null === $snapshot || 'committed' !== $snapshot['status'] ) {
			throw new VersionException( __( 'Cette sauvegarde n\'existe plus.', 'lumia-staging' ), 'lmv_not_found', 404 );
		}
		$post_id = (int) $snapshot['post_id'];
		$status  = get_post_status( $post_id );
		if ( false === $status || 'trash' === $status ) {
			throw new VersionException( __( 'Le contenu de cette sauvegarde a été supprimé.', 'lumia-staging' ), 'lmv_invalid_source', 404 );
		}
		$stored_bricks = (string) $snapshot['bricks_version'];
		if ( ! $confirmed && '' !== $stored_bricks && BricksAdapter::major( $stored_bricks ) !== BricksAdapter::major( $this->bricks->version() ) ) {
			throw new VersionException(
				sprintf(
					/* translators: 1: old Bricks version, 2: current Bricks version */
					__( 'Cette sauvegarde a été faite sous Bricks %1$s, le site utilise Bricks %2$s. L\'affichage peut différer : vérifiez l\'aperçu, puis confirmez la restauration.', 'lumia-staging' ),
					$stored_bricks,
					$this->bricks->version()
				),
				'lmv_confirm_required',
				409,
				array( 'reason' => 'bricks_version' )
			);
		}
		if ( ! $this->lock->acquire( 'post', $post_id ) ) {
			throw new VersionException( __( 'Une publication est déjà en cours sur ce contenu.', 'lumia-staging' ), 'lmv_locked', 409 );
		}

		$is_template = $this->bricks->is_template( $post_id );
		$backup_id   = 0;
		try {
			$backup_id = $this->snapshots->create(
				$post_id,
				array(
					'kind'   => 'restore',
					'note'   => sprintf(
						/* translators: %s: date */
						__( 'Retour à la version du %s', 'lumia-staging' ),
						wp_date( (string) get_option( 'date_format' ) . ' H:i', (int) strtotime( $snapshot['created_at'] . ' UTC' ) )
					),
					'status' => 'pending',
				)
			);
			$this->tx->begin();
			try {
				$this->snapshots->apply( $post_id, (array) $snapshot['data'], $this->settings->bool( 'publish_post_fields' ) );
				$this->tx->commit();
			} catch ( \Throwable $e ) {
				$this->tx->rollback();
				throw $e;
			}
			$css = $this->bricks->regenerate_css( $post_id );
			if ( '' !== $css['warning'] ) {
				$this->logger->warning( 'css_fallback', $post_id, $css['warning'] );
			}
			$this->cache->purge( $post_id, $is_template );
			$this->snapshots->mark( array( $backup_id ), 'committed' );

			do_action( 'lmv_after_restore', $post_id, $snapshot_id, $backup_id );
			do_action( 'lmv_after_publish', $post_id, 0, $backup_id );
			$this->logger->log( 'restored', $post_id, 0, sprintf( '« %s » restauré (sauvegarde #%d).', get_the_title( $post_id ), $snapshot_id ), array( 'backup_id' => $backup_id ) );
			return $backup_id;
		} catch ( VersionException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			if ( $backup_id > 0 ) {
				$this->rollback(
					array(
						array(
							'version_id'  => 0,
							'post_id'     => $post_id,
							'title'       => '',
							'is_template' => $is_template,
							'snapshot_id' => $backup_id,
						),
					)
				);
			}
			$this->logger->log( 'restore_failed', $post_id, 0, $e->getMessage(), array(), 'error' );
			throw new VersionException(
				sprintf(
					/* translators: %s: error */
					__( 'La restauration a échoué (%s). Le contenu en ligne est inchangé.', 'lumia-staging' ),
					$e->getMessage()
				),
				'lmv_restore_failed',
				500,
				array(),
				$e
			);
		} finally {
			$this->lock->release( 'post', $post_id );
		}
	}

	/**
	 * Restaure des contenus depuis leurs sauvegardes « pending ».
	 * Utilisé en cas d'échec et par la reprise après incident.
	 *
	 * @param list<Item> $items Dans l'ordre de restauration.
	 */
	public function rollback( array $items, string $final_status = 'rolled_back' ): void {
		foreach ( $items as $item ) {
			try {
				$snapshot = $this->snapshots->get( $item['snapshot_id'] );
				if ( null === $snapshot ) {
					continue;
				}
				$this->snapshots->apply( $item['post_id'], (array) $snapshot['data'], true );
				$this->bricks->regenerate_css( $item['post_id'] );
				$this->cache->purge( $item['post_id'], $item['is_template'] );
				$this->snapshots->mark( array( $item['snapshot_id'] ), $final_status );
			} catch ( \Throwable $e ) {
				$this->logger->log( 'rollback_failed', $item['post_id'], $item['version_id'], 'Restauration impossible : ' . $e->getMessage() . ' — restaurez la sauvegarde #' . $item['snapshot_id'] . ' manuellement (wp lmv restore).', array(), 'error' );
			}
		}
	}

	public static function step_label( string $step ): string {
		$labels = array(
			'check'    => __( 'contrôles', 'lumia-staging' ),
			'snapshot' => __( 'sauvegarde de la version en ligne', 'lumia-staging' ),
			'copy'     => __( 'copie du contenu', 'lumia-staging' ),
			'css'      => __( 'régénération du CSS', 'lumia-staging' ),
			'cache'    => __( 'purge des caches', 'lumia-staging' ),
		);
		return $labels[ $step ] ?? $step;
	}

	/**
	 * F7 : templates d'abord, puis pages.
	 *
	 * @param list<int> $version_ids
	 * @return list<Item>
	 */
	private function build_items( array $version_ids ): array {
		$items = array();
		foreach ( array_values( array_unique( array_map( 'intval', $version_ids ) ) ) as $version_id ) {
			$this->versions->assert_version( $version_id );
			$post_id = $this->versions->source_id( $version_id );
			$items[] = array(
				'version_id'  => $version_id,
				'post_id'     => $post_id,
				'title'       => (string) get_the_title( $post_id ),
				'is_template' => $this->bricks->is_template( $post_id ),
				'snapshot_id' => 0,
			);
		}
		if ( array() === $items ) {
			throw new VersionException( __( 'Aucune version à publier.', 'lumia-staging' ), 'lmv_empty', 400 );
		}
		$sources = array_column( $items, 'post_id' );
		if ( count( $sources ) !== count( array_unique( $sources ) ) ) {
			throw new VersionException( __( 'Deux versions du lot concernent le même contenu.', 'lumia-staging' ), 'lmv_duplicate', 400 );
		}
		usort( $items, static fn( $a, $b ) => (int) $b['is_template'] <=> (int) $a['is_template'] );
		return $items;
	}

	/**
	 * @param Item $item
	 */
	private function check( array $item, bool $force ): void {
		$version_id = $item['version_id'];
		$post_id    = $item['post_id'];
		$this->versions->assert_version( $version_id );

		$state = $this->versions->state( $version_id );
		if ( ! $state->is_publishable() ) {
			throw new VersionException( sprintf( /* translators: %s: state */ __( 'Cette version ne peut pas être publiée dans l\'état « %s ».', 'lumia-staging' ), $state->label() ), 'lmv_bad_transition', 409 );
		}
		$source_status = get_post_status( $post_id );
		if ( $this->versions->is_orphan( $version_id ) || false === $source_status || 'trash' === $source_status ) {
			throw new VersionException( sprintf( /* translators: %s: title */ __( 'L\'original de « %s » a été supprimé : la version ne peut plus être publiée. Exportez-la ou abandonnez-la.', 'lumia-staging' ), $item['title'] ), 'lmv_orphan', 409 );
		}
		if ( $item['is_template'] && $this->bricks->template_type( $version_id ) !== $this->bricks->template_type( $post_id ) ) {
			throw new VersionException( sprintf( /* translators: %s: title */ __( 'Le type de template de la version de « %s » a été changé : publication bloquée pour ne pas modifier l\'affichage du site. Remettez le type d\'origine.', 'lumia-staging' ), $item['title'] ), 'lmv_template_type', 409 );
		}
		if ( ! $force && $this->conflicts->source_changed( $version_id, $post_id ) ) {
			throw new VersionException(
				sprintf( /* translators: %s: title */ __( '« %s » a été modifié directement depuis la création de la version. Publier écraserait ces modifications : comparez d\'abord, ou publiez quand même.', 'lumia-staging' ), $item['title'] ),
				'lmv_conflict',
				409,
				array( 'version_id' => $version_id )
			);
		}
	}

	/**
	 * @param list<Item> $items
	 */
	private function acquire_locks( array $items, int $batch_id ): void {
		if ( $batch_id > 0 && ! $this->lock->acquire( 'batch', $batch_id ) ) {
			throw new VersionException( __( 'Ce lot est déjà en cours de publication.', 'lumia-staging' ), 'lmv_locked', 409 );
		}
		foreach ( $items as $item ) {
			if ( ! $this->lock->acquire( 'post', $item['post_id'] ) ) {
				$this->release_locks( $items, $batch_id );
				throw new VersionException( sprintf( /* translators: %s: title */ __( 'Publication déjà en cours pour « %s ».', 'lumia-staging' ), $item['title'] ), 'lmv_locked', 409 );
			}
		}
	}

	/**
	 * @param list<Item> $items
	 */
	private function release_locks( array $items, int $batch_id ): void {
		foreach ( $items as $item ) {
			$this->lock->release( 'post', $item['post_id'] );
		}
		if ( $batch_id > 0 ) {
			$this->lock->release( 'batch', $batch_id );
		}
	}

	/**
	 * @param Item $item
	 */
	private function before( string $step, array $item ): void {
		do_action( 'lmv_before_step', $step, $item );
	}

	/**
	 * @param Item $item
	 */
	private function after( string $step, array $item ): void {
		do_action( 'lmv_after_step', $step, $item );
	}
}

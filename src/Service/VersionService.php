<?php
/**
 * Créer, reprendre, abandonner une version ; gérer ses états.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Service;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Post\PostStatus;
use Lumia\Staging\Support\Lock;
use Lumia\Staging\Support\Settings;

defined( 'ABSPATH' ) || exit;

class VersionService {

	public function __construct(
		private \wpdb $db,
		private BricksAdapter $bricks,
		private ContentCopier $copier,
		private ConflictDetector $conflicts,
		private PostStatus $status,
		private Lock $lock,
		private Settings $settings,
		private Logger $logger
	) {}

	/*
	------------------------------------------------------------------ */
	/*
	Lecture                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @phpstan-impure
	 */
	public function is_version( int $post_id ): bool {
		return $post_id > 0 && PostStatus::STATUS === get_post_status( $post_id );
	}

	public function source_id( int $version_id ): int {
		return (int) get_post_meta( $version_id, Meta::SOURCE_ID, true );
	}

	public function state( int $version_id ): State {
		return State::from_meta( get_post_meta( $version_id, Meta::STATE, true ) );
	}

	public function is_orphan( int $version_id ): bool {
		return '1' === (string) get_post_meta( $version_id, Meta::ORPHAN, true );
	}

	public function supports( int $post_id ): bool {
		$type = (string) get_post_type( $post_id );
		if ( ! in_array( $type, $this->settings->post_types(), true ) ) {
			return false;
		}
		// Pages non Bricks : versionnables seulement si le mode Bricks est actif (§2).
		return $this->bricks->is_bricks_post( $post_id );
	}

	/**
	 * Version ouverte pour un contenu. Requête directe : doit fonctionner
	 * aussi en tâche programmée, sans utilisateur connecté.
	 */
	public function open_version_for( int $source_id ): int {
		$sql = "SELECT p.ID FROM {$this->db->posts} p
			INNER JOIN {$this->db->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_status = %s AND m.meta_value = %s
			ORDER BY p.ID DESC LIMIT 1";
		return (int) $this->db->get_var( $this->db->prepare( $sql, Meta::SOURCE_ID, PostStatus::STATUS, (string) $source_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * IDs des versions existantes, plus récentes d'abord.
	 *
	 * @param array{state?: string, search?: string, source_id?: int, batch_id?: int} $filters
	 * @return list<int>
	 */
	public function list_ids( array $filters = array() ): array {
		$join   = '';
		$where  = array( 'p.post_status = %s' );
		$params = array( PostStatus::STATUS );
		if ( ! empty( $filters['state'] ) ) {
			$join    .= " INNER JOIN {$this->db->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '" . Meta::STATE . "'";
			$where[]  = 'ms.meta_value = %s';
			$params[] = $filters['state'];
		}
		if ( ! empty( $filters['source_id'] ) ) {
			$join    .= " INNER JOIN {$this->db->postmeta} mo ON mo.post_id = p.ID AND mo.meta_key = '" . Meta::SOURCE_ID . "'";
			$where[]  = 'mo.meta_value = %s';
			$params[] = (string) $filters['source_id'];
		}
		if ( ! empty( $filters['batch_id'] ) ) {
			$join    .= " INNER JOIN {$this->db->postmeta} mb ON mb.post_id = p.ID AND mb.meta_key = '" . Meta::BATCH_ID . "'";
			$where[]  = 'mb.meta_value = %s';
			$params[] = (string) $filters['batch_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'p.post_title LIKE %s';
			$params[] = '%' . $this->db->esc_like( $filters['search'] ) . '%';
		}
		$sql = "SELECT p.ID FROM {$this->db->posts} p {$join} WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.post_modified_gmt DESC LIMIT 500';
		return array_values( array_map( 'intval', $this->db->get_col( $this->db->prepare( $sql, $params ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/*
	------------------------------------------------------------------ */
	/*
	F1 — Créer                                                          */
	/* ------------------------------------------------------------------ */

	public function create( int $source_id, string $note = '' ): int {
		$source = get_post( $source_id );
		if ( ! $source instanceof \WP_Post || in_array( $source->post_status, array( 'trash', 'auto-draft', 'inherit', PostStatus::STATUS ), true ) ) {
			throw new VersionException( __( 'Ce contenu n\'existe pas ou ne peut pas avoir de version de travail.', 'lumia-staging' ), 'lmv_invalid_source', 404 );
		}
		if ( ! $this->supports( $source_id ) ) {
			throw new VersionException( __( 'Ce contenu n\'est pas édité avec Bricks ou son type n\'est pas activé dans les réglages de Lümia Staging.', 'lumia-staging' ), 'lmv_unsupported', 400 );
		}
		if ( ! $this->lock->acquire( 'post', $source_id ) ) {
			throw new VersionException( __( 'Une opération est déjà en cours sur ce contenu. Réessayez dans quelques secondes.', 'lumia-staging' ), 'lmv_locked', 409 );
		}

		try {
			$existing = $this->open_version_for( $source_id );
			if ( $existing > 0 ) {
				throw new VersionException(
					__( 'Une version de travail est déjà ouverte pour ce contenu. Reprenez-la plutôt que d\'en créer une seconde.', 'lumia-staging' ),
					'lmv_version_exists',
					409,
					array(
						'version_id' => $existing,
						'edit_url'   => $this->bricks->builder_url( $existing ),
					)
				);
			}

			$version_id = $this->status->internal(
				fn() => wp_insert_post(
					wp_slash(
						array(
							'post_type'      => $source->post_type,
							'post_status'    => PostStatus::STATUS,
							'post_title'     => $source->post_title,
							'post_content'   => $source->post_content,
							'post_excerpt'   => $source->post_excerpt,
							'post_author'    => get_current_user_id() > 0 ? get_current_user_id() : (int) $source->post_author,
							'post_name'      => 'lmv-version-' . $source_id . '-' . strtolower( wp_generate_password( 6, false ) ),
							'menu_order'     => $source->menu_order,
							'comment_status' => 'closed',
							'ping_status'    => 'closed',
						)
					),
					true
				)
			);
			if ( $version_id instanceof \WP_Error ) {
				throw new VersionException( $version_id->get_error_message(), 'lmv_insert_failed', 500 );
			}
			$version_id = (int) $version_id;

			$this->copier->copy_to_version( $source_id, $version_id );
			update_post_meta( $version_id, Meta::SOURCE_ID, $source_id );
			update_post_meta( $version_id, Meta::STATE, State::InProgress->value );
			if ( '' !== $note ) {
				update_post_meta( $version_id, Meta::NOTE, sanitize_textarea_field( $note ) );
			}
			$this->conflicts->stamp( $version_id, $source_id );
			// Le save_post de Bricks a créé un fichier CSS vide pour la version
			// (métas pas encore copiées) : on le génère avec son contenu.
			$this->bricks->regenerate_css( $version_id );

			/**
			 * Une version vient d'être créée.
			 *
			 * @param int $version_id
			 * @param int $source_id
			 */
			do_action( 'lmv_version_created', $version_id, $source_id );
			$this->logger->log( 'version_created', $source_id, $version_id, sprintf( 'Version de travail créée pour « %s ».', $source->post_title ) );

			return $version_id;
		} finally {
			$this->lock->release( 'post', $source_id );
		}
	}

	/*
	------------------------------------------------------------------ */
	/*
	États                                                               */
	/* ------------------------------------------------------------------ */

	public function transition( int $version_id, State $to ): void {
		$this->assert_version( $version_id );
		$from = $this->state( $version_id );
		if ( $from === $to ) {
			return;
		}
		if ( ! $from->can( $to ) ) {
			throw new VersionException(
				sprintf(
					/* translators: 1: current state, 2: target state */
					__( 'Action impossible : la version est « %1$s » et ne peut pas passer à « %2$s ».', 'lumia-staging' ),
					$from->label(),
					$to->label()
				),
				'lmv_bad_transition',
				409
			);
		}
		update_post_meta( $version_id, Meta::STATE, $to->value );
		do_action( 'lmv_version_state_changed', $version_id, $to->value, $from->value );
	}

	public function set_note( int $version_id, string $note ): void {
		$this->assert_version( $version_id );
		update_post_meta( $version_id, Meta::NOTE, sanitize_textarea_field( $note ) );
	}

	public function note( int $version_id ): string {
		return (string) get_post_meta( $version_id, Meta::NOTE, true );
	}

	/**
	 * Abandonne et supprime la version. Une version validée par le client
	 * demande une confirmation explicite (§9).
	 */
	public function abandon( int $version_id, bool $confirmed = false ): void {
		$this->assert_version( $version_id );
		$state = $this->state( $version_id );
		if ( State::Approved === $state && ! $confirmed ) {
			throw new VersionException( __( 'Cette version a été validée par le client. Confirmez l\'abandon : elle sera définitivement supprimée.', 'lumia-staging' ), 'lmv_confirm_required', 409 );
		}
		$source_id = $this->source_id( $version_id );
		$this->transition( $version_id, State::Abandoned );
		do_action( 'lmv_version_abandoned', $version_id, $source_id );
		$this->delete( $version_id );
		$this->logger->log( 'version_abandoned', $source_id, $version_id, 'Version de travail abandonnée.' );
	}

	/**
	 * Suppression définitive d'une version et de son CSS.
	 */
	public function delete( int $version_id ): void {
		$this->status->internal( static fn() => wp_delete_post( $version_id, true ) );
		$this->bricks->delete_css_file( $version_id );
	}

	/*
	------------------------------------------------------------------ */
	/*
	R11 — Original supprimé                                             */
	/* ------------------------------------------------------------------ */

	public function mark_orphans( int $source_id, bool $orphan ): void {
		foreach ( $this->list_ids( array( 'source_id' => $source_id ) ) as $version_id ) {
			if ( $orphan ) {
				update_post_meta( $version_id, Meta::ORPHAN, '1' );
				$this->logger->warning( 'version_orphaned', $source_id, 'Original mis à la corbeille : la version ne peut plus être publiée.' );
			} else {
				delete_post_meta( $version_id, Meta::ORPHAN );
			}
		}
	}

	/**
	 * Export JSON d'une version (seule issue d'une version orpheline avec l'abandon).
	 *
	 * @return array<string, mixed>
	 */
	public function export( int $version_id ): array {
		$this->assert_version( $version_id );
		return array(
			'plugin'         => 'lumia-staging',
			'format'         => 1,
			'exported_at'    => gmdate( 'c' ),
			'source_id'      => $this->source_id( $version_id ),
			'title'          => get_the_title( $version_id ),
			'post_type'      => get_post_type( $version_id ),
			'bricks_version' => get_post_meta( $version_id, Meta::BRICKS_VERSION, true ),
			'note'           => $this->note( $version_id ),
			'meta'           => $this->bricks->meta( $version_id ),
		);
	}

	public function assert_version( int $version_id ): void {
		if ( ! $this->is_version( $version_id ) ) {
			throw new VersionException( __( 'Cette version n\'existe plus : elle a peut-être déjà été publiée ou abandonnée.', 'lumia-staging' ), 'lmv_not_found', 404 );
		}
	}
}

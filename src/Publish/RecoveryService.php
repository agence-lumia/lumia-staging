<?php
/**
 * Reprise après incident (§11).
 *
 * Si PHP s'arrête au milieu d'une publication (délai dépassé, erreur
 * fatale, conteneur redémarré), la sauvegarde reste « pending ». À la
 * requête admin suivante, si le verrou SQL du contenu est libre (le serveur
 * SQL l'a libéré à la fin de la connexion), la sauvegarde est restaurée.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Publish;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Lock;

defined( 'ABSPATH' ) || exit;

class RecoveryService {

	public const NOTICE_OPTION = 'lmv_recovery_notices';

	public function __construct(
		private SnapshotRepository $snapshots,
		private PublishPipeline $pipeline,
		private VersionService $versions,
		private BricksAdapter $bricks,
		private Lock $lock,
		private Logger $logger
	) {}

	/**
	 * @return int Nombre de contenus restaurés.
	 */
	public function run(): int {
		$pending = $this->snapshots->pending();
		if ( array() === $pending ) {
			$this->cleanup_published_leftovers();
			return 0;
		}

		$groups = array();
		foreach ( $pending as $row ) {
			$key              = $row['batch_id'] > 0 ? 'b' . $row['batch_id'] : 's' . $row['id'];
			$groups[ $key ][] = $row;
		}

		$restored = 0;
		foreach ( $groups as $rows ) {
			$batch_id = $rows[0]['batch_id'];
			if ( $batch_id > 0 && ! $this->lock->acquire( 'batch', $batch_id ) ) {
				continue; // Lot encore en cours.
			}
			$locked = array();
			$busy   = false;
			foreach ( $rows as $row ) {
				if ( ! $this->lock->acquire( 'post', $row['post_id'] ) ) {
					$busy = true;
					break;
				}
				$locked[] = $row['post_id'];
			}
			if ( ! $busy ) {
				$items = array();
				foreach ( array_reverse( $rows ) as $row ) {
					$items[] = array(
						'version_id'  => $row['version_id'],
						'post_id'     => $row['post_id'],
						'title'       => (string) get_the_title( $row['post_id'] ),
						'is_template' => $this->bricks->is_template( $row['post_id'] ),
						'snapshot_id' => $row['id'],
					);
				}
				$this->pipeline->rollback( $items, 'recovered' );
				foreach ( $items as $item ) {
					++$restored;
					$this->logger->log( 'publish_recovered', $item['post_id'], $item['version_id'], sprintf( 'Publication interrompue de « %s » : version en ligne restaurée, version de travail conservée.', $item['title'] ), array(), 'warning' );
					$this->add_notice( $item['title'] );
					if ( $item['version_id'] > 0 && $this->versions->is_version( $item['version_id'] ) && State::Published->value === get_post_meta( $item['version_id'], Meta::STATE, true ) ) {
						update_post_meta( $item['version_id'], Meta::STATE, State::InProgress->value );
					}
				}
			}
			foreach ( $locked as $post_id ) {
				$this->lock->release( 'post', $post_id );
			}
			if ( $batch_id > 0 ) {
				$this->lock->release( 'batch', $batch_id );
			}
		}
		return $restored;
	}

	/**
	 * Versions marquées publiées mais pas supprimées (arrêt juste après la
	 * validation) : la publication est bien en ligne, on termine le ménage.
	 */
	private function cleanup_published_leftovers(): void {
		foreach ( $this->versions->list_ids( array( 'state' => State::Published->value ) ) as $version_id ) {
			$this->versions->delete( $version_id );
		}
	}

	private function add_notice( string $title ): void {
		$notices   = (array) get_option( self::NOTICE_OPTION, array() );
		$notices[] = $title;
		update_option( self::NOTICE_OPTION, array_slice( array_unique( $notices ), -10 ), false );
	}
}

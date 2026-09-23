<?php
/**
 * Commandes WP-CLI (F11) : wp lmv list | create | publish | restore | history | cleanup.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Cli;

use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Install\Schema;
use Lumia\Staging\Publish\PublishPipeline;
use Lumia\Staging\Publish\RecoveryService;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Settings;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Gère les versions de travail Lümia Staging.
 */
final class CliCommand {

	public function __construct(
		private VersionService $versions,
		private PublishPipeline $pipeline,
		private SnapshotRepository $snapshots,
		private RecoveryService $recovery,
		private Settings $settings
	) {}

	/**
	 * Liste les versions de travail ouvertes.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : in_progress, in_review, approved, scheduled.
	 *
	 * [--format=<format>]
	 * : table, json, csv, ids. Défaut : table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lmv list --state=approved
	 *
	 * @subcommand list
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function list_( array $args, array $assoc ): void {
		$ids = $this->versions->list_ids( array( 'state' => (string) ( $assoc['state'] ?? '' ) ) );
		if ( 'ids' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$source = $this->versions->source_id( $id );
			$rows[] = array(
				'version'  => $id,
				'original' => $source,
				'titre'    => get_the_title( $source ),
				'type'     => get_post_type( $source ),
				'etat'     => $this->versions->state( $id )->value,
				'auteur'   => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) ),
				'modifie'  => get_post_field( 'post_modified', $id ),
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'version', 'original', 'titre', 'type', 'etat', 'auteur', 'modifie' ) );
	}

	/**
	 * Crée une version de travail d'une page ou d'un template.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID du contenu original.
	 *
	 * [--user=<id>]
	 * : Auteur de la version (utilisez l'option globale --user de WP-CLI).
	 *
	 * [--note=<note>]
	 * : Note libre.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function create( array $args, array $assoc ): void {
		$this->run(
			function () use ( $args, $assoc ) {
				$id = $this->versions->create( (int) $args[0], (string) ( $assoc['note'] ?? '' ) );
				WP_CLI::success( sprintf( 'Version %d créée pour le contenu %d.', $id, (int) $args[0] ) );
			}
		);
	}

	/**
	 * Publie une ou plusieurs versions (plusieurs = lot tout ou rien).
	 *
	 * ## OPTIONS
	 *
	 * <version_id>...
	 * : ID des versions.
	 *
	 * [--note=<note>]
	 * : Note reprise dans l'historique.
	 *
	 * [--force]
	 * : Publie même si l'original a changé (écrase).
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function publish( array $args, array $assoc ): void {
		$this->run(
			function () use ( $args, $assoc ) {
				$result = $this->pipeline->publish(
					array_map( 'intval', $args ),
					array(
						'note'    => (string) ( $assoc['note'] ?? '' ),
						'force'   => isset( $assoc['force'] ),
						'context' => 'cli',
					)
				);
				foreach ( $result['warnings'] as $warning ) {
					WP_CLI::warning( $warning );
				}
				foreach ( $result['items'] as $item ) {
					WP_CLI::success( sprintf( '« %s » (#%d) publié. Sauvegarde de l\'état précédent : #%d.', $item['title'], $item['post_id'], $item['snapshot_id'] ) );
				}
			}
		);
	}

	/**
	 * Restaure une sauvegarde (voir `wp lmv history <post_id>`).
	 *
	 * ## OPTIONS
	 *
	 * <snapshot_id>
	 * : ID de la sauvegarde.
	 *
	 * [--yes]
	 * : Confirme même si la sauvegarde date d'une autre version majeure de Bricks.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function restore( array $args, array $assoc ): void {
		$this->run(
			function () use ( $args, $assoc ) {
				$backup = $this->pipeline->restore( (int) $args[0], isset( $assoc['yes'] ) );
				WP_CLI::success( sprintf( 'Sauvegarde #%d restaurée. Pour annuler : wp lmv restore %d', (int) $args[0], $backup ) );
			}
		);
	}

	/**
	 * Affiche l'historique des publications d'un contenu.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID du contenu.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Défaut : table.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function history( array $args, array $assoc ): void {
		$rows = array_map(
			static fn( $r ) => array(
				'sauvegarde' => $r['id'],
				'remplacee'  => $r['created_at'] . ' UTC',
				'type'       => $r['kind'],
				'note'       => $r['note'],
				'bricks'     => $r['bricks_version'],
			),
			$this->snapshots->history( (int) $args[0] )
		);
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'sauvegarde', 'remplacee', 'type', 'note', 'bricks' ) );
	}

	/**
	 * Reprise après incident, rétention des sauvegardes, purge du journal.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function cleanup( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$recovered = $this->recovery->run();
		$deleted   = $this->snapshots->cleanup( $this->settings->int( 'retention_count' ), $this->settings->int( 'retention_days' ) );
		\Lumia\Staging\Plugin::daily_cleanup();
		WP_CLI::success( sprintf( '%d publication(s) interrompue(s) restaurée(s), %d sauvegarde(s) supprimée(s) par la rétention.', $recovered, $deleted ) );
	}

	/**
	 * Redescend le schéma de base de données (migrations réversibles).
	 *
	 * ## OPTIONS
	 *
	 * <version>
	 * : Numéro de schéma cible.
	 *
	 * @subcommand schema-rollback
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function schema_rollback( array $args, array $assoc ): void {
		unset( $assoc );
		Schema::rollback( (int) $args[0] );
		WP_CLI::success( 'Schéma ramené à la version ' . (int) $args[0] . '.' );
	}

	/**
	 * @param callable(): void $operation
	 */
	private function run( callable $operation ): void {
		try {
			$operation();
		} catch ( VersionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}

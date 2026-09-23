<?php
/**
 * Tables dédiées, créées et migrées par dbDelta avec un numéro de schéma.
 *
 * Tables préfixées par $wpdb->prefix : elles sont donc incluses dans les
 * sauvegardes WPvivid.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION = 1;

	public const OPTION = 'lmv_db_version';

	public const TABLES = array( 'lmv_snapshots', 'lmv_tokens', 'lmv_feedback', 'lmv_batches', 'lmv_log' );

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Appelé à chaque chargement admin et à l'activation : ne fait rien si
	 * le schéma est à jour.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::OPTION, 0 );
		if ( $installed >= self::VERSION ) {
			return;
		}
		self::install();
		foreach ( self::migrations() as $version => $migration ) {
			if ( $version > $installed && $version <= self::VERSION ) {
				$migration['up']();
			}
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}lmv_snapshots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  version_id bigint(20) unsigned NOT NULL DEFAULT 0,
  batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT 'publish',
  status varchar(20) NOT NULL DEFAULT 'pending',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note text NULL,
  validation text NULL,
  data longtext NOT NULL,
  hash char(64) NOT NULL DEFAULT '',
  bricks_version varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_status (post_id,status,created_at),
  KEY status (status),
  KEY batch_id (batch_id)
) {$charset};
CREATE TABLE {$p}lmv_tokens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  context text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY version_id (version_id)
) {$charset};
CREATE TABLE {$p}lmv_feedback (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version_id bigint(20) unsigned NOT NULL,
  token_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL DEFAULT '',
  decision varchar(20) NOT NULL,
  comment text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY version_id (version_id)
) {$charset};
CREATE TABLE {$p}lmv_batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'pending',
  scheduled_at datetime NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note text NULL,
  message text NULL,
  created_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};
CREATE TABLE {$p}lmv_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  level varchar(10) NOT NULL DEFAULT 'info',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  version_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object_id (object_id),
  KEY action (action)
) {$charset};"
		);
	}

	/**
	 * Migrations réversibles, indexées par numéro de schéma. dbDelta gère
	 * les ajouts de colonnes ; les migrations servent aux transformations
	 * de données et doivent toujours fournir `down`.
	 *
	 * @return array<int, array{up: callable(): void, down: callable(): void}>
	 */
	public static function migrations(): array {
		return array(
			1 => array(
				'up'   => static function (): void {},
				'down' => static function (): void {},
			),
		);
	}

	/**
	 * Redescend le schéma jusqu'à $target (WP-CLI `wp lmv schema-rollback`).
	 */
	public static function rollback( int $target ): void {
		$installed = (int) get_option( self::OPTION, 0 );
		$steps     = self::migrations();
		krsort( $steps );
		foreach ( $steps as $version => $migration ) {
			if ( $version <= $installed && $version > $target ) {
				$migration['down']();
			}
		}
		update_option( self::OPTION, $target, false );
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		delete_option( self::OPTION );
	}
}

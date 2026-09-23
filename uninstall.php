<?php
/**
 * Désinstallation : les données ne sont supprimées que si l'option
 * « Supprimer toutes les données » est cochée dans les réglages.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lmv_settings = get_option( 'lmv_settings', array() );
if ( ! is_array( $lmv_settings ) || empty( $lmv_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Versions de travail (statut lmv-version) et leurs métas.
$lmv_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s", 'lmv-version' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $lmv_ids as $lmv_id ) {
	wp_delete_post( (int) $lmv_id, true );
}
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lmv\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'lmv_snapshots', 'lmv_tokens', 'lmv_feedback', 'lmv_batches', 'lmv_log' ) as $lmv_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $lmv_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}

foreach ( array( 'lmv_settings', 'lmv_db_version', 'lmv_recovery_notices' ) as $lmv_option ) {
	delete_option( $lmv_option );
}
delete_site_transient( 'lmv_github_release' );
delete_site_transient( 'lmv_github_release_stable' );
delete_site_transient( 'lmv_github_release_dev' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lmv\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lmv\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( wp_roles()->role_objects as $lmv_role ) {
	foreach ( array( 'lmv_create_version', 'lmv_publish_version', 'lmv_restore_version', 'lmv_share_preview', 'lmv_manage_settings' ) as $lmv_cap ) {
		$lmv_role->remove_cap( $lmv_cap );
	}
}

wp_clear_scheduled_hook( 'lmv_daily_cleanup' );

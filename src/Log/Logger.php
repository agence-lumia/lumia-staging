<?php
/**
 * Journal d'activité (F10) : qui, quoi, quand, sur quel contenu.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Log;

use Lumia\Staging\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public function __construct( private \wpdb $db ) {}

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $action, int $object_id = 0, int $version_id = 0, string $message = '', array $context = array(), string $level = 'info' ): void {
		$this->db->insert(
			Schema::table( 'lmv_log' ),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'action'     => substr( sanitize_key( $action ), 0, 64 ),
				'level'      => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'object_id'  => max( 0, $object_id ),
				'version_id' => max( 0, $version_id ),
				'message'    => $message,
				'context'    => array() === $context ? null : (string) wp_json_encode( $context ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $action, int $object_id, string $message, array $context = array() ): void {
		$this->log( $action, $object_id, 0, $message, $context, 'warning' );
	}

	/**
	 * @param array{action?: string, object_id?: int, level?: string, search?: string} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function query( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
		$table  = Schema::table( 'lmv_log' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $filters['action'];
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = $filters['object_id'];
		}
		if ( ! empty( $filters['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $this->db->esc_like( $filters['search'] ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$total     = (int) $this->db->get_var( array() === $params ? $total_sql : $this->db->prepare( $total_sql, $params ) );
		$rows      = $this->db->get_results( $this->db->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$user    = get_userdata( (int) $row['user_id'] );
			$items[] = array(
				'id'         => (int) $row['id'],
				'date'       => mysql_to_rfc3339( (string) $row['created_at'] ),
				'user'       => $user ? $user->display_name : ( '0' === (string) $row['user_id'] ? __( 'Système', 'lumia-staging' ) : '#' . $row['user_id'] ),
				'action'     => (string) $row['action'],
				'level'      => (string) $row['level'],
				'object_id'  => (int) $row['object_id'],
				'object'     => (int) $row['object_id'] > 0 ? (string) get_the_title( (int) $row['object_id'] ) : '',
				'version_id' => (int) $row['version_id'],
				'message'    => (string) $row['message'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	public function purge( int $months ): int {
		$table = Schema::table( 'lmv_log' );
		$limit = gmdate( 'Y-m-d H:i:s', time() - $months * 30 * DAY_IN_SECONDS );
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE created_at < %s", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Écrit le journal en CSV sur un flux (export F10).
	 *
	 * @param resource $out
	 */
	public function export_csv( $out ): void {
		fputcsv( $out, array( 'date_utc', 'utilisateur', 'action', 'niveau', 'contenu_id', 'contenu', 'version_id', 'message' ), ';' );
		$page = 1;
		do {
			$result = $this->query( array(), $page, 500 );
			foreach ( $result['items'] as $item ) {
				$row = array( $item['date'], $item['user'], $item['action'], $item['level'], $item['object_id'], $item['object'], $item['version_id'], $item['message'] );
				// Neutralise l'injection de formules dans les tableurs.
				$row = array_map( static fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v, $row );
				fputcsv( $out, $row, ';' );
			}
			++$page;
			$fetched = count( $result['items'] );
		} while ( 500 === $fetched );
	}
}

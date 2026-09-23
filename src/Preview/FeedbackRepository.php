<?php
/**
 * Retours client : validation ou demande de modifications (F3).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Preview;

use Lumia\Staging\Install\Schema;

defined( 'ABSPATH' ) || exit;

class FeedbackRepository {

	public const APPROVE = 'approve';
	public const CHANGES = 'changes';

	public function __construct( private \wpdb $db ) {}

	public function add( int $version_id, int $token_id, string $name, string $decision, string $comment ): int {
		$this->db->insert(
			Schema::table( 'lmv_feedback' ),
			array(
				'version_id' => $version_id,
				'token_id'   => $token_id,
				'name'       => $name,
				'decision'   => $decision,
				'comment'    => $comment,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * @return list<array{id: int, name: string, decision: string, comment: string, date: string}>
	 */
	public function for_version( int $version_id ): array {
		$table = Schema::table( 'lmv_feedback' );
		$rows  = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE version_id = %d ORDER BY id DESC", $version_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static fn( $r ) => array(
				'id'       => (int) $r['id'],
				'name'     => (string) $r['name'],
				'decision' => (string) $r['decision'],
				'comment'  => (string) $r['comment'],
				'date'     => mysql_to_rfc3339( (string) $r['created_at'] ),
			),
			(array) $rows
		);
	}

	/**
	 * @return array{id: int, name: string, decision: string, comment: string, date: string}|null
	 */
	public function latest( int $version_id ): ?array {
		return $this->for_version( $version_id )[0] ?? null;
	}

	/**
	 * Résumé conservé dans l'historique : « Validée par X le … ».
	 */
	public function validation_summary( int $version_id ): string {
		$latest = $this->latest( $version_id );
		if ( null === $latest || self::APPROVE !== $latest['decision'] ) {
			return '';
		}
		return (string) wp_json_encode(
			array(
				'name' => $latest['name'],
				'date' => $latest['date'],
			)
		);
	}

	public function purge( int $months ): int {
		$table = Schema::table( 'lmv_feedback' );
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $months * 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

<?php
/**
 * Jetons d'aperçu client (§8).
 *
 * Jeton de 256 bits encodé base64url, stocké uniquement sous forme
 * d'empreinte HMAC-SHA256 (sels WordPress), comparé avec hash_equals().
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Preview;

use Lumia\Staging\Install\Schema;
use Lumia\Staging\Post\PostStatus;

defined( 'ABSPATH' ) || exit;

class TokenRepository {

	public function __construct( private \wpdb $db ) {}

	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	public static function well_formed( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token );
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{id: int, token: string, expires_at: string}
	 */
	public function create( int $version_id, int $days, array $context = array() ): array {
		$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, min( 30, $days ) ) * DAY_IN_SECONDS );
		$this->db->insert(
			Schema::table( 'lmv_tokens' ),
			array(
				'version_id' => $version_id,
				'token_hash' => self::hash( $token ),
				'context'    => (string) wp_json_encode( $context ),
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
				'expires_at' => $expires,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return array(
			'id'         => (int) $this->db->insert_id,
			'token'      => $token,
			'expires_at' => $expires,
		);
	}

	/**
	 * Jeton valide : existe, non révoqué, non expiré, version encore ouverte.
	 *
	 * @return array{id: int, version_id: int, expires_at: string, context: array<string, mixed>}|null
	 */
	public function find_valid( string $token ): ?array {
		if ( ! self::well_formed( $token ) ) {
			return null;
		}
		$hash  = self::hash( $token );
		$table = Schema::table( 'lmv_tokens' );
		$row   = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return null;
		}
		if ( null !== $row['revoked_at'] || strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
			return null;
		}
		$version_id = (int) $row['version_id'];
		if ( PostStatus::STATUS !== get_post_status( $version_id ) ) {
			return null;
		}
		$context = json_decode( (string) $row['context'], true );
		return array(
			'id'         => (int) $row['id'],
			'version_id' => $version_id,
			'expires_at' => (string) $row['expires_at'],
			'context'    => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * @return list<array{id: int, created_at: string, expires_at: string, revoked: bool, expired: bool, created_by: string}>
	 */
	public function for_version( int $version_id ): array {
		$table = Schema::table( 'lmv_tokens' );
		$rows  = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE version_id = %d ORDER BY id DESC", $version_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$user  = get_userdata( (int) $row['created_by'] );
			$out[] = array(
				'id'         => (int) $row['id'],
				'created_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
				'expires_at' => mysql_to_rfc3339( (string) $row['expires_at'] ),
				'revoked'    => null !== $row['revoked_at'],
				'expired'    => strtotime( $row['expires_at'] . ' UTC' ) <= time(),
				'created_by' => $user ? $user->display_name : '',
			);
		}
		return $out;
	}

	public function revoke( int $token_id, int $version_id ): bool {
		return (bool) $this->db->update(
			Schema::table( 'lmv_tokens' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'id'         => $token_id,
				'version_id' => $version_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	public function revoke_all( int $version_id ): void {
		$table = Schema::table( 'lmv_tokens' );
		$this->db->query( $this->db->prepare( "UPDATE {$table} SET revoked_at = %s WHERE version_id = %d AND revoked_at IS NULL", current_time( 'mysql', true ), $version_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function delete_for_version( int $version_id ): void {
		$this->db->delete( Schema::table( 'lmv_tokens' ), array( 'version_id' => $version_id ), array( '%d' ) );
	}

	public function purge_expired( int $days = 30 ): int {
		$table = Schema::table( 'lmv_tokens' );
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

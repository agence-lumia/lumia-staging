<?php
/**
 * Sauvegardes avant publication (F8), rétention et restauration.
 *
 * Statuts d'une sauvegarde :
 * - pending     : publication en cours (sert à la reprise après incident) ;
 * - committed   : publication terminée, visible dans l'historique ;
 * - rolled_back : publication échouée et annulée ;
 * - recovered   : publication interrompue, restaurée par la reprise.
 *
 * Les métas sont stockées brutes (valeurs telles qu'en base), compressées
 * puis encodées en base64 : restauration à l'octet près, sans désérialiser.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Service;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Install\Schema;

defined( 'ABSPATH' ) || exit;

class SnapshotRepository {

	public function __construct(
		private \wpdb $db,
		private BricksAdapter $bricks
	) {}

	/**
	 * État courant d'un contenu.
	 *
	 * @return array<string, mixed>
	 */
	public function capture( int $post_id ): array {
		$post  = get_post( $post_id );
		$terms = array();
		foreach ( get_object_taxonomies( (string) get_post_type( $post_id ) ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! $ids instanceof \WP_Error ) {
				$terms[ $taxonomy ] = array_map( 'intval', $ids );
			}
		}
		return array(
			'meta'          => $this->bricks->raw_meta( $post_id ),
			'post'          => array(
				'post_title'   => $post instanceof \WP_Post ? $post->post_title : '',
				'post_content' => $post instanceof \WP_Post ? $post->post_content : '',
				'post_excerpt' => $post instanceof \WP_Post ? $post->post_excerpt : '',
			),
			'thumbnail'     => (int) get_post_thumbnail_id( $post_id ),
			'page_template' => (string) get_post_meta( $post_id, '_wp_page_template', true ),
			'terms'         => $terms,
		);
	}

	/**
	 * @param array{kind?: string, version_id?: int, batch_id?: int, note?: string, validation?: string, status?: string} $info
	 */
	public function create( int $post_id, array $info = array() ): int {
		$data = $this->capture( $post_id );
		$ok   = $this->db->insert(
			Schema::table( 'lmv_snapshots' ),
			array(
				'post_id'        => $post_id,
				'version_id'     => (int) ( $info['version_id'] ?? 0 ),
				'batch_id'       => (int) ( $info['batch_id'] ?? 0 ),
				'kind'           => (string) ( $info['kind'] ?? 'publish' ),
				'status'         => (string) ( $info['status'] ?? 'pending' ),
				'author_id'      => get_current_user_id(),
				'note'           => (string) ( $info['note'] ?? '' ),
				'validation'     => (string) ( $info['validation'] ?? '' ),
				'data'           => $this->encode( $data ),
				'hash'           => $this->bricks->hash_post( $post_id ),
				'bricks_version' => $this->bricks->version(),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Impossible d\'écrire la sauvegarde : ' . esc_html( $this->db->last_error ) );
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Réécrit un contenu à l'identique d'une sauvegarde.
	 *
	 * @param array<string, mixed> $data
	 */
	public function apply( int $post_id, array $data, bool $with_fields = true ): void {
		$meta = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();

		foreach ( array_keys( $this->bricks->raw_meta( $post_id ) ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				$this->db->delete(
					$this->db->postmeta,
					array(
						'post_id'  => $post_id,
						'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					),
					array( '%d', '%s' )
				); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}
		foreach ( $meta as $key => $raw ) {
			$this->db->delete(
				$this->db->postmeta,
				array(
					'post_id'  => $post_id,
					'meta_key' => (string) $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				),
				array( '%d', '%s' )
			); // phpcs:ignore WordPress.DB.SlowDBQuery
			$this->db->insert(
				$this->db->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => (string) $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => (string) $raw, // phpcs:ignore WordPress.DB.SlowDBQuery
				),
				array( '%d', '%s', '%s' )
			);
		}
		wp_cache_delete( $post_id, 'post_meta' );

		if ( $with_fields && is_array( $data['post'] ?? null ) ) {
			$fields = array_intersect_key( $data['post'], array_flip( array( 'post_title', 'post_content', 'post_excerpt' ) ) );
			wp_update_post( wp_slash( array_merge( array( 'ID' => $post_id ), $fields ) ) );
			$thumbnail = (int) ( $data['thumbnail'] ?? 0 );
			if ( $thumbnail > 0 ) {
				set_post_thumbnail( $post_id, $thumbnail );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		$template = (string) ( $data['page_template'] ?? '' );
		if ( '' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		} else {
			delete_post_meta( $post_id, '_wp_page_template' );
		}
		if ( is_array( $data['terms'] ?? null ) ) {
			foreach ( $data['terms'] as $taxonomy => $ids ) {
				if ( taxonomy_exists( (string) $taxonomy ) ) {
					wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
				}
			}
		}
		clean_post_cache( $post_id );
	}

	/**
	 * @return array<string, mixed>|null Ligne avec `data` décodé.
	 */
	public function get( int $id ): ?array {
		$table = Schema::table( 'lmv_snapshots' );
		$row   = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['data'] = $this->decode( (string) $row['data'] );
		return $row;
	}

	/**
	 * @param list<int> $ids
	 */
	public function mark( array $ids, string $status ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return;
		}
		$table        = Schema::table( 'lmv_snapshots' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$this->db->query( $this->db->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})", array_merge( array( $status ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Publications inachevées (reprise après incident), sans les données.
	 *
	 * @return list<array{id: int, post_id: int, batch_id: int, version_id: int}>
	 */
	public function pending(): array {
		$table = Schema::table( 'lmv_snapshots' );
		$rows  = $this->db->get_results( "SELECT id, post_id, batch_id, version_id FROM {$table} WHERE status = 'pending' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static fn( $r ) => array(
				'id'         => (int) $r['id'],
				'post_id'    => (int) $r['post_id'],
				'batch_id'   => (int) $r['batch_id'],
				'version_id' => (int) $r['version_id'],
			),
			(array) $rows
		);
	}

	/**
	 * Historique d'un contenu (sauvegardes terminées), plus récentes d'abord.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function history( int $post_id ): array {
		$table = Schema::table( 'lmv_snapshots' );
		$rows  = $this->db->get_results(
			$this->db->prepare( "SELECT id, post_id, version_id, batch_id, kind, author_id, note, validation, hash, bricks_version, created_at FROM {$table} WHERE post_id = %d AND status = 'committed' ORDER BY id DESC", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		/** @var list<array<string, mixed>> */
		return (array) $rows;
	}

	/**
	 * Rétention : au-delà de $count sauvegardes OU de $days jours, on supprime,
	 * sauf la plus récente de chaque contenu (le retour à la version
	 * précédente reste toujours possible).
	 */
	public function cleanup( int $count, int $days ): int {
		$table   = Schema::table( 'lmv_snapshots' );
		$limit   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		$posts   = $this->db->get_col( "SELECT DISTINCT post_id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $posts as $post_id ) {
			$ids  = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND status = 'committed' ORDER BY id DESC", (int) $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$old  = $this->db->get_col( $this->db->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND status = 'committed' AND created_at < %s", (int) $post_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$drop = array_unique( array_merge( array_slice( $ids, $count ), array_map( 'intval', $old ) ) );
			$drop = array_diff( $drop, array_slice( $ids, 0, 1 ) );
			foreach ( $drop as $id ) {
				$deleted += (int) $this->db->delete( $table, array( 'id' => $id ), array( '%d' ) );
			}
		}
		// Sauvegardes annulées : inutiles au-delà d'une semaine.
		$deleted += (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE status IN ('rolled_back','recovered') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $deleted;
	}

	public function delete_all(): void {
		$this->db->query( 'TRUNCATE TABLE ' . Schema::table( 'lmv_snapshots' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function encode( array $data ): string {
		$json = (string) wp_json_encode( $data );
		if ( function_exists( 'gzcompress' ) ) {
			return 'gz:' . base64_encode( (string) gzcompress( $json, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return 'js:' . $json;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode( string $stored ): array {
		$json = str_starts_with( $stored, 'gz:' )
			? (string) gzuncompress( (string) base64_decode( substr( $stored, 3 ), true ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			: substr( $stored, 3 );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}

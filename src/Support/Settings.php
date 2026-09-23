<?php
/**
 * Réglages du plugin : une seule option légère, chargée automatiquement.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Support;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'lmv_settings';

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'post_types'          => array( 'page', 'bricks_template' ),
			'preview_days'        => 7,
			'retention_count'     => 20,
			'retention_days'      => 90,
			'log_months'          => 12,
			'publish_post_fields' => true,
			'builder_button'      => true,
			'notify_emails'       => true,
			'delete_on_uninstall' => false,
			'update_channel'      => 'stable',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
			// Ancien nom du canal des pré-versions (0.1.0).
			if ( 'beta' === $this->cache['update_channel'] ) {
				$this->cache['update_channel'] = 'dev';
			}
		}
		return $this->cache;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	public function int( string $key ): int {
		$value = $this->get( $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/**
	 * Types de contenu versionnables. Filtre `lmv_post_types`.
	 *
	 * @return list<string>
	 */
	public function post_types(): array {
		$types = $this->get( 'post_types' );
		$types = apply_filters( 'lmv_post_types', is_array( $types ) ? $types : array() );
		return array_values( array_filter( array_map( 'strval', (array) $types ), 'post_type_exists' ) );
	}

	/**
	 * Enregistre des réglages après nettoyage.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$current = $this->all();
		$clean   = $current;

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$clean['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $input['post_types'] ), array( 'page', 'bricks_template' ) ) );
		}
		$ranges = array(
			'preview_days'    => array( 1, 30 ),
			'retention_count' => array( 1, 200 ),
			'retention_days'  => array( 30, 3650 ),
			'log_months'      => array( 1, 60 ),
		);
		foreach ( $ranges as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}
		foreach ( array( 'publish_post_fields', 'notify_emails', 'delete_on_uninstall', 'builder_button' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset( $input['update_channel'] ) && in_array( $input['update_channel'], array( 'stable', 'dev' ), true ) ) {
			$clean['update_channel'] = $input['update_channel'];
		}

		update_option( self::OPTION, $clean, true );
		$this->cache = null;
		return $this->all();
	}
}

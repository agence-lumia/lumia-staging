<?php
/**
 * Résumé structurel (F4) : éléments Bricks ajoutés, supprimés, modifiés.
 *
 * Classe pure pour les tests unitaires.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Diff;

final class StructureDiff {

	/**
	 * @param mixed $before Liste d'éléments Bricks (id, name, parent, children, settings).
	 * @param mixed $after
	 * @return array{added: int, removed: int, modified: int}
	 */
	public static function elements( mixed $before, mixed $after ): array {
		$a = self::index( $before );
		$b = self::index( $after );

		$added    = count( array_diff_key( $b, $a ) );
		$removed  = count( array_diff_key( $a, $b ) );
		$modified = 0;
		foreach ( array_intersect_key( $a, $b ) as $id => $element ) {
			if ( self::fingerprint( $element ) !== self::fingerprint( $b[ $id ] ) ) {
				++$modified;
			}
		}
		return array(
			'added'    => $added,
			'removed'  => $removed,
			'modified' => $modified,
		);
	}

	/**
	 * Compare deux valeurs quelconques (réglages de page, CSS…).
	 */
	public static function differs( mixed $before, mixed $after ): bool {
		return self::normalize( $before ) !== self::normalize( $after );
	}

	/**
	 * @return array<string, array<mixed>>
	 */
	private static function index( mixed $elements ): array {
		$out = array();
		if ( ! is_array( $elements ) ) {
			return $out;
		}
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$out[ (string) $element['id'] ] = $element;
			}
		}
		return $out;
	}

	/**
	 * @param array<mixed> $element
	 */
	private static function fingerprint( array $element ): string {
		return self::normalize(
			array(
				$element['name'] ?? '',
				$element['parent'] ?? '',
				$element['children'] ?? array(),
				$element['settings'] ?? array(),
				$element['label'] ?? '',
			)
		);
	}

	private static function normalize( mixed $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		if ( is_array( $value ) ) {
			$value = self::sort_recursive( $value );
		}
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- classe pure, sans WordPress.
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function sort_recursive( array $value ): array {
		$is_list = array_is_list( $value );
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::sort_recursive( $v );
			}
		}
		if ( ! $is_list ) {
			ksort( $value );
		}
		return $value;
	}
}

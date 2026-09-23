<?php
/**
 * Copie de contenu : original → version (F1) et version → original (F5).
 *
 * Règle de base (§6) : on copie le contenu Bricks de la version DANS
 * l'original, on ne remplace jamais l'original. ID, slug, menus, SEO et
 * réglages WooCommerce restent intacts.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Service;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Diff\ReferenceReplacer;

defined( 'ABSPATH' ) || exit;

class ContentCopier {

	public function __construct(
		private BricksAdapter $bricks,
		private ReferenceReplacer $replacer
	) {}

	/**
	 * Clés copiées : liste blanche puis liste noire, motifs avec `*` final.
	 * Filtres `lmv_meta_whitelist` et `lmv_meta_blacklist`.
	 */
	public function key_allowed( string $key ): bool {
		$white = (array) apply_filters( 'lmv_meta_whitelist', array( BricksAdapter::META_PREFIX . '*' ) );
		$black = (array) apply_filters( 'lmv_meta_blacklist', array( '_bricks_lock*' ) );
		return $this->matches( $key, $white ) && ! $this->matches( $key, $black );
	}

	/**
	 * F1 : copie le contenu de l'original vers une version neuve.
	 */
	public function copy_to_version( int $source_id, int $version_id ): void {
		$is_template  = $this->bricks->is_template( $source_id );
		$settings_key = $this->bricks->template_settings_key();

		foreach ( $this->bricks->meta( $source_id ) as $key => $value ) {
			if ( ! $this->key_allowed( $key ) ) {
				continue;
			}
			if ( $is_template && $key === $settings_key ) {
				$value = $this->bricks->neutralize_template_settings( $value ); // R5.
			}
			add_post_meta( $version_id, $key, wp_slash( $value ), true );
		}

		$thumbnail = (int) get_post_thumbnail_id( $source_id );
		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $version_id, $thumbnail );
		}
		$template = (string) get_post_meta( $source_id, '_wp_page_template', true );
		if ( '' !== $template ) {
			update_post_meta( $version_id, '_wp_page_template', $template );
		}
		$this->copy_terms( $source_id, $version_id );
	}

	/**
	 * F5 : écrit le contenu de la version dans l'original.
	 */
	public function publish_into( int $version_id, int $original_id, bool $with_fields ): void {
		$is_template  = $this->bricks->is_template( $original_id );
		$type_key     = $this->bricks->template_type_key();
		$settings_key = $this->bricks->template_settings_key();
		$protected    = $is_template ? array( $type_key, $settings_key ) : array();
		$url_pairs    = $this->url_pairs( $version_id, $original_id );

		$version_meta  = $this->bricks->meta( $version_id );
		$original_keys = array_keys( $this->bricks->raw_meta( $original_id ) );

		foreach ( $version_meta as $key => $value ) {
			if ( ! $this->key_allowed( $key ) || ( $is_template && $key === $type_key ) ) {
				continue; // R5 : le type de l'original est conservé.
			}
			if ( $is_template && $key === $settings_key ) {
				$value = $this->bricks->merge_template_settings( $value, get_post_meta( $original_id, $settings_key, true ) );
			}
			$value = $this->replacer->replace( $value, $version_id, $original_id, $url_pairs );
			update_post_meta( $original_id, $key, wp_slash( $value ) );
		}

		// R1 : une clé présente sur l'original mais absente de la version est supprimée.
		foreach ( $original_keys as $key ) {
			if ( ! array_key_exists( $key, $version_meta ) && $this->key_allowed( $key ) && ! in_array( $key, $protected, true ) ) {
				delete_post_meta( $original_id, $key );
			}
		}

		if ( $with_fields ) {
			$version = get_post( $version_id );
			if ( $version instanceof \WP_Post ) {
				$result = wp_update_post(
					wp_slash(
						array(
							'ID'           => $original_id,
							'post_title'   => $version->post_title,
							'post_content' => (string) $this->replacer->replace( $version->post_content, $version_id, $original_id, $url_pairs ),
							'post_excerpt' => $version->post_excerpt,
						)
					),
					true
				);
				if ( $result instanceof \WP_Error ) {
					throw new \RuntimeException( esc_html( $result->get_error_message() ) );
				}
			}
			$thumbnail = (int) get_post_thumbnail_id( $version_id );
			if ( $thumbnail > 0 ) {
				set_post_thumbnail( $original_id, $thumbnail );
			} else {
				delete_post_thumbnail( $original_id );
			}
		}

		$template = (string) get_post_meta( $version_id, '_wp_page_template', true );
		if ( '' !== $template ) {
			update_post_meta( $original_id, '_wp_page_template', $template );
		} else {
			delete_post_meta( $original_id, '_wp_page_template' );
		}
		$this->copy_terms( $version_id, $original_id );
	}

	/**
	 * Champs de contenu modifiés entre l'original et la version.
	 *
	 * @return list<string> Parmi title, excerpt, thumbnail.
	 */
	public function changed_fields( int $version_id, int $original_id ): array {
		$changed = array();
		if ( get_post_field( 'post_title', $version_id ) !== get_post_field( 'post_title', $original_id ) ) {
			$changed[] = 'title';
		}
		if ( get_post_field( 'post_excerpt', $version_id ) !== get_post_field( 'post_excerpt', $original_id ) ) {
			$changed[] = 'excerpt';
		}
		if ( (int) get_post_thumbnail_id( $version_id ) !== (int) get_post_thumbnail_id( $original_id ) ) {
			$changed[] = 'thumbnail';
		}
		return $changed;
	}

	private function copy_terms( int $from, int $to ): void {
		$type = (string) get_post_type( $from );
		foreach ( get_object_taxonomies( $type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! $terms instanceof \WP_Error ) {
				wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy );
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function url_pairs( int $version_id, int $original_id ): array {
		$from = (string) get_permalink( $version_id );
		$to   = (string) get_permalink( $original_id );
		return '' !== $from && '' !== $to ? array( $from => $to ) : array();
	}

	/**
	 * @param array<mixed> $patterns
	 */
	private function matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( str_ends_with( $pattern, '*' ) ? str_starts_with( $key, substr( $pattern, 0, -1 ) ) : $key === $pattern ) {
				return true;
			}
		}
		return false;
	}
}

<?php
/**
 * Caches (R4) : cache objet (Redis), Cache Enabler, hook générique.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Adapter;

defined( 'ABSPATH' ) || exit;

class CacheAdapter {

	public function __construct( private BricksAdapter $bricks ) {}

	/**
	 * Purge après publication ou restauration d'un contenu.
	 *
	 * @param bool $site_wide Vrai pour un template : il peut s'afficher sur tout le site.
	 */
	public function purge( int $post_id, bool $site_wide ): void {
		clean_post_cache( $post_id );
		$this->bricks->flush_option_cache();

		if ( $site_wide ) {
			do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} else {
			do_action( 'cache_enabler_clear_page_cache_by_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			if ( (int) get_option( 'page_on_front' ) === $post_id ) {
				do_action( 'cache_enabler_clear_page_cache_by_url', home_url( '/' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}

		/**
		 * Brancher un autre système de cache.
		 *
		 * @param int  $post_id Contenu publié.
		 * @param bool $site_wide  Purge de tout le site demandée.
		 */
		do_action( 'lmv_purge_cache', $post_id, $site_wide );
	}

	/**
	 * Désactive tout cache de page pour la réponse en cours (aperçus).
	 */
	public function bypass_current_request(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante standard des plugins de cache.
		}
		add_filter( 'cache_enabler_bypass_cache', '__return_true' );
		add_filter( 'cache_enabler_page_contents_before_store', '__return_empty_string' );
	}
}

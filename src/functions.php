<?php
/**
 * Fonctions publiques, utilisables dans les snippets maison (FluentSnippets…).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lmv_get_source_id' ) ) {
	/**
	 * Renvoie l'ID du contenu original si $post_id est une version de travail,
	 * sinon $post_id lui-même. Sans argument, utilise le contenu courant.
	 *
	 * Exemple dans un snippet : `if ( 42 === lmv_get_source_id() ) { … }`
	 * fonctionne à la fois sur la page 42 et sur l'aperçu de sa version.
	 */
	function lmv_get_source_id( int $post_id = 0 ): int {
		if ( 0 === $post_id ) {
			$post_id = (int) get_queried_object_id();
			if ( 0 === $post_id ) {
				$post_id = (int) get_the_ID();
			}
		}
		if ( $post_id <= 0 ) {
			return 0;
		}
		if ( \Lumia\Staging\Post\PostStatus::STATUS !== get_post_status( $post_id ) ) {
			return $post_id;
		}
		$source = (int) get_post_meta( $post_id, \Lumia\Staging\Domain\Meta::SOURCE_ID, true );
		return $source > 0 ? $source : $post_id;
	}
}

if ( ! function_exists( 'lmv_is_version' ) ) {
	/**
	 * Indique si le contenu est une version de travail Lümia Staging.
	 */
	function lmv_is_version( int $post_id ): bool {
		return \Lumia\Staging\Post\PostStatus::STATUS === get_post_status( $post_id );
	}
}

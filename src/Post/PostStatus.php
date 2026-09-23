<?php
/**
 * Statut `lmv-version` : jamais public.
 *
 * Exclu du front, de la recherche, des sitemaps, des flux, de l'API REST
 * publique et des listes admin standard. Enregistré même si Bricks est
 * absent, pour que les versions restent invisibles en toutes circonstances.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Post;

use Lumia\Staging\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class PostStatus {

	public const STATUS = 'lmv-version';

	/** @var array<int, true> Versions autorisées pour la requête en cours (aperçu par jeton). */
	private array $allowed = array();

	private int $internal = 0;

	public function register(): void {
		add_action( 'init', array( $this, 'register_status' ), 0 );
		add_filter( 'posts_results', array( $this, 'filter_results' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_status' ), 99, 2 );
		add_filter( 'wp_untrash_post_status', array( $this, 'untrash_status' ), 10, 3 );
		add_filter( 'page_link', array( $this, 'page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'post_link' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'post_link' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_args' ) );
	}

	public function register_status(): void {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Version de travail', 'post status', 'lumia-staging' ),
				'public'                    => false,
				'protected'                 => true,
				'private'                   => false,
				'internal'                  => false,
				'publicly_queryable'        => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * Autorise l'affichage d'une version pendant la requête (aperçu client).
	 */
	public function allow( int $post_id ): void {
		$this->allowed[ $post_id ] = true;
	}

	public function is_allowed( int $post_id ): bool {
		return isset( $this->allowed[ $post_id ] );
	}

	/**
	 * Exécute une opération interne qui peut changer le statut d'une version.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public function internal( callable $operation ): mixed {
		++$this->internal;
		try {
			return $operation();
		} finally {
			--$this->internal;
		}
	}

	/**
	 * Retire les versions de tout résultat de requête pour qui n'a pas le droit
	 * de les voir (y compris REST, recherche, flux, `?p=ID`).
	 *
	 * @param array<int, \WP_Post|int> $posts
	 * @return array<int, \WP_Post|int>
	 */
	public function filter_results( array $posts, \WP_Query $query ): array {
		unset( $query );
		$filtered = false;
		foreach ( $posts as $i => $post ) {
			if ( ! $post instanceof \WP_Post || self::STATUS !== $post->post_status ) {
				continue;
			}
			if ( $this->is_allowed( $post->ID ) || current_user_can( Capabilities::CREATE ) ) {
				continue;
			}
			unset( $posts[ $i ] );
			$filtered = true;
		}
		return $filtered ? array_values( $posts ) : $posts;
	}

	/**
	 * Un utilisateur sans capacité `lmv_create_version` ne peut ni lire, ni
	 * modifier, ni supprimer une version, même s'il peut modifier les pages
	 * (rôle Éditeur du client) : couvre REST, builder Bricks et admin.
	 *
	 * @param list<string> $caps
	 * @param array<mixed> $args
	 * @return list<string>
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_post', 'read_post', 'delete_post', 'publish_post', 'edit_page', 'read_page', 'delete_page' ), true ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 || self::STATUS !== get_post_status( $post_id ) ) {
			return $caps;
		}
		if ( 'read_post' === $cap && $this->is_allowed( $post_id ) ) {
			return array( 'exist' );
		}
		if ( ! user_can( $user_id, Capabilities::CREATE ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Empêche une version de changer de statut hors du plugin (bouton
	 * « Publier » de Bricks ou de l'éditeur WordPress) : elle deviendrait
	 * un contenu public en doublon.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public function guard_status( array $data, array $postarr ): array {
		if ( $this->internal > 0 || empty( $postarr['ID'] ) ) {
			return $data;
		}
		if ( self::STATUS === get_post_status( (int) $postarr['ID'] ) && ! in_array( $data['post_status'] ?? '', array( self::STATUS, 'trash' ), true ) ) {
			$data['post_status'] = self::STATUS;
			$data['post_name']   = get_post_field( 'post_name', (int) $postarr['ID'] );
		}
		return $data;
	}

	public function untrash_status( string $new_status, int $post_id, string $previous_status ): string {
		unset( $post_id );
		return self::STATUS === $previous_status ? self::STATUS : $new_status;
	}

	public function page_link( string $link, int $post_id ): string {
		if ( self::STATUS === get_post_status( $post_id ) ) {
			return add_query_arg( 'page_id', $post_id, home_url( '/' ) );
		}
		return $link;
	}

	public function post_link( string $link, \WP_Post $post ): string {
		if ( self::STATUS === $post->post_status ) {
			return add_query_arg(
				array(
					'p'         => $post->ID,
					'post_type' => $post->post_type,
				),
				home_url( '/' )
			);
		}
		return $link;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function sitemap_args( array $args ): array {
		$args['post_status'] = 'publish';
		return $args;
	}
}

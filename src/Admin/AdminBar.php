<?php
/**
 * Barre d'admin côté site : « Créer une version » / « Reprendre la version ».
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Admin;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Rest\RestController;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminBar {

	public function __construct(
		private VersionService $versions,
		private BricksAdapter $bricks
	) {}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'nodes' ), 81 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	private function current_id(): int {
		if ( is_admin() || ! is_singular() ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}

	public function enqueue(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( Capabilities::CREATE ) || 0 === $this->current_id() || $this->bricks->is_builder() ) {
			return;
		}
		wp_enqueue_script( 'lmv-actions', LMV_URL . 'assets/admin/actions.js', array( 'wp-api-fetch' ), LMV_VERSION, true );
		wp_add_inline_script(
			'lmv-actions',
			'window.lmvActions=' . wp_json_encode(
				array(
					'namespace' => RestController::NS,
					'creating'  => __( 'Création de la version…', 'lumia-staging' ),
					'error'     => __( 'Impossible de créer la version.', 'lumia-staging' ),
				)
			) . ';',
			'before'
		);
	}

	public function nodes( \WP_Admin_Bar $bar ): void {
		$post_id = $this->current_id();
		if ( 0 === $post_id || ! current_user_can( Capabilities::CREATE ) || ! current_user_can( 'edit_post', $post_id ) || $this->bricks->is_builder() ) {
			return;
		}

		if ( $this->versions->is_version( $post_id ) ) {
			$source = $this->versions->source_id( $post_id );
			$bar->add_node(
				array(
					'id'    => 'lmv',
					/* translators: %s: title */
					'title' => '<span class="ab-icon dashicons dashicons-backup" style="top:2px"></span>' . esc_html( sprintf( __( 'Version de travail de « %s »', 'lumia-staging' ), get_the_title( $source ) ) ),
					'href'  => $this->bricks->builder_url( $post_id ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'lmv',
					'id'     => 'lmv-edit',
					'title'  => esc_html__( 'Modifier dans Bricks', 'lumia-staging' ),
					'href'   => $this->bricks->builder_url( $post_id ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'lmv',
					'id'     => 'lmv-live',
					'title'  => esc_html__( 'Voir la version en ligne', 'lumia-staging' ),
					'href'   => (string) get_permalink( $source ),
				)
			);
			return;
		}

		if ( ! $this->versions->supports( $post_id ) ) {
			return;
		}
		$open = $this->versions->open_version_for( $post_id );
		$bar->add_node(
			array(
				'id'    => 'lmv',
				'title' => '<span class="ab-icon dashicons dashicons-backup" style="top:2px"></span>' . ( $open > 0 ? esc_html__( 'Reprendre la version', 'lumia-staging' ) : esc_html__( 'Créer une version', 'lumia-staging' ) ),
				'href'  => $open > 0 ? $this->bricks->builder_url( $open ) : '#',
				'meta'  => $open > 0 ? array() : array(
					'class' => 'lmv-create-node',
					'html'  => '',
				),
			)
		);
		if ( 0 === $open ) {
			// Attribut lu par actions.js : crée la version par un POST, jamais par un GET.
			$bar->add_node(
				array(
					'parent' => 'lmv',
					'id'     => 'lmv-create',
					'title'  => '<span class="lmv-create" data-post="' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Créer une version de travail', 'lumia-staging' ) . '</span>',
					'href'   => '#',
				)
			);
		}
		if ( current_user_can( Capabilities::RESTORE ) ) {
			$bar->add_node(
				array(
					'parent' => 'lmv',
					'id'     => 'lmv-history',
					'title'  => esc_html__( 'Historique', 'lumia-staging' ),
					'href'   => admin_url( 'admin.php?page=lumia-staging-history&post=' . $post_id ),
				)
			);
		}
	}
}

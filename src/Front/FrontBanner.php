<?php
/**
 * Bandeau sur le front quand un admin consulte une version de travail.
 * Rien n'est chargé pour les visiteurs, ni sur l'original.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Front;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Rest\Presenter;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class FrontBanner {

	public function __construct(
		private VersionService $versions,
		private BricksAdapter $bricks,
		private Presenter $presenter
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue(): void {
		if ( ! is_user_logged_in() || ! is_singular() || $this->bricks->is_builder() || ! Capabilities::has_any() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $this->versions->is_version( $post_id ) ) {
			return;
		}
		UiConfig::enqueue( 'front', $post_id, $this->versions, $this->bricks, $this->presenter );
	}
}

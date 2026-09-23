<?php
/**
 * Intégration dans l'éditeur Bricks : script JS sans framework, chargé
 * uniquement dans la fenêtre principale du builder (pas dans l'iframe) pour
 * ne pas entrer en conflit avec l'application Vue de Bricks.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Builder;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Front\UiConfig;
use Lumia\Staging\Rest\Presenter;
use Lumia\Staging\Service\VersionService;

defined( 'ABSPATH' ) || exit;

final class BuilderIntegration {

	public function __construct(
		private VersionService $versions,
		private BricksAdapter $bricks,
		private Presenter $presenter
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue(): void {
		if ( ! $this->bricks->is_builder_main() ) {
			return;
		}
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			$post_id = (int) get_queried_object_id();
		}
		UiConfig::enqueue( 'builder', $post_id, $this->versions, $this->bricks, $this->presenter );
	}
}

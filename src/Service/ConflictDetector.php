<?php
/**
 * Empreintes de l'original (R10) et des éléments globaux Bricks (R8).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Service;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Domain\Meta;

defined( 'ABSPATH' ) || exit;

class ConflictDetector {

	public function __construct( private BricksAdapter $bricks ) {}

	/**
	 * Enregistre les empreintes au moment de la copie.
	 */
	public function stamp( int $version_id, int $source_id ): void {
		update_post_meta( $version_id, Meta::SOURCE_HASH, $this->bricks->hash_post( $source_id ) );
		update_post_meta( $version_id, Meta::GLOBALS_HASH, $this->bricks->hash_globals() );
		update_post_meta( $version_id, Meta::BRICKS_VERSION, $this->bricks->version() );
	}

	/**
	 * R10 : quelqu'un a modifié l'original depuis la création de la version.
	 */
	public function source_changed( int $version_id, int $source_id ): bool {
		$stored = (string) get_post_meta( $version_id, Meta::SOURCE_HASH, true );
		return '' !== $stored && ! hash_equals( $stored, $this->bricks->hash_post( $source_id ) );
	}

	/**
	 * R8 : une classe, variable, palette, style de thème ou composant a changé.
	 */
	public function globals_changed( int $version_id ): bool {
		$stored = (string) get_post_meta( $version_id, Meta::GLOBALS_HASH, true );
		return '' !== $stored && ! hash_equals( $stored, $this->bricks->hash_globals() );
	}

	/**
	 * Bricks a changé de version majeure depuis la création.
	 */
	public function bricks_major_changed( int $version_id ): bool {
		$stored = (string) get_post_meta( $version_id, Meta::BRICKS_VERSION, true );
		return '' !== $stored && BricksAdapter::major( $stored ) !== BricksAdapter::major( $this->bricks->version() );
	}
}

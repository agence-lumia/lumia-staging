<?php
/**
 * Erreur métier affichable à l'utilisateur : le message est déjà rédigé
 * en français simple (cause, impact, action).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Domain;

defined( 'ABSPATH' ) || exit;

class VersionException extends \RuntimeException {

	/**
	 * @param array<string, mixed> $data Données utiles à l'interface.
	 */
	public function __construct(
		string $message,
		public readonly string $error_code = 'lmv_error',
		public readonly int $status = 400,
		public readonly array $data = array(),
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function to_wp_error(): \WP_Error {
		return new \WP_Error( $this->error_code, $this->getMessage(), array_merge( $this->data, array( 'status' => $this->status ) ) );
	}
}

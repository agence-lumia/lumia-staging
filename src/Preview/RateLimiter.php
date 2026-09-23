<?php
/**
 * Limitation des essais de jetons : 20 invalides par IP en 10 minutes,
 * puis 429 pendant 15 minutes. L'IP n'est jamais stockée en clair.
 *
 * Derrière un proxy (Traefik/Dokploy), REMOTE_ADDR peut être l'IP du proxy :
 * filtre `lmv_client_ip` pour fournir l'IP réelle de façon fiable.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Preview;

defined( 'ABSPATH' ) || exit;

class RateLimiter {

	public const MAX_FAILURES = 20;
	public const WINDOW       = 600;
	public const BLOCK        = 900;

	public function is_blocked(): bool {
		return false !== get_transient( 'lmv_rl_block_' . $this->key() );
	}

	public function fail(): void {
		$key   = $this->key();
		$count = (int) get_transient( 'lmv_rl_' . $key ) + 1;
		set_transient( 'lmv_rl_' . $key, $count, self::WINDOW );
		if ( $count >= self::MAX_FAILURES ) {
			set_transient( 'lmv_rl_block_' . $key, 1, self::BLOCK );
			delete_transient( 'lmv_rl_' . $key );
		}
	}

	private function key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'lmv_client_ip', $ip );
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}
}

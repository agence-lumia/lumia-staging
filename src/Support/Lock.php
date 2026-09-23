<?php
/**
 * Verrous applicatifs via GET_LOCK() MariaDB/MySQL.
 *
 * Un verrou par contenu (« post ») et un par lot (« batch »). Le verrou est
 * libéré automatiquement par le serveur SQL si PHP s'arrête : c'est ce qui
 * permet à la reprise après incident de savoir qu'une publication ne tourne
 * plus. Repli sur une option atomique si GET_LOCK n'existe pas (SQLite).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Support;

defined( 'ABSPATH' ) || exit;

final class Lock {

	/** Durée au-delà de laquelle un verrou de repli est considéré mort. */
	private const FALLBACK_TTL = 900;

	/** @var array<string, true> */
	private array $held = array();

	private ?bool $native = null;

	public function __construct( private \wpdb $db ) {}

	public function acquire( string $scope, int $id, int $timeout = 0 ): bool {
		$name = $this->name( $scope, $id );
		if ( isset( $this->held[ $name ] ) ) {
			return true;
		}
		$ok = $this->native()
			? '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) )
			: $this->acquire_fallback( $name );
		if ( $ok ) {
			$this->held[ $name ] = true;
		}
		return $ok;
	}

	public function release( string $scope, int $id ): void {
		$name = $this->name( $scope, $id );
		if ( ! isset( $this->held[ $name ] ) ) {
			return;
		}
		if ( $this->native() ) {
			$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		} else {
			delete_option( $name );
		}
		unset( $this->held[ $name ] );
	}

	/**
	 * Le verrou est-il libre (personne ne publie ce contenu) ?
	 */
	public function is_free( string $scope, int $id ): bool {
		$name = $this->name( $scope, $id );
		if ( isset( $this->held[ $name ] ) ) {
			return false;
		}
		if ( $this->native() ) {
			return '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
		}
		$since = get_option( $name );
		return false === $since || ( time() - (int) $since ) > self::FALLBACK_TTL;
	}

	public function release_all(): void {
		foreach ( array_keys( $this->held ) as $name ) {
			if ( $this->native() ) {
				$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			} else {
				delete_option( $name );
			}
		}
		$this->held = array();
	}

	/**
	 * Les noms de verrous sont globaux au serveur SQL : on les préfixe par
	 * une empreinte de la base et du préfixe de tables (plusieurs sites
	 * peuvent partager un même MariaDB).
	 */
	private function name( string $scope, int $id ): string {
		$site = substr( md5( ( defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '' ) . '|' . $this->db->prefix ), 0, 10 );
		return substr( 'lmv_' . $site . '_' . sanitize_key( $scope ) . '_' . $id, 0, 64 );
	}

	private function native(): bool {
		if ( null === $this->native ) {
			$suppress     = $this->db->suppress_errors( true );
			$result       = $this->db->get_var( "SELECT IS_FREE_LOCK('lmv_probe')" );
			$this->native = null !== $result && '' === $this->db->last_error;
			$this->db->suppress_errors( $suppress );
		}
		return $this->native;
	}

	private function acquire_fallback( string $name ): bool {
		if ( add_option( $name, (string) time(), '', false ) ) {
			return true;
		}
		$since = get_option( $name );
		if ( false !== $since && ( time() - (int) $since ) > self::FALLBACK_TTL ) {
			delete_option( $name );
			return add_option( $name, (string) time(), '', false );
		}
		return false;
	}
}

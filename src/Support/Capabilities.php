<?php
/**
 * Capacités propres au plugin (cahier des charges §3).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const CREATE   = 'lmv_create_version';
	public const PUBLISH  = 'lmv_publish_version';
	public const RESTORE  = 'lmv_restore_version';
	public const SHARE    = 'lmv_share_preview';
	public const SETTINGS = 'lmv_manage_settings';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::CREATE, self::PUBLISH, self::RESTORE, self::SHARE, self::SETTINGS );
	}

	/**
	 * Capacités par rôle. Filtre `lmv_default_caps` pour les donner à un autre rôle.
	 *
	 * @return array<string, list<string>>
	 */
	public static function defaults(): array {
		$caps = apply_filters( 'lmv_default_caps', array( 'administrator' => self::all() ) );
		return is_array( $caps ) ? $caps : array( 'administrator' => self::all() );
	}

	public static function install(): void {
		foreach ( self::defaults() as $role_name => $caps ) {
			$role = get_role( (string) $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( (array) $caps as $cap ) {
				if ( in_array( $cap, self::all(), true ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * L'utilisateur a-t-il au moins une capacité du plugin ?
	 */
	public static function has_any( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		foreach ( self::all() as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

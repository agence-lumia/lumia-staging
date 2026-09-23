<?php
/**
 * Activation et désactivation.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Install;

use Lumia\Staging\Scheduler\Scheduler;
use Lumia\Staging\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function activate(): void {
		Schema::maybe_upgrade();
		Capabilities::install();
		if ( false === get_option( \Lumia\Staging\Support\Settings::OPTION ) ) {
			add_option( \Lumia\Staging\Support\Settings::OPTION, \Lumia\Staging\Support\Settings::defaults(), '', true );
		}
		if ( ! wp_next_scheduled( Scheduler::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Scheduler::DAILY_HOOK );
		}
	}

	/**
	 * Les versions ouvertes sont conservées : elles restent invisibles
	 * (statut non enregistré = non public) et réapparaissent à la réactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scheduler::DAILY_HOOK );
	}
}

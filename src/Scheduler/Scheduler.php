<?php
/**
 * Programmation (F6, F7) : Action Scheduler s'il est présent (fourni par
 * WooCommerce), sinon WP-Cron. Précision : à 5 minutes près avec le cron
 * système du template Dokploy (DISABLE_WP_CRON, service toutes les 300 s).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Scheduler;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	public const VERSION_HOOK = 'lmv_scheduled_publish';
	public const BATCH_HOOK   = 'lmv_scheduled_batch';
	public const DAILY_HOOK   = 'lmv_daily_cleanup';
	public const GROUP        = 'lumia-staging';

	public function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	public function schedule( string $hook, int $id, int $timestamp ): void {
		$this->cancel( $hook, $id );
		if ( $this->uses_action_scheduler() ) {
			as_schedule_single_action( $timestamp, $hook, array( $id ), self::GROUP );
			return;
		}
		wp_schedule_single_event( $timestamp, $hook, array( $id ) );
	}

	/**
	 * Annule dans les deux systèmes : WooCommerce a pu être activé ou
	 * désactivé entre la programmation et l'annulation.
	 */
	public function cancel( string $hook, int $id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array( $id ), self::GROUP );
		}
		wp_clear_scheduled_hook( $hook, array( $id ) );
	}
}

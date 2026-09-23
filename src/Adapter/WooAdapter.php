<?php
/**
 * WooCommerce (R12, R14) : chargé seulement si WooCommerce est actif.
 *
 * Ne lit ni n'écrit jamais de commande, client, stock ou prix.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Adapter;

defined( 'ABSPATH' ) || exit;

final class WooAdapter {

	/** Pages spéciales WooCommerce, clé utilisée par wc_get_page_id(). */
	private const PAGES = array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' );

	public static function woo_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Déclare la compatibilité HPOS (le plugin ne touche pas aux commandes).
	 */
	public static function declare_compatibility( string $plugin_file ): void {
		add_action(
			'before_woocommerce_init',
			static function () use ( $plugin_file ): void {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
				}
			}
		);
	}

	/**
	 * R12 : pendant l'aperçu d'une version d'une page spéciale, les filtres
	 * `woocommerce_get_{page}_page_id` renvoient l'ID de la version.
	 */
	public function swap_special_pages( int $source_id, int $version_id ): void {
		foreach ( self::PAGES as $page ) {
			add_filter(
				'woocommerce_get_' . $page . '_page_id',
				static fn( $id ) => (int) $id === $source_id ? $version_id : $id,
				999
			);
		}
	}

	/**
	 * R14 : aucun paiement possible en aperçu.
	 *
	 * @param callable(string): string $add_preview_args Ajoute les paramètres d'aperçu à une URL.
	 */
	public function block_payments( callable $add_preview_args ): void {
		add_filter( 'woocommerce_available_payment_gateways', static fn() => array(), 999 );
		add_filter( 'woocommerce_cart_needs_payment', '__return_true', 999 );
		add_filter(
			'woocommerce_order_button_html',
			static fn() => '<p class="lmv-preview-blocked">' . esc_html__( 'Aperçu : la commande est désactivée.', 'lumia-staging' ) . '</p>',
			999
		);
		// Les appels wc-ajax (dont la validation de commande) portent le jeton :
		// ils passent alors par le blocage des soumissions de l'aperçu.
		add_filter( 'woocommerce_ajax_get_endpoint', static fn( $url ) => $add_preview_args( (string) $url ), 999 );
		add_filter( 'woocommerce_get_checkout_url', static fn( $url ) => $add_preview_args( (string) $url ), 999 );
	}
}

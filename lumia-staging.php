<?php
/**
 * Plugin Name:       Lümia Staging
 * Plugin URI:        https://github.com/agence-lumia/lumia-staging
 * Description:       Versions de travail pour les pages et templates Bricks : modifier sans impacter les visiteurs, faire valider par le client, publier sur le même ID et revenir en arrière.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Agence Lümia
 * Author URI:        https://studiokyne.com
 * License:           GPL-2.0-or-later
 * Text Domain:       lumia-staging
 * Domain Path:       /languages
 * Update URI:        https://github.com/agence-lumia/lumia-staging
 *
 * Ce fichier ne contient aucune logique métier : il vérifie les prérequis
 * puis démarre le conteneur de services. Il doit rester lisible par un PHP
 * ancien pour pouvoir afficher le message de prérequis.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'LMV_VERSION', '0.1.0' );
define( 'LMV_FILE', __FILE__ );
define( 'LMV_DIR', plugin_dir_path( __FILE__ ) );
define( 'LMV_URL', plugin_dir_url( __FILE__ ) );
define( 'LMV_MIN_PHP', '8.1' );
define( 'LMV_MIN_WP', '6.8' );

if ( version_compare( PHP_VERSION, LMV_MIN_PHP, '<' ) || version_compare( (string) get_bloginfo( 'version' ), LMV_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: PHP version, 2: WordPress version */
					__( 'Lümia Staging nécessite PHP %1$s et WordPress %2$s minimum. Le plugin est inactif.', 'lumia-staging' ),
					LMV_MIN_PHP,
					LMV_MIN_WP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once LMV_DIR . 'src/autoload.php';
require_once LMV_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( \Lumia\Staging\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Lumia\Staging\Install\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Lumia\Staging\Plugin::class, 'boot' ), 5 );

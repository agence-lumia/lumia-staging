<?php
/**
 * Autoload PSR-4 minimal : le plugin n'a aucune dépendance d'exécution,
 * Composer ne sert qu'aux outils de développement.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Lumia\\Staging\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

<?php
/**
 * Routeur du serveur PHP intégré (tests) : fichiers statiques, sinon WordPress.
 */

$root = '/var/www/html';
$path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( '/' !== $path && is_file( $root . $path ) ) {
	if ( str_ends_with( $path, '.php' ) ) {
		$_SERVER['SCRIPT_NAME']     = $path;
		$_SERVER['SCRIPT_FILENAME'] = $root . $path;
		chdir( dirname( $root . $path ) );
		require $root . $path;
		return true;
	}
	return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';

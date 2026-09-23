<?php
/**
 * Tests unitaires : WordPress simulé au strict minimum.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_salt( $scheme = 'auth' ) {
	return 'sel-de-test-' . $scheme;
}

require __DIR__ . '/../../src/autoload.php';

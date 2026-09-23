<?php
/**
 * Faux Bricks : reproduit les points de contact utilisés par BricksAdapter
 * (constantes, Assets_Files, Database, filtre bricks/active_templates).
 */

define( 'BRICKS_VERSION', get_option( 'stub_bricks_version', '2.1.0' ) );
define( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );
define( 'BRICKS_DB_PAGE_HEADER', '_bricks_page_header_2' );
define( 'BRICKS_DB_PAGE_FOOTER', '_bricks_page_footer_2' );
define( 'BRICKS_DB_PAGE_SETTINGS', '_bricks_page_settings' );
define( 'BRICKS_DB_TEMPLATE_SLUG', 'bricks_template' );
define( 'BRICKS_DB_TEMPLATE_TYPE', '_bricks_template_type' );
define( 'BRICKS_DB_TEMPLATE_SETTINGS', '_bricks_template_settings' );
define( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' );

require __DIR__ . '/includes/stub.php';

add_action(
	'init',
	static function () {
		register_post_type(
			'bricks_template',
			array(
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'label'              => 'Templates',
				'supports'           => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}
);

// Comme Bricks : régénère le CSS du contenu à chaque enregistrement.
add_action(
	'save_post',
	static function ( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'file' !== \Bricks\Database::get_setting( 'cssLoading' ) ) {
			return;
		}
		$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
		if ( is_array( $elements ) && array() !== $elements ) {
			\Bricks\Assets_Files::generate_post_css_file( $post_id, 'content', $elements );
		}
	}
);

function bricks_is_builder_main() {
	return false;
}

function bricks_is_builder() {
	return false;
}

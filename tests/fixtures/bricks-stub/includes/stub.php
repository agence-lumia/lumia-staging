<?php
/**
 * Classes internes simulées, fidèles au comportement de Bricks 2.4 qui
 * compte pour Lümia Staging : dédoublonnage du CSS dans un tableau statique
 * pour toute la requête (Assets::$unique_inline_css), génération au save_post.
 */

namespace Bricks;

class Database {
	public static $global_settings = null;

	public static function get_setting( $key ) {
		if ( null === self::$global_settings ) {
			$settings              = get_option( 'bricks_global_settings', array() );
			self::$global_settings = is_array( $settings ) ? $settings : array();
		}
		return self::$global_settings[ $key ] ?? null;
	}
}

class Assets {
	public static $unique_inline_css = array();

	public static function reset_duplication_tracking() {
		self::$unique_inline_css = array();
	}
}

class Assets_Files {
	public static function generate_post_css_file( $post_id, $content_type = 'content', $elements = array() ) {
		if ( get_option( 'stub_css_broken' ) ) {
			throw new \RuntimeException( 'CSS cassé (simulation)' );
		}
		$css = '/* base */';
		foreach ( (array) $elements as $element ) {
			$selector = '#brxe-' . ( $element['id'] ?? '' );
			// Comme Bricks : un sélecteur déjà émis dans la requête est sauté.
			if ( isset( Assets::$unique_inline_css[ $selector ] ) ) {
				continue;
			}
			Assets::$unique_inline_css[ $selector ] = true;
			$css                                   .= $selector . '{/* ' . ( $element['settings']['text'] ?? '' ) . ' */}';
		}
		$dir = wp_upload_dir()['basedir'] . '/bricks/css';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/post-' . $post_id . '.min.css', $css );
		return 'post-' . $post_id . '.min.css';
	}

	public static function regenerate_css_file( $data = false, $index = false, $return = false ) {
		Assets::reset_duplication_tracking();
		$elements = get_post_meta( (int) $data, '_bricks_page_content_2', true );
		return self::generate_post_css_file( (int) $data, 'content', is_array( $elements ) ? $elements : array() );
	}
}

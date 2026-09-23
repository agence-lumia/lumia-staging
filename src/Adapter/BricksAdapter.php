<?php
/**
 * Seul point de contact avec Bricks (cahier des charges §7).
 *
 * Tout ce qui dépend de fonctions internes ou non documentées de Bricks est
 * ici : clés de métas, CSS, templates actifs, options globales, détection de
 * version. Une mise à jour de Bricks ne doit casser que ce fichier.
 *
 * Points à confirmer au lot 0 : voir CLAUDE.md, section « Lot 0 ».
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Adapter;

defined( 'ABSPATH' ) || exit;

class BricksAdapter {

	public const META_PREFIX = '_bricks_';

	private bool $inline_forced = false;

	/** @var array<int, int> Templates remplacés pendant un aperçu : source => version. */
	private array $template_swaps = array();

	/*
	------------------------------------------------------------------ */
	/*
	Détection                                                           */
	/* ------------------------------------------------------------------ */

	public function is_available(): bool {
		$available = 'bricks' === get_template() || defined( 'BRICKS_VERSION' );
		return (bool) apply_filters( 'lmv_bricks_available', $available );
	}

	public function version(): string {
		if ( defined( 'BRICKS_VERSION' ) ) {
			return (string) constant( 'BRICKS_VERSION' );
		}
		$theme = wp_get_theme( 'bricks' );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	public static function major( string $version ): int {
		return (int) strtok( $version, '.' );
	}

	public function is_builder_main(): bool {
		return function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main();
	}

	public function is_builder(): bool {
		return ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Clés de métas                                                       */
	/* ------------------------------------------------------------------ */

	public function is_bricks_key( string $key ): bool {
		return str_starts_with( $key, self::META_PREFIX );
	}

	public function template_post_type(): string {
		return defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? (string) constant( 'BRICKS_DB_TEMPLATE_SLUG' ) : 'bricks_template';
	}

	public function template_type_key(): string {
		return defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? (string) constant( 'BRICKS_DB_TEMPLATE_TYPE' ) : '_bricks_template_type';
	}

	public function template_settings_key(): string {
		return defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ? (string) constant( 'BRICKS_DB_TEMPLATE_SETTINGS' ) : '_bricks_template_settings';
	}

	public function page_settings_key(): string {
		return defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? (string) constant( 'BRICKS_DB_PAGE_SETTINGS' ) : '_bricks_page_settings';
	}

	public function editor_mode_key(): string {
		return defined( 'BRICKS_DB_EDITOR_MODE' ) ? (string) constant( 'BRICKS_DB_EDITOR_MODE' ) : '_bricks_editor_mode';
	}

	/**
	 * Clés qui contiennent des éléments Bricks (contenu, header et footer propres).
	 *
	 * @return array<string, string> zone => clé
	 */
	public function content_keys(): array {
		return array(
			'content' => defined( 'BRICKS_DB_PAGE_CONTENT' ) ? (string) constant( 'BRICKS_DB_PAGE_CONTENT' ) : '_bricks_page_content_2',
			'header'  => defined( 'BRICKS_DB_PAGE_HEADER' ) ? (string) constant( 'BRICKS_DB_PAGE_HEADER' ) : '_bricks_page_header_2',
			'footer'  => defined( 'BRICKS_DB_PAGE_FOOTER' ) ? (string) constant( 'BRICKS_DB_PAGE_FOOTER' ) : '_bricks_page_footer_2',
		);
	}

	public function is_template( int $post_id ): bool {
		return get_post_type( $post_id ) === $this->template_post_type();
	}

	public function template_type( int $post_id ): string {
		return (string) get_post_meta( $post_id, $this->template_type_key(), true );
	}

	/**
	 * Le contenu est-il édité avec Bricks ?
	 */
	public function is_bricks_post( int $post_id ): bool {
		if ( $this->is_template( $post_id ) ) {
			return true;
		}
		$mode = get_post_meta( $post_id, $this->editor_mode_key(), true );
		if ( 'bricks' === $mode ) {
			return true;
		}
		return '' !== get_post_meta( $post_id, $this->content_keys()['content'], true );
	}

	/**
	 * Valeurs brutes (telles qu'en base) de toutes les métas `_bricks_*`.
	 * Passe par le cache de métas WordPress (Redis compris).
	 *
	 * @return array<string, string>
	 */
	public function raw_meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $key => $values ) {
			$key = (string) $key;
			if ( $this->is_bricks_key( $key ) && is_array( $values ) && array() !== $values ) {
				$out[ $key ] = (string) $values[0];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Valeurs désérialisées des métas `_bricks_*`.
	 *
	 * @return array<string, mixed>
	 */
	public function meta( int $post_id ): array {
		return array_map( 'maybe_unserialize', $this->raw_meta( $post_id ) );
	}

	/**
	 * Empreinte des métas Bricks d'un contenu (détection de conflit R10).
	 * Filtre `lmv_hash_ignored_keys` pour ignorer une clé volatile.
	 */
	public function hash_post( int $post_id ): string {
		$raw     = $this->raw_meta( $post_id );
		$ignored = (array) apply_filters( 'lmv_hash_ignored_keys', array( '_bricks_lock' ) );
		foreach ( $ignored as $key ) {
			unset( $raw[ (string) $key ] );
		}
		return hash( 'sha256', (string) wp_json_encode( $raw ) );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Templates                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * R5 : réglages de template sans conditions d'affichage.
	 */
	public function neutralize_template_settings( mixed $settings ): mixed {
		if ( is_array( $settings ) ) {
			unset( $settings['templateConditions'] );
		}
		return $settings;
	}

	/**
	 * R5 : à la publication, seul le contenu est copié ; on remet les
	 * conditions de l'original dans les réglages publiés.
	 */
	public function merge_template_settings( mixed $version_settings, mixed $original_settings ): mixed {
		$version_settings = is_array( $version_settings ) ? $version_settings : array();
		unset( $version_settings['templateConditions'] );
		if ( is_array( $original_settings ) && isset( $original_settings['templateConditions'] ) ) {
			$version_settings['templateConditions'] = $original_settings['templateConditions'];
		}
		return $version_settings;
	}

	/**
	 * R7 : pendant un aperçu, remplace le template actif par sa version.
	 * Filtre Bricks `bricks/active_templates` (à confirmer au lot 0).
	 */
	public function swap_active_template( int $source_id, int $version_id ): void {
		if ( array() === $this->template_swaps ) {
			add_filter( 'bricks/active_templates', array( $this, 'filter_active_templates' ), 999 );
		}
		$this->template_swaps[ $source_id ] = $version_id;
	}

	/**
	 * @internal Callback du filtre Bricks.
	 */
	public function filter_active_templates( mixed $active ): mixed {
		return $this->replace_ids( $active );
	}

	private function replace_ids( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'replace_ids' ), $value );
		}
		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && isset( $this->template_swaps[ (int) $value ] ) ) {
			return is_int( $value ) ? $this->template_swaps[ $value ] : (string) $this->template_swaps[ (int) $value ];
		}
		return $value;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Éléments globaux (R8)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @return list<string>
	 */
	public function global_option_names(): array {
		$names = array(
			'bricks_global_classes',
			'bricks_global_classes_categories',
			'bricks_global_pseudo_classes',
			'bricks_global_variables',
			'bricks_global_variables_categories',
			'bricks_color_palette',
			'bricks_theme_styles',
			'bricks_components',
		);
		return array_values( array_map( 'strval', (array) apply_filters( 'lmv_bricks_global_options', $names ) ) );
	}

	public function hash_globals(): string {
		$data = array();
		foreach ( $this->global_option_names() as $name ) {
			$data[ $name ] = get_option( $name, null );
		}
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/**
	 * Vide le cache objet des options Bricks (Redis) après publication.
	 */
	public function flush_option_cache(): void {
		foreach ( array_merge( $this->global_option_names(), array( 'bricks_global_settings' ) ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/*
	------------------------------------------------------------------ */
	/*
	Builder                                                             */
	/* ------------------------------------------------------------------ */

	public function builder_url( int $post_id ): string {
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_builder_edit_link' ) ) {
			return (string) \Bricks\Helpers::get_builder_edit_link( $post_id );
		}
		return add_query_arg( 'bricks', 'run', (string) get_permalink( $post_id ) );
	}

	/**
	 * Points de rupture Bricks du site, pour le comparatif (F4).
	 *
	 * @return list<array{key: string, label: string, width: int}>
	 */
	public function breakpoints(): array {
		$stored = get_option( 'bricks_breakpoints' );
		$out    = array(
			array(
				'key'   => 'desktop',
				'label' => __( 'Bureau', 'lumia-staging' ),
				'width' => 1440,
			),
		);
		if ( is_array( $stored ) && array() !== $stored ) {
			foreach ( $stored as $bp ) {
				if ( is_array( $bp ) && isset( $bp['key'], $bp['width'] ) && 'desktop' !== $bp['key'] && empty( $bp['disabled'] ) ) {
					$out[] = array(
						'key'   => (string) $bp['key'],
						'label' => (string) ( $bp['label'] ?? $bp['key'] ),
						'width' => (int) $bp['width'],
					);
				}
			}
			return $out;
		}
		return array_merge(
			$out,
			array(
				array(
					'key'   => 'tablet_portrait',
					'label' => __( 'Tablette', 'lumia-staging' ),
					'width' => 991,
				),
				array(
					'key'   => 'mobile_portrait',
					'label' => __( 'Mobile', 'lumia-staging' ),
					'width' => 478,
				),
			)
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	CSS (R3)                                                            */
	/* ------------------------------------------------------------------ */

	public function css_file_mode(): bool {
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' ) ) {
			return 'file' === \Bricks\Database::get_setting( 'cssLoading' );
		}
		$settings = get_option( 'bricks_global_settings' );
		return is_array( $settings ) && 'file' === ( $settings['cssLoading'] ?? '' );
	}

	public function css_file_path( int $post_id ): string {
		$upload = wp_upload_dir( null, false );
		return trailingslashit( (string) $upload['basedir'] ) . 'bricks/css/post-' . $post_id . '.min.css';
	}

	/**
	 * Régénère le fichier CSS d'un contenu. Renvoie un avertissement si la
	 * fonction interne attendue n'existe plus (repli, alerte au journal).
	 *
	 * @return array{ok: bool, warning: string}
	 */
	public function regenerate_css( int $post_id ): array {
		if ( ! $this->css_file_mode() ) {
			return array(
				'ok'      => true,
				'warning' => '',
			);
		}
		$class = '\Bricks\Assets_Files';
		try {
			// Bricks dédoublonne le CSS dans des tableaux statiques pour toute
			// la requête (Assets::$unique_inline_css). Si le save_post de Bricks
			// vient de générer ce fichier, une seconde génération sans remise à
			// zéro réécrirait un fichier sans les styles des éléments.
			$this->reset_css_tracking();

			// 1) Chemin officiel de Bricks (bouton « Régénérer les CSS », WP-CLI) :
			// détecte seul la zone (header, footer, contenu). Index ≠ 0 : l'index 0
			// supprime tous les fichiers CSS du site.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lecture seule : regenerate_css_file() lit $_POST['data'] en priorité.
			$post_overrides = isset( $_POST['data'] ) || isset( $_POST['index'] );
			if ( ! $post_overrides && class_exists( $class ) && method_exists( $class, 'regenerate_css_file' ) ) {
				\Bricks\Assets_Files::regenerate_css_file( (string) $post_id, 1, true );
				return array(
					'ok'      => true,
					'warning' => '',
				);
			}

			// 2) Même logique que Assets_Files::save_post().
			if ( class_exists( $class ) && method_exists( $class, 'generate_post_css_file' ) ) {
				[ $area, $elements ] = $this->css_area_and_elements( $post_id );
				\Bricks\Assets_Files::generate_post_css_file( $post_id, $area, $elements );
				return array(
					'ok'      => true,
					'warning' => '',
				);
			}
			$reason = 'Assets_Files::regenerate_css_file introuvable';
		} catch ( \Throwable $e ) {
			$reason = $e->getMessage();
		}

		// Repli : on supprime le fichier périmé, Bricks le reconstruit au
		// prochain enregistrement ; alerte dans le journal.
		$this->delete_css_file( $post_id );
		return array(
			'ok'      => false,
			'warning' => sprintf( 'Régénération CSS Bricks impossible (%s) : fichier supprimé, régénérez les CSS depuis Bricks > Réglages > Performance.', $reason ),
		);
	}

	/**
	 * Remet à zéro le dédoublonnage CSS de Bricks (Assets::reset_duplication_tracking, Bricks 2.0+).
	 */
	private function reset_css_tracking(): void {
		if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'reset_duplication_tracking' ) ) {
			\Bricks\Assets::reset_duplication_tracking();
		}
	}

	/**
	 * Zone CSS et éléments d'un contenu, comme Bricks les détermine.
	 *
	 * @return array{0: string, 1: array<mixed>}
	 */
	private function css_area_and_elements( int $post_id ): array {
		$keys = $this->content_keys();
		if ( $this->is_template( $post_id ) ) {
			foreach ( array( 'header', 'footer' ) as $area ) {
				$elements = get_post_meta( $post_id, $keys[ $area ], true );
				if ( is_array( $elements ) && array() !== $elements ) {
					return array( $area, $elements );
				}
			}
		}
		$elements = get_post_meta( $post_id, $keys['content'], true );
		return array( 'content', is_array( $elements ) ? $elements : array() );
	}

	public function delete_css_file( int $post_id ): void {
		$path = $this->css_file_path( $post_id );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Pendant un aperçu, force le chargement du CSS en ligne : il est alors
	 * calculé depuis les métas affichées (version ou sauvegarde) et non lu
	 * dans le fichier de l'original.
	 */
	public function force_inline_css(): void {
		if ( $this->inline_forced ) {
			return;
		}
		$this->inline_forced = true;
		add_filter(
			'option_bricks_global_settings',
			static function ( $settings ) {
				if ( is_array( $settings ) ) {
					unset( $settings['cssLoading'] );
				}
				return $settings;
			}
		);
		// Bricks charge ses réglages dans Database::$global_settings dès le
		// chargement du thème, avant nos filtres : on corrige aussi la copie
		// en mémoire, au plus tard avant le rendu de la page.
		$patch = static function (): void {
			if ( class_exists( '\Bricks\Database' ) && property_exists( '\Bricks\Database', 'global_settings' ) && is_array( \Bricks\Database::$global_settings ) ) {
				unset( \Bricks\Database::$global_settings['cssLoading'] );
			}
		};
		$patch();
		add_action( 'wp', $patch, 0 );
		add_action( 'template_redirect', $patch, 0 );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Analyse des éléments                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Images utilisées dans les éléments et absentes de la médiathèque.
	 *
	 * @param array<string, mixed> $meta Métas désérialisées.
	 * @return list<array{element: string, name: string, attachment: int}>
	 */
	public function missing_media( array $meta ): array {
		$missing = array();
		foreach ( $this->content_keys() as $key ) {
			$elements = $meta[ $key ] ?? array();
			if ( ! is_array( $elements ) ) {
				continue;
			}
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) || ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
					continue;
				}
				foreach ( $this->attachment_ids( $element['settings'] ) as $attachment_id ) {
					if ( 'attachment' !== get_post_type( $attachment_id ) ) {
						$missing[] = array(
							'element'    => (string) ( $element['id'] ?? '' ),
							'name'       => (string) ( $element['label'] ?? $element['name'] ?? '' ),
							'attachment' => $attachment_id,
						);
					}
				}
			}
		}
		return $missing;
	}

	/**
	 * @param array<mixed> $settings
	 * @return list<int>
	 */
	private function attachment_ids( array $settings ): array {
		$ids = array();
		if ( isset( $settings['id'] ) && is_numeric( $settings['id'] ) && ( isset( $settings['url'] ) || isset( $settings['filename'] ) ) && empty( $settings['useDynamicData'] ) ) {
			$ids[] = (int) $settings['id'];
		}
		foreach ( $settings as $value ) {
			if ( is_array( $value ) ) {
				$ids = array_merge( $ids, $this->attachment_ids( $value ) );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}

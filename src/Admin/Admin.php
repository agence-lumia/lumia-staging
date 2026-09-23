<?php
/**
 * Administration : menus, écrans React, actions de ligne, notices,
 * widget du tableau de bord (F9), export du journal (F10).
 *
 * Un utilisateur sans capacité `lmv_*` ne voit aucune interface du plugin.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Admin;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Adapter\WooAdapter;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Publish\RecoveryService;
use Lumia\Staging\Rest\Presenter;
use Lumia\Staging\Rest\RestController;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Capabilities;
use Lumia\Staging\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SCREENS = array(
		'toplevel_page_lumia-staging'          => 'dashboard',
		'versions_page_lumia-staging-history'  => 'history',
		'versions_page_lumia-staging-compare'  => 'compare',
		'versions_page_lumia-staging-log'      => 'log',
		'versions_page_lumia-staging-settings' => 'settings',
	);

	/** @var array<string, string> hook_suffix => écran */
	private array $hooks = array();

	public function __construct(
		private VersionService $versions,
		private BricksAdapter $bricks,
		private RecoveryService $recovery,
		private Presenter $presenter,
		private Settings $settings,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'recover' ), 5 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_head', array( $this, 'hide_submenus' ) );
		add_action( 'load-post.php', array( $this, 'redirect_version_editor' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'admin_post_lmv_export_log', array( $this, 'export_log' ) );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( LMV_FILE ), array( $this, 'plugin_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	public function menu(): void {
		$pages = array(
			array( null, __( 'Versions', 'lumia-staging' ), Capabilities::CREATE, 'lumia-staging', 'dashboard' ),
			array( 'lumia-staging', __( 'Tableau de bord', 'lumia-staging' ), Capabilities::CREATE, 'lumia-staging', 'dashboard' ),
			array( 'lumia-staging', __( 'Historique', 'lumia-staging' ), Capabilities::RESTORE, 'lumia-staging-history', 'history' ),
			array( 'lumia-staging', __( 'Comparer', 'lumia-staging' ), Capabilities::CREATE, 'lumia-staging-compare', 'compare' ),
			array( 'lumia-staging', __( 'Journal', 'lumia-staging' ), Capabilities::SETTINGS, 'lumia-staging-log', 'log' ),
			array( 'lumia-staging', __( 'Réglages', 'lumia-staging' ), Capabilities::SETTINGS, 'lumia-staging-settings', 'settings' ),
		);
		foreach ( $pages as [ $parent, $title, $cap, $slug, $screen ] ) {
			$render = fn() => $this->render( $screen );
			$hook   = null === $parent
				? add_menu_page( $title, $title, $cap, $slug, $render, 'dashicons-backup', 58 )
				: add_submenu_page( $parent, $title . ' — Lümia Staging', $title, $cap, $slug, $render );
			if ( is_string( $hook ) && '' !== $hook ) {
				$this->hooks[ $hook ] = $screen;
			}
		}
	}

	/**
	 * Classe de corps sur les écrans du plugin (mise en page plein écran).
	 */
	public function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( null !== $screen && isset( $this->hooks[ $screen->id ] ) ) {
			$classes .= ' lmv-admin-page lmv-screen-' . $this->hooks[ $screen->id ];
		}
		return $classes;
	}

	/**
	 * Historique et Comparer s'ouvrent depuis un contenu : pas d'entrée de menu.
	 */
	public function hide_submenus(): void {
		echo '<style>#toplevel_page_lumia-staging a[href$="page=lumia-staging-history"],#toplevel_page_lumia-staging a[href$="page=lumia-staging-compare"]{display:none!important}</style>';
	}

	private function render( string $screen ): void {
		// wp-header-end : les notices des autres plugins s'affichent au-dessus de l'application.
		echo '<div class="wrap lmv-wrap"><hr class="wp-header-end"><div id="lmv-admin" class="lmv-admin" data-screen="' . esc_attr( $screen ) . '">';
		echo '<noscript>' . esc_html__( 'Lümia Staging nécessite JavaScript.', 'lumia-staging' ) . '</noscript>';
		echo '</div></div>';
	}

	public function enqueue( string $hook ): void {
		$screen = $this->hooks[ $hook ] ?? self::SCREENS[ $hook ] ?? null;

		if ( in_array( $hook, array( 'edit.php', 'index.php' ), true ) && Capabilities::has_any() ) {
			$this->enqueue_actions_script();
		}
		if ( null === $screen ) {
			return;
		}

		$asset_file = LMV_DIR . 'build/admin.asset.php';
		$asset      = is_file( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-date' ),
			'version'      => LMV_VERSION,
		);
		wp_enqueue_script( 'lmv-admin', LMV_URL . 'build/admin.js', (array) $asset['dependencies'], (string) $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'lmv-admin', LMV_URL . 'build/style-admin.css', array( 'wp-components' ), (string) $asset['version'] );
		wp_set_script_translations( 'lmv-admin', 'lumia-staging', LMV_DIR . 'languages' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture de paramètres d'affichage.
		$params = array(
			'version'  => isset( $_GET['version'] ) ? absint( $_GET['version'] ) : 0,
			'post'     => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0,
			'snapshot' => isset( $_GET['snapshot'] ) ? absint( $_GET['snapshot'] ) : 0,
			'on_page'  => isset( $_GET['on_page'] ) ? absint( $_GET['on_page'] ) : 0,
			'action'   => isset( $_GET['lmv_action'] ) ? sanitize_key( $_GET['lmv_action'] ) : '',
		);
		// phpcs:enable

		wp_add_inline_script(
			'lmv-admin',
			'window.lmvAdmin=' . wp_json_encode(
				array(
					'screen'      => $screen,
					'namespace'   => RestController::NS,
					'params'      => $params,
					'adminUrl'    => admin_url(),
					'caps'        => array(
						'create'   => current_user_can( Capabilities::CREATE ),
						'publish'  => current_user_can( Capabilities::PUBLISH ),
						'restore'  => current_user_can( Capabilities::RESTORE ),
						'share'    => current_user_can( Capabilities::SHARE ),
						'settings' => current_user_can( Capabilities::SETTINGS ),
					),
					'previewDays' => $this->settings->int( 'preview_days' ),
					'timezone'    => wp_timezone_string(),
					'logExport'   => wp_nonce_url( admin_url( 'admin-post.php?action=lmv_export_log' ), 'lmv_export_log' ),
					'woo'         => WooAdapter::woo_active(),
					'staleDays'   => 14,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Petit script d'actions (« Créer une version ») pour les listes et la barre d'admin.
	 */
	public function enqueue_actions_script(): void {
		wp_enqueue_script( 'lmv-actions', LMV_URL . 'assets/admin/actions.js', array( 'wp-api-fetch' ), LMV_VERSION, true );
		wp_add_inline_script(
			'lmv-actions',
			'window.lmvActions=' . wp_json_encode(
				array(
					'namespace' => RestController::NS,
					'creating'  => __( 'Création de la version…', 'lumia-staging' ),
					'error'     => __( 'Impossible de créer la version.', 'lumia-staging' ),
				)
			) . ';',
			'before'
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	Listes de contenus                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! current_user_can( Capabilities::CREATE ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( 'trash' === $post->post_status || ! $this->versions->supports( $post->ID ) ) {
			return $actions;
		}
		$open = $this->versions->open_version_for( $post->ID );
		if ( $open > 0 ) {
			$actions['lmv_resume'] = '<a href="' . esc_url( $this->bricks->builder_url( $open ) ) . '">' . esc_html__( 'Reprendre la version', 'lumia-staging' ) . '</a>';
		} else {
			$actions['lmv_create'] = '<a href="#" class="lmv-create" data-post="' . esc_attr( (string) $post->ID ) . '" role="button">' . esc_html__( 'Créer une version', 'lumia-staging' ) . '</a>';
		}
		if ( current_user_can( Capabilities::RESTORE ) ) {
			$actions['lmv_history'] = '<a href="' . esc_url( admin_url( 'admin.php?page=lumia-staging-history&post=' . $post->ID ) ) . '">' . esc_html__( 'Historique', 'lumia-staging' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * @param array<string, string> $states
	 * @return array<string, string>
	 */
	public function post_states( array $states, \WP_Post $post ): array {
		if ( Capabilities::has_any() && 'trash' !== $post->post_status && $this->versions->open_version_for( $post->ID ) > 0 ) {
			$states['lmv_open'] = __( 'Version de travail ouverte', 'lumia-staging' );
		}
		return $states;
	}

	/**
	 * Une version s'édite dans Bricks, pas dans l'éditeur WordPress.
	 */
	public function redirect_version_editor(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id > 0 && $this->versions->is_version( $post_id ) && current_user_can( Capabilities::CREATE ) ) {
			wp_safe_redirect( $this->bricks->builder_url( $post_id ) );
			exit;
		}
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function plugin_links( array $links ): array {
		if ( current_user_can( Capabilities::SETTINGS ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lumia-staging-settings' ) ) . '">' . esc_html__( 'Réglages', 'lumia-staging' ) . '</a>' );
		}
		return $links;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Reprise après incident, notices                                     */
	/* ------------------------------------------------------------------ */

	public function recover(): void {
		if ( wp_doing_ajax() || ! Capabilities::has_any() ) {
			return;
		}
		$this->recovery->run();
	}

	public function notices(): void {
		if ( ! Capabilities::has_any() ) {
			return;
		}
		$notices = (array) get_option( RecoveryService::NOTICE_OPTION, array() );
		if ( array() === $notices ) {
			return;
		}
		delete_option( RecoveryService::NOTICE_OPTION );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Lümia Staging — publication interrompue', 'lumia-staging' ) . '</strong><br>';
		echo esc_html(
			sprintf(
				/* translators: %s: list of titles */
				__( 'Une publication s\'est arrêtée en cours de route (délai dépassé ou serveur redémarré). La version en ligne a été restaurée à l\'identique et la version de travail est conservée : %s. Vous pouvez relancer la publication.', 'lumia-staging' ),
				implode( ', ', array_map( 'strval', $notices ) )
			)
		);
		echo '</p></div>';
	}

	/*
	------------------------------------------------------------------ */
	/*
	Widget du tableau de bord WordPress (F9)                             */
	/* ------------------------------------------------------------------ */

	public function dashboard_widget(): void {
		if ( current_user_can( Capabilities::CREATE ) ) {
			wp_add_dashboard_widget( 'lmv_dashboard', __( 'Versions de travail', 'lumia-staging' ), array( $this, 'render_widget' ) );
		}
	}

	public function render_widget(): void {
		$stale     = array();
		$feedbacks = array();
		$scheduled = array();
		$limit     = time() + WEEK_IN_SECONDS;
		foreach ( $this->versions->list_ids() as $version_id ) {
			$v = $this->presenter->version( $version_id );
			if ( $v['age_days'] >= 14 && State::Scheduled->value !== $v['state'] ) {
				$stale[] = $v;
			}
			if ( null !== $v['feedback'] && strtotime( (string) $v['feedback']['date'] ) > time() - WEEK_IN_SECONDS ) {
				$feedbacks[] = $v;
			}
			$at = (int) get_post_meta( $version_id, Meta::SCHEDULED_AT, true );
			if ( $at > 0 && $at <= $limit ) {
				$scheduled[] = $v + array( 'at' => $at );
			}
		}
		$base = admin_url( 'admin.php?page=lumia-staging&version=' );

		if ( array() === $stale && array() === $feedbacks && array() === $scheduled ) {
			echo '<p>' . esc_html__( 'Rien à signaler : aucune version oubliée, aucune validation reçue, aucune publication programmée cette semaine.', 'lumia-staging' ) . '</p>';
		}
		$this->widget_list(
			__( 'Validations reçues', 'lumia-staging' ),
			$feedbacks,
			static fn( $v ) => ( 'approve' === $v['feedback']['decision']
				/* translators: %s: name */
				? sprintf( __( 'validée par %s', 'lumia-staging' ), $v['feedback']['name'] )
				/* translators: %s: name */
				: sprintf( __( 'modifications demandées par %s', 'lumia-staging' ), $v['feedback']['name'] ) ),
			$base
		);
		$this->widget_list(
			__( 'Publications programmées (7 jours)', 'lumia-staging' ),
			$scheduled,
			/* translators: %s: date */
			static fn( $v ) => sprintf( __( 'en ligne le %s', 'lumia-staging' ), wp_date( 'd/m H:i', (int) $v['at'] ) ),
			$base
		);
		$this->widget_list(
			__( 'Versions ouvertes depuis plus de 14 jours', 'lumia-staging' ),
			$stale,
			/* translators: %d: days */
			static fn( $v ) => sprintf( _n( '%d jour', '%d jours', (int) $v['age_days'], 'lumia-staging' ), (int) $v['age_days'] ),
			$base
		);
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=lumia-staging' ) ) . '">' . esc_html__( 'Toutes les versions', 'lumia-staging' ) . '</a></p>';
	}

	/**
	 * @param list<array<string, mixed>>             $items
	 * @param callable(array<string, mixed>): string $detail
	 */
	private function widget_list( string $title, array $items, callable $detail, string $base ): void {
		if ( array() === $items ) {
			return;
		}
		echo '<h3 style="margin:1em 0 .4em">' . esc_html( $title ) . '</h3><ul style="margin:0">';
		foreach ( $items as $v ) {
			echo '<li><a href="' . esc_url( $base . (int) $v['id'] ) . '">' . esc_html( (string) $v['title'] ) . '</a> — ' . esc_html( $detail( $v ) ) . '</li>';
		}
		echo '</ul>';
	}

	/*
	------------------------------------------------------------------ */
	/*
	Export CSV du journal                                               */
	/* ------------------------------------------------------------------ */

	public function export_log(): void {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'lumia-staging' ), 403 );
		}
		check_admin_referer( 'lmv_export_log' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lumia-staging-journal-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- flux php://output ; BOM pour Excel.
			$this->logger->export_csv( $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		exit;
	}
}

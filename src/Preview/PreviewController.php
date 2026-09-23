<?php
/**
 * Aperçu client et aperçus signés (F3, R7, R12, R13, R14, §8).
 *
 * Le jeton reste dans l'URL à chaque page (`?lmv_preview=…`), sans cookie :
 * le nginx du template Dokploy sert le cache Cache Enabler avant PHP, sauf si
 * l'URL a des paramètres. Les liens internes de la page d'aperçu sont
 * réécrits pour porter le jeton.
 *
 * L'aperçu est rendu comme pour un visiteur anonyme, jamais mis en cache,
 * et bloque toute soumission (formulaires, commande WooCommerce).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Preview;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Adapter\CacheAdapter;
use Lumia\Staging\Adapter\WooAdapter;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Notify\Mailer;
use Lumia\Staging\Post\PostStatus;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;

defined( 'ABSPATH' ) || exit;

class PreviewController {

	public const SIGNED_TTL = HOUR_IN_SECONDS;

	private ?PreviewContext $context = null;

	private string $failure = '';

	public function __construct(
		private TokenRepository $tokens,
		private FeedbackRepository $feedback,
		private RateLimiter $limiter,
		private PostStatus $status,
		private BricksAdapter $bricks,
		private CacheAdapter $cache,
		private VersionService $versions,
		private SnapshotRepository $snapshots,
		private Mailer $mailer,
		private Logger $logger
	) {}

	public function context(): ?PreviewContext {
		return $this->context;
	}

	/*
	------------------------------------------------------------------ */
	/*
	URLs                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Page sur laquelle s'affiche l'aperçu : l'original pour une page,
	 * la page choisie (ou l'accueil) pour un template (R7).
	 */
	public function base_url( int $source_id, int $on_page = 0 ): string {
		if ( $this->bricks->is_template( $source_id ) ) {
			return $on_page > 0 ? (string) get_permalink( $on_page ) : home_url( '/' );
		}
		return (string) get_permalink( $source_id );
	}

	public function client_url( int $version_id, string $token, int $on_page = 0 ): string {
		return add_query_arg( 'lmv_preview', $token, $this->base_url( $this->versions->source_id( $version_id ), $on_page ) );
	}

	/**
	 * URL signée à durée courte : aperçu admin et iframes du comparatif.
	 */
	public function signed_url( string $base, int $version_id, int $snapshot_id, bool $live ): string {
		$expires = time() + self::SIGNED_TTL;
		return add_query_arg(
			array(
				'lmv_v'    => $version_id,
				'lmv_s'    => $snapshot_id,
				'lmv_live' => $live ? '1' : '0',
				'lmv_exp'  => $expires,
				'lmv_sig'  => self::sign( $version_id, $snapshot_id, $live, $expires ),
			),
			$base
		);
	}

	public static function sign( int $version_id, int $snapshot_id, bool $live, int $expires ): string {
		return hash_hmac( 'sha256', $version_id . '|' . $snapshot_id . '|' . ( $live ? 1 : 0 ) . '|' . $expires, wp_salt( 'auth' ) );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Démarrage (plugins_loaded, avant l'identification de l'utilisateur) */
	/* ------------------------------------------------------------------ */

	public function boot(): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- jeton / signature vérifiés ci-dessous.
		$token = isset( $_GET['lmv_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['lmv_preview'] ) ) : '';
		$sig   = isset( $_GET['lmv_sig'] ) ? sanitize_text_field( wp_unslash( $_GET['lmv_sig'] ) ) : '';
		// phpcs:enable
		if ( '' === $token && '' === $sig ) {
			return;
		}

		if ( $this->limiter->is_blocked() ) {
			$this->failure = 'blocked';
		} else {
			$this->context = '' !== $token ? $this->resolve_client( $token ) : $this->resolve_signed( $sig );
			if ( null === $this->context ) {
				$this->failure = 'invalid';
			}
		}

		// Rendu anonyme, même si la personne est connectée ailleurs.
		add_filter( 'determine_current_user', '__return_zero', 999 );
		add_action( 'init', array( $this, 'init' ), 0 );
	}

	private function resolve_client( string $token ): ?PreviewContext {
		$row = $this->tokens->find_valid( $token );
		if ( null === $row ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$live = isset( $_GET['lmv_side'] ) && 'before' === $_GET['lmv_side'];
		$args = array( 'lmv_preview' => $token );
		if ( $live ) {
			$args['lmv_side'] = 'before';
		}
		return new PreviewContext( 'client', $row['version_id'], $this->versions->source_id( $row['version_id'] ), 0, $live, $row['id'], $row['expires_at'], $args );
	}

	private function resolve_signed( string $sig ): ?PreviewContext {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$version_id  = isset( $_GET['lmv_v'] ) ? absint( $_GET['lmv_v'] ) : 0;
		$snapshot_id = isset( $_GET['lmv_s'] ) ? absint( $_GET['lmv_s'] ) : 0;
		$live        = isset( $_GET['lmv_live'] ) && '1' === $_GET['lmv_live'];
		$expires     = isset( $_GET['lmv_exp'] ) ? absint( $_GET['lmv_exp'] ) : 0;
		// phpcs:enable
		if ( $expires < time() || ! hash_equals( self::sign( $version_id, $snapshot_id, $live, $expires ), $sig ) ) {
			return null;
		}
		$source_id = 0;
		if ( $version_id > 0 ) {
			if ( ! $this->versions->is_version( $version_id ) ) {
				return null;
			}
			$source_id = $this->versions->source_id( $version_id );
		} elseif ( $snapshot_id > 0 ) {
			$snapshot = $this->snapshots->get( $snapshot_id );
			if ( null === $snapshot ) {
				return null;
			}
			$source_id = (int) $snapshot['post_id'];
		}
		$args = array(
			'lmv_v'    => (string) $version_id,
			'lmv_s'    => (string) $snapshot_id,
			'lmv_live' => $live ? '1' : '0',
			'lmv_exp'  => (string) $expires,
			'lmv_sig'  => $sig,
		);
		return new PreviewContext( 'signed', $version_id, $source_id, $snapshot_id, $live, 0, '', $args );
	}

	/*
	------------------------------------------------------------------ */
	/*
	init                                                                */
	/* ------------------------------------------------------------------ */

	public function init(): void {
		if ( 'blocked' === $this->failure ) {
			$this->neutral_page( 429 );
		}
		if ( 'invalid' === $this->failure || null === $this->context ) {
			$this->limiter->fail();
			$this->neutral_page( 404 );
		}
		$context = $this->context;

		if ( 0 !== get_current_user_id() ) {
			wp_set_current_user( 0 );
		}
		$this->cache->bypass_current_request();
		$this->send_headers();
		add_filter( 'show_admin_bar', '__return_false', 999 );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'redirect_canonical', '__return_false' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans handle_feedback().
			if ( $context->is_client() && isset( $_POST['lmv_action'] ) && 'feedback' === $_POST['lmv_action'] ) {
				$this->handle_feedback( $context );
			}
			$this->block_submission();
		}

		$this->setup_rendering( $context );

		add_filter( 'admin_url', array( $this, 'filter_ajax_url' ), 999, 2 );
		add_filter( 'rest_url', array( $this, 'add_args' ), 999 );
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 0 );

		if ( WooAdapter::woo_active() ) {
			$woo = new WooAdapter();
			$woo->block_payments( array( $this, 'add_args' ) );
			if ( $context->shows_version() && ! $this->bricks->is_template( $context->source_id ) ) {
				$woo->swap_special_pages( $context->source_id, $context->version_id ); // R12.
			}
		}
		if ( $context->is_client() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_toolbar' ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_guard' ) );
	}

	private function setup_rendering( PreviewContext $context ): void {
		if ( $context->shows_version() ) {
			$this->status->allow( $context->version_id );
			$this->bricks->force_inline_css();
			if ( $this->bricks->is_template( $context->source_id ) ) {
				$this->bricks->swap_active_template( $context->source_id, $context->version_id ); // R7.
			} else {
				$source  = $context->source_id;
				$version = $context->version_id;
				// R13 : page d'accueil.
				add_filter( 'option_page_on_front', static fn( $id ) => (int) $id === $source ? $version : $id, 999 );
				add_action( 'pre_get_posts', fn( \WP_Query $q ) => $this->swap_main_query( $q, $source, $version ), 1 );
			}
			return;
		}
		if ( $context->snapshot_id > 0 && ! $context->live ) {
			$snapshot = $this->snapshots->get( $context->snapshot_id );
			$meta     = null !== $snapshot && is_array( $snapshot['data']['meta'] ?? null ) ? $snapshot['data']['meta'] : array();
			$post_id  = $context->source_id;
			$this->bricks->force_inline_css();
			add_filter(
				'get_post_metadata',
				static function ( $value, $object_id, $meta_key, $single ) use ( $post_id, $meta ) {
					if ( (int) $object_id !== $post_id || ! is_string( $meta_key ) || ! str_starts_with( $meta_key, BricksAdapter::META_PREFIX ) ) {
						return $value;
					}
					if ( ! array_key_exists( $meta_key, $meta ) ) {
						return $single ? array( '' ) : array();
					}
					return array( maybe_unserialize( (string) $meta[ $meta_key ] ) );
				},
				999,
				4
			);
		}
	}

	/**
	 * La requête principale qui vise l'original affiche la version.
	 */
	private function swap_main_query( \WP_Query $query, int $source, int $version ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}
		$page_id = (int) $query->get( 'page_id' );
		$p       = (int) $query->get( 'p' );
		$target  = in_array( $page_id, array( $source, $version ), true ) || $p === $source;
		if ( ! $target && '' !== (string) $query->get( 'pagename' ) ) {
			$page   = get_page_by_path( (string) $query->get( 'pagename' ) );
			$target = $page instanceof \WP_Post && $page->ID === $source;
		}
		if ( ! $target ) {
			return;
		}
		$query->set( 'page_id', $version );
		$query->set( 'p', 0 );
		$query->set( 'pagename', '' );
		$query->set( 'name', '' );
		$query->set( 'post_type', get_post_type( $source ) );
		$query->set( 'post_status', array( 'publish', PostStatus::STATUS ) );
		$query->queried_object    = get_post( $version );
		$query->queried_object_id = $version;
	}

	/*
	------------------------------------------------------------------ */
	/*
	En-têtes, blocages                                                   */
	/* ------------------------------------------------------------------ */

	private function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: frame-ancestors 'self'" );
	}

	/**
	 * Page neutre : ne révèle rien du site ni de la version.
	 */
	private function neutral_page( int $status ): never {
		status_header( $status );
		$this->send_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( 429 === $status ) {
			header( 'Retry-After: ' . RateLimiter::BLOCK );
		}
		$title = 429 === $status ? 'Trop de tentatives' : 'Lien indisponible';
		$text  = 429 === $status
			? 'Trop de liens invalides ont été ouverts depuis votre connexion. Réessayez dans 15 minutes.'
			: 'Ce lien d\'aperçu n\'est plus valide : il a expiré ou a été révoqué. Demandez un nouveau lien à la personne qui vous l\'a envoyé.';
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#f6f6f8;color:#1d1d24}main{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}</style>'
			. '</head><body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></main></body></html>';
		exit;
	}

	private function block_submission(): never {
		$message = __( 'Aperçu : les envois de formulaires et les commandes sont désactivés.', 'lumia-staging' );
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) || str_contains( $uri, '/wp-json/' ) || isset( $_GET['rest_route'] ) ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		status_header( 403 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Aperçu', 'lumia-staging' ) . '</title></head><body style="font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0"><p>' . esc_html( $message ) . ' <a href="javascript:history.back()">' . esc_html__( 'Retour', 'lumia-staging' ) . '</a></p></body></html>';
		exit;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Retours client                                                      */
	/* ------------------------------------------------------------------ */

	private function handle_feedback( PreviewContext $context ): never {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié juste en dessous.
		$nonce = isset( $_POST['lmv_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lmv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lmv_feedback_' . $context->token_id ) ) {
			wp_send_json_error( array( 'message' => __( 'La page a expiré. Rechargez-la puis recommencez.', 'lumia-staging' ) ), 403 );
		}
		// Champ piège : un robot le remplit, un humain ne le voit pas.
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_success( array( 'decision' => 'ok' ) );
		}
		$name     = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ) );
		$decision = sanitize_key( wp_unslash( (string) ( $_POST['decision'] ?? '' ) ) );
		$comment  = trim( wp_strip_all_tags( wp_unslash( (string) ( $_POST['comment'] ?? '' ) ) ) );
		// phpcs:enable

		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Indiquez votre nom (100 caractères maximum).', 'lumia-staging' ) ), 400 );
		}
		if ( ! in_array( $decision, array( FeedbackRepository::APPROVE, FeedbackRepository::CHANGES ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choix invalide.', 'lumia-staging' ) ), 400 );
		}
		if ( mb_strlen( $comment ) > 2000 ) {
			wp_send_json_error( array( 'message' => __( 'Le commentaire est limité à 2 000 caractères.', 'lumia-staging' ) ), 400 );
		}
		if ( FeedbackRepository::CHANGES === $decision && '' === $comment ) {
			wp_send_json_error( array( 'message' => __( 'Décrivez les modifications souhaitées.', 'lumia-staging' ) ), 400 );
		}

		$version_id = $context->version_id;
		$source_id  = $context->source_id;
		$this->feedback->add( $version_id, $context->token_id, $name, $decision, $comment );

		$state = $this->versions->state( $version_id );
		if ( in_array( $state, array( State::InProgress, State::InReview, State::Approved ), true ) ) {
			update_post_meta( $version_id, \Lumia\Staging\Domain\Meta::STATE, FeedbackRepository::APPROVE === $decision ? State::Approved->value : State::InProgress->value );
		}

		$title = (string) get_the_title( $source_id );
		$this->logger->log(
			'client_feedback',
			$source_id,
			$version_id,
			FeedbackRepository::APPROVE === $decision
				? sprintf( '%s a validé la version.', $name )
				: sprintf( '%s demande des modifications.', $name ),
			array( 'comment' => $comment )
		);
		$this->mailer->feedback( (int) get_post_field( 'post_author', $version_id ), $title, $name, $decision, $comment, admin_url( 'admin.php?page=lumia-staging&version=' . $version_id ) );

		do_action( 'lmv_client_feedback', $version_id, $decision, $name, $comment );
		wp_send_json_success( array( 'decision' => $decision ) );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Réécriture des liens, scripts                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Ajoute les paramètres d'aperçu à une URL interne.
	 */
	public function add_args( string $url ): string {
		if ( null === $this->context || ! $this->is_internal( $url ) ) {
			return $url;
		}
		$url = remove_query_arg( array( 'lmv_preview', 'lmv_side', 'lmv_v', 'lmv_s', 'lmv_live', 'lmv_exp', 'lmv_sig' ), $url );
		return add_query_arg( array_map( 'rawurlencode', $this->context->args ), $url );
	}

	/**
	 * @internal Seul admin-ajax.php porte le jeton (pour être bloqué en POST).
	 */
	public function filter_ajax_url( string $url, string $path ): string {
		return str_starts_with( $path, 'admin-ajax.php' ) ? $this->add_args( $url ) : $url;
	}

	public function start_buffer(): void {
		ob_start( array( $this, 'rewrite_links' ) );
	}

	/**
	 * @internal Callback d'ob_start.
	 */
	public function rewrite_links( string $html ): string {
		$result = preg_replace_callback(
			'/(<(?:a|form)\b[^>]*?\s(?:href|action)\s*=\s*)(["\'])(.*?)\2/i',
			function ( array $m ): string {
				$url = html_entity_decode( $m[3], ENT_QUOTES );
				if ( ! $this->is_internal( $url ) ) {
					return $m[0];
				}
				return $m[1] . $m[2] . esc_attr( $this->add_args( $url ) ) . $m[2];
			},
			$html
		);
		return is_string( $result ) ? $result : $html;
	}

	private function is_internal( string $url ): bool {
		if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(mailto|tel|javascript|data):#i', $url ) ) {
			return false;
		}
		$home = untrailingslashit( (string) home_url() );
		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( ! str_starts_with( $url, '/' ) && ! str_starts_with( set_url_scheme( $url, 'https' ), set_url_scheme( $home, 'https' ) ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/(wp-admin|wp-login\.php|wp-content|wp-includes)(/|$)#', $path ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' === $ext || in_array( $ext, array( 'php', 'html', 'htm' ), true );
	}

	public function current_url(): string {
		$base      = untrailingslashit( set_url_scheme( (string) get_option( 'home' ) ) );
		$home_path = (string) wp_parse_url( $base, PHP_URL_PATH );
		$uri       = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( '' !== $home_path && str_starts_with( $uri, $home_path ) ) {
			$uri = substr( $uri, strlen( $home_path ) );
		}
		return $base . '/' . ltrim( $uri, '/' );
	}

	/**
	 * Garde-fou côté navigateur : bloque les soumissions et les POST fetch/XHR.
	 */
	public function enqueue_guard(): void {
		wp_register_script( 'lmv-preview-guard', '', array(), LMV_VERSION, false );
		wp_enqueue_script( 'lmv-preview-guard' );
		wp_add_inline_script( 'lmv-preview-guard', $this->guard_script() );
	}

	private function guard_script(): string {
		$message = wp_json_encode( __( 'Aperçu : les envois de formulaires et les commandes sont désactivés.', 'lumia-staging' ) );
		return '(function(){var m=' . $message . ';function t(){if(window.lmvToast){window.lmvToast(m);}else{window.alert(m);}}'
			. 'function mine(e){return e&&e.closest&&e.closest("lmv-preview-bar");}'
			. 'document.addEventListener("submit",function(e){if(mine(e.target))return;e.preventDefault();e.stopImmediatePropagation();t();},true);'
			. 'document.addEventListener("click",function(e){var b=e.target&&e.target.closest&&e.target.closest("button[type=submit],input[type=submit],.bricks-form button,[name=woocommerce_checkout_place_order]");if(b&&!mine(b)){e.preventDefault();e.stopImmediatePropagation();t();}},true);'
			. 'var f=window.fetch;if(f){window.fetch=function(i,o){var meth=((o&&o.method)||(i&&i.method)||"GET").toUpperCase();var u=String(i&&i.url||i);if(meth!=="GET"&&u.indexOf("lmv_action")<0&&!(o&&o.lmvFeedback)){t();return Promise.reject(new Error(m));}return f.apply(this,arguments);};}'
			. 'var x=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(meth){this.__lmvBlock=String(meth).toUpperCase()!=="GET";return x.apply(this,arguments);};'
			. 'var s=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.send=function(){if(this.__lmvBlock){t();this.abort();return;}return s.apply(this,arguments);};'
			. '})();';
	}

	public function enqueue_toolbar(): void {
		$context = $this->context;
		if ( null === $context ) {
			return;
		}
		$current = $this->current_url();
		$latest  = $this->feedback->latest( $context->version_id );
		wp_enqueue_script( 'lmv-preview-toolbar', LMV_URL . 'assets/preview/toolbar.js', array(), LMV_VERSION, true );
		wp_add_inline_script(
			'lmv-preview-toolbar',
			'window.lmvPreview=' . wp_json_encode(
				array(
					'title'      => get_the_title( $context->source_id ),
					'isTemplate' => $this->bricks->is_template( $context->source_id ),
					'side'       => $context->live ? 'before' : 'after',
					'beforeUrl'  => add_query_arg( 'lmv_side', 'before', $current ),
					'afterUrl'   => remove_query_arg( 'lmv_side', $current ),
					'postUrl'    => remove_query_arg( 'lmv_side', $current ),
					'nonce'      => wp_create_nonce( 'lmv_feedback_' . $context->token_id ),
					'expires'    => mysql_to_rfc3339( $context->expires_at ),
					'decision'   => null !== $latest ? $latest : null,
					'i18n'       => array(
						'label'      => __( 'Aperçu de la nouvelle version', 'lumia-staging' ),
						'template'   => __( 'Aperçu du template sur cette page', 'lumia-staging' ),
						'before'     => __( 'Avant', 'lumia-staging' ),
						'after'      => __( 'Après', 'lumia-staging' ),
						'approve'    => __( 'Valider', 'lumia-staging' ),
						'changes'    => __( 'Demander des modifications', 'lumia-staging' ),
						'name'       => __( 'Votre nom', 'lumia-staging' ),
						'comment'    => __( 'Votre commentaire', 'lumia-staging' ),
						'commentReq' => __( 'Décrivez les modifications souhaitées', 'lumia-staging' ),
						'send'       => __( 'Envoyer', 'lumia-staging' ),
						'cancel'     => __( 'Annuler', 'lumia-staging' ),
						'collapse'   => __( 'Réduire la barre', 'lumia-staging' ),
						'expand'     => __( 'Afficher la barre d\'aperçu', 'lumia-staging' ),
						'thanksOk'   => __( 'Merci, votre validation a bien été envoyée.', 'lumia-staging' ),
						'thanksKo'   => __( 'Merci, votre demande a bien été envoyée.', 'lumia-staging' ),
						/* translators: %s: client name */
						'approved'   => __( 'Validée par %s', 'lumia-staging' ),
						/* translators: %s: client name */
						'requested'  => __( 'Modifications demandées par %s', 'lumia-staging' ),
						'error'      => __( 'L\'envoi a échoué. Réessayez.', 'lumia-staging' ),
						/* translators: %s: date */
						'expiresOn'  => __( 'Lien valable jusqu\'au %s', 'lumia-staging' ),
					),
				)
			) . ';',
			'before'
		);
	}
}

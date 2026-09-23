<?php
/**
 * Mises à jour depuis les releases GitHub, via le système natif de WordPress
 * (en-tête `Update URI` → filtre `update_plugins_github.com`).
 *
 * - Canal stable (releases) ou dev (pré-releases `-dev.N` publiées à chaque
 *   push sur la branche dev), dans les réglages. Le canal dev reçoit aussi
 *   les stables plus récentes.
 * - L'archive est vérifiée par SHA256 (fichier SHA256SUMS de la release)
 *   avant installation ; en cas d'écart, la mise à jour est refusée.
 * - Réponse GitHub en cache 12 h, échec en cache 15 min : sans cache négatif,
 *   un GitHub injoignable ou le quota anonyme (60 req/h) relancerait un appel
 *   de 10 s à chaque écran d'administration.
 * - Dépôt privé : jeton en lecture seule dans la constante LMV_GITHUB_TOKEN,
 *   jamais en base de données.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Update;

use Lumia\Staging\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Note array{version: string, date: string, html: string}
 * @phpstan-type Release array{version: string, package: string, zip_name: string, sums: string, url: string, date: string, notes: list<Note>}
 */
final class GitHubUpdater {

	public const CACHE     = 'lmv_github_release';
	private const SLUG     = 'lumia-staging';
	private const FAILED   = 'failed';
	private const TTL      = 12 * HOUR_IN_SECONDS;
	private const TTL_FAIL = 15 * MINUTE_IN_SECONDS;
	private const NOTES    = 10;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action(
			'update_option_' . Settings::OPTION,
			function ( $before, $after ): void {
				$channel = static fn( $v ): string => is_array( $v ) ? (string) ( $v['update_channel'] ?? '' ) : '';
				if ( $channel( $before ) !== $channel( $after ) ) {
					$this->flush();
				}
			},
			10,
			2
		);
	}

	private function repo(): string {
		$repo = defined( 'LMV_GITHUB_REPO' ) ? (string) constant( 'LMV_GITHUB_REPO' ) : 'agence-lumia/lumia-staging';
		return (string) apply_filters( 'lmv_github_repo', $repo );
	}

	private function channel(): string {
		return 'dev' === $this->settings->get( 'update_channel' ) ? 'dev' : 'stable';
	}

	private function cache_key(): string {
		return self::CACHE . '_' . $this->channel();
	}

	/**
	 * Vide les caches : le nôtre et celui de WordPress, pour que la pastille
	 * « mise à jour disponible » reflète tout de suite le nouvel état.
	 */
	public function flush(): void {
		delete_site_transient( self::CACHE . '_stable' );
		delete_site_transient( self::CACHE . '_dev' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * @param object               $upgrader
	 * @param array<string, mixed> $options
	 */
	public function after_upgrade( $upgrader, array $options ): void {
		unset( $upgrader );
		if ( 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}
		if ( in_array( plugin_basename( LMV_FILE ), (array) ( $options['plugins'] ?? array() ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function headers( string $accept = 'application/vnd.github+json' ): array {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'lumia-staging/' . LMV_VERSION,
		);
		if ( defined( 'LMV_GITHUB_TOKEN' ) && '' !== (string) constant( 'LMV_GITHUB_TOKEN' ) ) {
			$headers['Authorization'] = 'Bearer ' . (string) constant( 'LMV_GITHUB_TOKEN' );
		}
		return $headers;
	}

	/**
	 * Release la plus récente du canal choisi.
	 *
	 * @return Release|null
	 */
	public function release(): ?array {
		$cached = get_site_transient( $this->cache_key() );
		if ( self::FAILED === $cached ) {
			return null;
		}
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			/** @var Release $cached */
			return $cached;
		}

		// Variante « html » : GitHub renvoie les notes déjà rendues (body_html),
		// pas de parseur Markdown à embarquer.
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo() . '/releases?per_page=30',
			array(
				'timeout' => 10,
				'headers' => $this->headers( 'application/vnd.github.html+json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( $this->cache_key(), self::FAILED, self::TTL_FAIL );
			return null;
		}
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
		$releases = self::eligible( is_array( $body ) ? $body : array(), 'dev' === $this->channel() );
		if ( array() === $releases ) {
			set_site_transient( $this->cache_key(), self::FAILED, self::TTL_FAIL );
			return null;
		}

		$found          = $releases[0];
		$found['notes'] = array_map(
			static fn( array $r ): array => $r['notes'][0],
			array_slice( $releases, 0, self::NOTES )
		);
		set_site_transient( $this->cache_key(), $found, self::TTL );
		return $found;
	}

	/**
	 * Releases installables (ZIP + SHA256SUMS), de la plus récente à la plus
	 * ancienne selon la version, pas selon l'ordre de l'API.
	 *
	 * @param array<mixed> $releases Réponse de l'API GitHub.
	 * @return list<Release>
	 */
	public static function eligible( array $releases, bool $dev ): array {
		$out = array();
		foreach ( $releases as $rel ) {
			if ( ! is_array( $rel ) || ! empty( $rel['draft'] ) || ( ! $dev && ! empty( $rel['prerelease'] ) ) ) {
				continue;
			}
			$version = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'v' );
			if ( ! preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version ) ) {
				continue;
			}
			$zip  = null;
			$sums = null;
			foreach ( (array) ( $rel['assets'] ?? array() ) as $asset ) {
				$name = is_array( $asset ) ? (string) ( $asset['name'] ?? '' ) : '';
				if ( preg_match( '/^lumia-staging-.*\.zip$/', $name ) ) {
					$zip = $asset;
				} elseif ( 'SHA256SUMS' === $name ) {
					$sums = $asset;
				}
			}
			if ( null === $zip || null === $sums ) {
				continue;
			}
			$date  = (string) ( $rel['published_at'] ?? '' );
			$out[] = array(
				'version'  => $version,
				'package'  => (string) ( $zip['url'] ?? '' ),
				'zip_name' => (string) ( $zip['name'] ?? '' ),
				'sums'     => (string) ( $sums['url'] ?? '' ),
				'url'      => (string) ( $rel['html_url'] ?? '' ),
				'date'     => $date,
				'notes'    => array(
					array(
						'version' => $version,
						'date'    => $date,
						'html'    => (string) ( $rel['body_html'] ?? '' ),
					),
				),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => version_compare( $b['version'], $a['version'] ) );
		return $out;
	}

	/**
	 * État connu, sans appel réseau (le cache seul) : l'écran des réglages ne
	 * doit jamais attendre GitHub.
	 *
	 * @return array{channel: string, installed: string, remote: ?string, has_update: bool}
	 */
	public function status(): array {
		$cached = get_site_transient( $this->cache_key() );
		$remote = is_array( $cached ) && isset( $cached['version'] ) ? (string) $cached['version'] : null;
		return array(
			'channel'    => $this->channel(),
			'installed'  => LMV_VERSION,
			'remote'     => $remote,
			'has_update' => null !== $remote && version_compare( $remote, LMV_VERSION, '>' ),
		);
	}

	/**
	 * Vérification forcée (bouton des réglages) : contourne les caches puis
	 * relance la vérification de WordPress.
	 *
	 * @return array{channel: string, installed: string, remote: ?string, has_update: bool}
	 */
	public function check_now(): array {
		$this->flush();
		$this->release();
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		return $this->status();
	}

	/**
	 * @param array<string, mixed>|false $update
	 * @param array<string, mixed>       $plugin_data
	 * @return array<string, mixed>|false
	 */
	public function check( $update, array $plugin_data, string $plugin_file ) {
		unset( $plugin_data );
		if ( plugin_basename( LMV_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $update;
		}
		// Version égale ou inférieure : WordPress range l'entrée dans no_update,
		// ce qui affiche quand même la colonne « Mises à jour auto ».
		return array(
			'id'           => 'github.com/' . $this->repo(),
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => version_compare( $release['version'], LMV_VERSION, '>' ) ? $release['package'] : '',
			'requires'     => LMV_MIN_WP,
			'requires_php' => LMV_MIN_PHP,
		);
	}

	/**
	 * Fiche de détails : toutes les notes postérieures à la version installée
	 * (une mise à jour saute souvent plusieurs pré-versions).
	 *
	 * @param false|object|array<string, mixed> $result
	 * @return false|object|array<string, mixed>
	 */
	public function details( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Lümia Staging',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://studiokyne.com">Agence Lümia</a>',
			'homepage'      => 'https://github.com/' . $this->repo(),
			'requires'      => LMV_MIN_WP,
			'requires_php'  => LMV_MIN_PHP,
			'last_updated'  => $release['date'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Versions de travail pour les pages et templates Bricks.', 'lumia-staging' ),
				'changelog'   => $this->changelog( $release['notes'] ),
			),
		);
	}

	/**
	 * @param list<Note> $notes
	 */
	private function changelog( array $notes ): string {
		$newer = array_values( array_filter( $notes, static fn( array $n ): bool => version_compare( $n['version'], LMV_VERSION, '>' ) ) );
		if ( array() === $newer ) {
			$newer = array_slice( $notes, 0, 1 );
		}
		if ( array() === $newer ) {
			return '<p>' . esc_html__( 'Aucune note de version disponible.', 'lumia-staging' ) . '</p>';
		}
		$html = '';
		foreach ( $newer as $note ) {
			$date  = '' !== $note['date'] ? (string) mysql2date( (string) get_option( 'date_format' ), $note['date'] ) : '';
			$html .= '<h4>' . esc_html( 'v' . $note['version'] ) . ( '' !== $date ? ' — ' . esc_html( $date ) : '' ) . '</h4>';
			// HTML rendu par GitHub : filtré ici, puis de nouveau par WordPress.
			$html .= wp_kses_post( $note['html'] );
		}
		return $html;
	}

	/**
	 * Télécharge l'archive (jeton éventuel), puis vérifie son SHA256.
	 *
	 * @param bool|string|\WP_Error $reply
	 * @return bool|string|\WP_Error
	 */
	public function download( $reply, string $package, object $upgrader ) {
		unset( $upgrader );
		$prefix = 'https://api.github.com/repos/' . $this->repo() . '/releases/assets/';
		if ( ! str_starts_with( $package, $prefix ) ) {
			return $reply;
		}
		$release = $this->release();
		if ( null === $release || $release['package'] !== $package ) {
			return new \WP_Error( 'lmv_update', __( 'Release introuvable : réessayez plus tard.', 'lumia-staging' ) );
		}

		$file = $this->fetch_asset( $package, true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$sums = $this->fetch_asset( $release['sums'], false );
		if ( is_wp_error( $sums ) ) {
			wp_delete_file( $file );
			return $sums;
		}
		$expected = self::expected_sum( $sums, $release['zip_name'] );
		$actual   = (string) hash_file( 'sha256', $file );
		if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'lmv_checksum', __( 'Mise à jour refusée : l\'empreinte SHA256 de l\'archive ne correspond pas à celle publiée avec la release.', 'lumia-staging' ) );
		}
		return $file;
	}

	/**
	 * Empreinte attendue pour un fichier, au format de `sha256sum`.
	 */
	public static function expected_sum( string $sums, string $file_name ): string {
		$expected = '';
		$lines    = preg_split( '/\R/', $sums );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^([a-f0-9]{64})\s+\*?(.+)$/i', trim( $line ), $m ) && basename( trim( $m[2] ) ) === $file_name ) {
				$expected = strtolower( $m[1] );
			}
		}
		return $expected;
	}

	/**
	 * Les assets GitHub redirigent vers un stockage signé qui refuse l'en-tête
	 * Authorization : on suit la redirection à la main, sans le jeton.
	 *
	 * @return string|\WP_Error Chemin du fichier ($to_file) ou contenu.
	 */
	private function fetch_asset( string $url, bool $to_file ): string|\WP_Error {
		$first = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		$code = (int) wp_remote_retrieve_response_code( $first );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $first, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : $location;
			$args     = array( 'timeout' => 300 );
			if ( $to_file ) {
				$args['stream']   = true;
				$args['filename'] = wp_tempnam( 'lumia-staging.zip' );
			}
			$second = wp_remote_get( $location, $args );
			if ( is_wp_error( $second ) || 200 !== wp_remote_retrieve_response_code( $second ) ) {
				return new \WP_Error( 'lmv_update', __( 'Téléchargement de la mise à jour impossible.', 'lumia-staging' ) );
			}
			return $to_file ? (string) $args['filename'] : wp_remote_retrieve_body( $second );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'lmv_update', __( 'Téléchargement de la mise à jour impossible (accès au dépôt refusé ?).', 'lumia-staging' ) );
		}
		if ( ! $to_file ) {
			return wp_remote_retrieve_body( $first );
		}
		$path = wp_tempnam( 'lumia-staging.zip' );
		file_put_contents( $path, wp_remote_retrieve_body( $first ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}
}

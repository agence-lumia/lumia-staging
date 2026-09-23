<?php
/**
 * API interne `lumia-staging/v1` utilisée par l'interface.
 *
 * Chaque route a un permission_callback : capacité `lmv_*` ET droit de
 * modifier le contenu concerné (`edit_post` sur l'original). Le nonce REST
 * (`X-WP-Nonce`) est exigé par WordPress pour toute requête authentifiée
 * par cookie. Aucune action d'écriture en GET.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Rest;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Preview\PreviewController;
use Lumia\Staging\Preview\TokenRepository;
use Lumia\Staging\Publish\ChangeSummary;
use Lumia\Staging\Publish\PublishPipeline;
use Lumia\Staging\Publish\ScheduleService;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Capabilities;
use Lumia\Staging\Support\Settings;
use Lumia\Staging\Update\GitHubUpdater;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class RestController {

	public const NS = 'lumia-staging/v1';

	public function __construct(
		private VersionService $versions,
		private PublishPipeline $pipeline,
		private ScheduleService $schedule,
		private ChangeSummary $summary,
		private SnapshotRepository $snapshots,
		private TokenRepository $tokens,
		private PreviewController $preview,
		private Presenter $presenter,
		private BricksAdapter $bricks,
		private Settings $settings,
		private Logger $logger,
		private GitHubUpdater $updater
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		$this->route( '/versions', WP_REST_Server::READABLE, 'list_versions', fn() => current_user_can( Capabilities::CREATE ) );
		$this->route(
			'/versions',
			WP_REST_Server::CREATABLE,
			'create_version',
			fn( WP_REST_Request $r ) => $this->can_source( Capabilities::CREATE, (int) $r['source_id'] ),
			array(
				'source_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'note'      => array( 'type' => 'string' ),
			)
		);
		$this->route( '/versions/(?P<id>\d+)', WP_REST_Server::READABLE, 'get_version', $this->can_version( Capabilities::CREATE ), $id );
		$this->route( '/versions/(?P<id>\d+)/state', WP_REST_Server::READABLE, 'version_state', fn() => current_user_can( Capabilities::CREATE ), $id );
		$this->route( '/versions/(?P<id>\d+)/note', WP_REST_Server::CREATABLE, 'set_note', $this->can_version( Capabilities::CREATE ), $id + array( 'note' => array( 'type' => 'string' ) ) );
		$this->route( '/versions/(?P<id>\d+)/abandon', WP_REST_Server::CREATABLE, 'abandon', $this->can_version( Capabilities::CREATE ), $id + array( 'confirm' => array( 'type' => 'boolean' ) ) );
		$this->route( '/versions/(?P<id>\d+)/export', WP_REST_Server::READABLE, 'export', $this->can_version( Capabilities::CREATE ), $id );
		$this->route( '/versions/(?P<id>\d+)/summary', WP_REST_Server::READABLE, 'summary', $this->can_version( Capabilities::PUBLISH ), $id );
		$this->route(
			'/versions/(?P<id>\d+)/publish',
			WP_REST_Server::CREATABLE,
			'publish',
			$this->can_version( Capabilities::PUBLISH ),
			$id + array(
				'note'  => array( 'type' => 'string' ),
				'force' => array( 'type' => 'boolean' ),
			)
		);
		$this->route(
			'/versions/(?P<id>\d+)/schedule',
			WP_REST_Server::CREATABLE,
			'schedule',
			$this->can_version( Capabilities::PUBLISH ),
			$id + array(
				'datetime' => array(
					'type'     => 'string',
					'required' => true,
				),
				'note'     => array( 'type' => 'string' ),
			)
		);
		$this->route( '/versions/(?P<id>\d+)/schedule', WP_REST_Server::DELETABLE, 'unschedule', $this->can_version( Capabilities::PUBLISH ), $id );
		$this->route(
			'/versions/(?P<id>\d+)/share',
			WP_REST_Server::CREATABLE,
			'share',
			$this->can_version( Capabilities::SHARE ),
			$id + array(
				'days'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 30,
				),
				'on_page' => array( 'type' => 'integer' ),
			)
		);
		$this->route( '/versions/(?P<id>\d+)/share/(?P<token>\d+)', WP_REST_Server::DELETABLE, 'revoke', $this->can_version( Capabilities::SHARE ), $id );
		$this->route(
			'/batches',
			WP_REST_Server::CREATABLE,
			'create_batch',
			array( $this, 'can_batch' ),
			array(
				'version_ids' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'required' => true,
				),
				'datetime'    => array( 'type' => 'string' ),
				'note'        => array( 'type' => 'string' ),
			)
		);
		$this->route( '/batches/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'cancel_batch', fn() => current_user_can( Capabilities::PUBLISH ), $id );
		$this->route( '/posts/(?P<id>\d+)/status', WP_REST_Server::READABLE, 'post_status', fn( WP_REST_Request $r ) => $this->can_source( Capabilities::CREATE, (int) $r['id'] ), $id );
		$this->route( '/posts/(?P<id>\d+)/history', WP_REST_Server::READABLE, 'history', fn( WP_REST_Request $r ) => $this->can_source( Capabilities::RESTORE, (int) $r['id'] ), $id );
		$this->route( '/snapshots/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore', array( $this, 'can_snapshot' ), $id + array( 'confirm' => array( 'type' => 'boolean' ) ) );
		$this->route(
			'/compare',
			WP_REST_Server::READABLE,
			'compare',
			array( $this, 'can_compare' ),
			array(
				'version'  => array( 'type' => 'integer' ),
				'snapshot' => array( 'type' => 'integer' ),
				'on_page'  => array( 'type' => 'integer' ),
			)
		);
		$this->route( '/pages', WP_REST_Server::READABLE, 'pages', fn() => current_user_can( Capabilities::CREATE ), array( 'search' => array( 'type' => 'string' ) ) );
		$this->route( '/log', WP_REST_Server::READABLE, 'log', fn() => current_user_can( Capabilities::SETTINGS ) );
		$this->route( '/settings', WP_REST_Server::READABLE, 'get_settings', fn() => current_user_can( Capabilities::SETTINGS ) );
		$this->route( '/settings', WP_REST_Server::CREATABLE, 'update_settings', fn() => current_user_can( Capabilities::SETTINGS ) );
		$this->route( '/updates/check', WP_REST_Server::CREATABLE, 'check_updates', fn() => current_user_can( Capabilities::SETTINGS ) && current_user_can( 'update_plugins' ) );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Versions                                                            */
	/* ------------------------------------------------------------------ */

	public function list_versions( WP_REST_Request $request ): WP_REST_Response {
		$ids   = $this->versions->list_ids(
			array(
				'state'  => sanitize_key( (string) $request['state'] ),
				'search' => sanitize_text_field( (string) $request['search'] ),
			)
		);
		$items = array();
		foreach ( $ids as $version_id ) {
			if ( current_user_can( 'edit_post', $this->versions->source_id( $version_id ) ) || ! get_post_status( $this->versions->source_id( $version_id ) ) ) {
				$items[] = $this->presenter->version( $version_id );
			}
		}
		return new WP_REST_Response(
			array(
				'items'   => $items,
				'batches' => $this->schedule->upcoming_batches(),
			)
		);
	}

	public function create_version( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id = $this->versions->create( (int) $request['source_id'], (string) $request['note'] );
				return array(
					'id'       => $version_id,
					'edit_url' => $this->bricks->builder_url( $version_id ),
					'version'  => $this->presenter->version( $version_id ),
				);
			},
			201
		);
	}

	public function get_version( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard( fn() => $this->presenter->version( (int) $request['id'], true ) );
	}

	public function version_state( WP_REST_Request $request ): WP_REST_Response {
		$version_id = (int) $request['id'];
		if ( ! $this->versions->is_version( $version_id ) ) {
			return new WP_REST_Response( array( 'exists' => false ) );
		}
		return new WP_REST_Response(
			array(
				'exists' => true,
				'state'  => $this->versions->state( $version_id )->value,
			)
		);
	}

	public function set_note( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$this->versions->set_note( (int) $request['id'], (string) $request['note'] );
				return array( 'note' => $this->versions->note( (int) $request['id'] ) );
			}
		);
	}

	public function abandon( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$this->versions->abandon( (int) $request['id'], (bool) $request['confirm'] );
				return array( 'abandoned' => true );
			}
		);
	}

	public function export( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard( fn() => $this->versions->export( (int) $request['id'] ) );
	}

	public function summary( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id = (int) $request['id'];
				return array_merge(
					$this->summary->for_version( $version_id ),
					array( 'version' => $this->presenter->version( $version_id, true ) )
				);
			}
		);
	}

	public function publish( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id = (int) $request['id'];
				$source_id  = $this->versions->source_id( $version_id );
				$result     = $this->pipeline->publish(
					array( $version_id ),
					array(
						'note'  => (string) $request['note'],
						'force' => (bool) $request['force'],
					)
				);
				$item       = $result['items'][0];
				return array(
					'post_id'     => $source_id,
					'snapshot_id' => $item['snapshot_id'],
					'undo_until'  => gmdate( 'c', time() + 30 ),
					'warnings'    => $result['warnings'],
					'live_url'    => (string) get_permalink( $source_id ),
					'edit_url'    => $this->bricks->builder_url( $source_id ),
				);
			}
		);
	}

	public function schedule( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$timestamp = ScheduleService::parse_site_datetime( (string) $request['datetime'] );
				$this->schedule->schedule_version( (int) $request['id'], $timestamp, (string) $request['note'] );
				return $this->presenter->version( (int) $request['id'] );
			}
		);
	}

	public function unschedule( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$this->schedule->unschedule_version( (int) $request['id'] );
				return $this->presenter->version( (int) $request['id'] );
			}
		);
	}

	public function share( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id = (int) $request['id'];
				$this->versions->assert_version( $version_id );
				$days    = $request['days'] ? (int) $request['days'] : $this->settings->int( 'preview_days' );
				$on_page = (int) $request['on_page'];
				$token   = $this->tokens->create( $version_id, $days, array( 'on_page' => $on_page ) );
				if ( State::InProgress === $this->versions->state( $version_id ) ) {
					$this->versions->transition( $version_id, State::InReview );
				}
				$this->logger->log( 'preview_shared', $this->versions->source_id( $version_id ), $version_id, sprintf( 'Lien d\'aperçu client créé (valable %d jours).', $days ) );
				return array(
					'url'        => $this->preview->client_url( $version_id, $token['token'], $on_page ),
					'token_id'   => $token['id'],
					'expires_at' => mysql_to_rfc3339( $token['expires_at'] ),
					'version'    => $this->presenter->version( $version_id, true ),
				);
			},
			201
		);
	}

	public function revoke( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id = (int) $request['id'];
				$this->tokens->revoke( (int) $request['token'], $version_id );
				$this->logger->log( 'preview_revoked', $this->versions->source_id( $version_id ), $version_id, 'Lien d\'aperçu client révoqué.' );
				return $this->presenter->version( $version_id, true );
			}
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	Lots                                                                */
	/* ------------------------------------------------------------------ */

	public function create_batch( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$datetime  = (string) $request['datetime'];
				$timestamp = '' !== $datetime ? ScheduleService::parse_site_datetime( $datetime ) : null;
				$result    = $this->schedule->create_batch( array_values( array_map( 'intval', (array) $request['version_ids'] ) ), $timestamp, (string) $request['note'] );
				$published = null !== $result['result'] ? $result['result']['items'] : array();
				return array(
					'batch_id'  => $result['batch_id'],
					'scheduled' => null !== $timestamp,
					'published' => array_map(
						static fn( $item ) => array(
							'post_id'     => $item['post_id'],
							'snapshot_id' => $item['snapshot_id'],
							'title'       => $item['title'],
						),
						$published
					),
				);
			}
		);
	}

	public function cancel_batch( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$this->schedule->cancel_batch( (int) $request['id'] );
				return array( 'cancelled' => true );
			}
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	Contenus, historique                                                */
	/* ------------------------------------------------------------------ */

	public function post_status( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		if ( $this->versions->is_version( $post_id ) ) {
			return new WP_REST_Response(
				array(
					'is_version' => true,
					'version'    => $this->presenter->version( $post_id, true ),
				)
			);
		}
		$open = $this->versions->open_version_for( $post_id );
		return new WP_REST_Response(
			array(
				'is_version'   => false,
				'supported'    => $this->versions->supports( $post_id ),
				'open_version' => $open > 0 ? $this->presenter->version( $open ) : null,
			)
		);
	}

	public function history( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$open    = $this->versions->open_version_for( $post_id );
		return new WP_REST_Response(
			array(
				'post'         => array(
					'id'       => $post_id,
					'title'    => get_the_title( $post_id ),
					'url'      => get_permalink( $post_id ),
					'edit_url' => $this->bricks->builder_url( $post_id ),
				),
				'open_version' => $open > 0 ? $this->presenter->version( $open ) : null,
				'items'        => $this->presenter->history( $post_id, $this->snapshots->history( $post_id ) ),
			)
		);
	}

	public function restore( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$backup = $this->pipeline->restore( (int) $request['id'], (bool) $request['confirm'] );
				return array(
					'snapshot_id' => $backup,
					'undo_until'  => gmdate( 'c', time() + 30 ),
				);
			}
		);
	}

	/**
	 * F4 : URLs signées des deux côtés et résumé structurel.
	 */
	public function compare( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ) {
				$version_id  = (int) $request['version'];
				$snapshot_id = (int) $request['snapshot'];
				$on_page     = (int) $request['on_page'];

				if ( $version_id > 0 ) {
					$source_id = $this->versions->source_id( $version_id );
					$base      = $this->preview->base_url( $source_id, $on_page );
					$summary   = $this->summary->for_version( $version_id );
					$title     = get_the_title( $source_id );
					$right     = $this->preview->signed_url( $base, $version_id, 0, false );
					$labels    = array( __( 'En ligne', 'lumia-staging' ), __( 'Version de travail', 'lumia-staging' ) );
				} else {
					$snapshot = $this->snapshots->get( $snapshot_id );
					if ( null === $snapshot ) {
						throw new VersionException( __( 'Sauvegarde introuvable.', 'lumia-staging' ), 'lmv_not_found', 404 );
					}
					$source_id = (int) $snapshot['post_id'];
					$base      = $this->preview->base_url( $source_id, $on_page );
					$summary   = array(
						'diff'   => $this->summary->diff( $this->bricks->meta( $source_id ), array_map( 'maybe_unserialize', (array) ( $snapshot['data']['meta'] ?? array() ) ) ),
						'alerts' => array(),
					);
					$title     = get_the_title( $source_id );
					$right     = $this->preview->signed_url( $base, 0, $snapshot_id, false );
					$labels    = array(
						__( 'En ligne', 'lumia-staging' ),
						/* translators: %s: date */
						sprintf( __( 'Version en ligne jusqu\'au %s', 'lumia-staging' ), wp_date( 'd/m/Y H:i', (int) strtotime( $snapshot['created_at'] . ' UTC' ) ) ),
					);
				}
				return array(
					'title'       => $title,
					'source_id'   => $source_id,
					'is_template' => $this->bricks->is_template( $source_id ),
					'left'        => $this->preview->signed_url( $base, 0, 0, true ),
					'right'       => $right,
					'labels'      => $labels,
					'summary'     => $summary,
					'breakpoints' => $this->bricks->breakpoints(),
				);
			}
		);
	}

	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'page', 'post', 'product' ),
				'post_status'    => 'publish',
				's'              => sanitize_text_field( (string) $request['search'] ),
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = array(
					'id'    => $post->ID,
					'title' => $post->post_title,
					'type'  => $post->post_type,
				);
			}
		}
		return new WP_REST_Response( $items );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Journal, réglages                                                   */
	/* ------------------------------------------------------------------ */

	public function log( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			$this->logger->query(
				array(
					'action' => sanitize_key( (string) $request['action'] ),
					'level'  => sanitize_key( (string) $request['level'] ),
					'search' => sanitize_text_field( (string) $request['search'] ),
				),
				max( 1, (int) $request['page'] ),
				50
			)
		);
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings_payload() );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$this->settings->update( $params );
		if ( array_key_exists( 'auto_update', $params ) ) {
			$this->set_auto_update( (bool) $params['auto_update'] );
		}
		$this->logger->log( 'settings_updated', 0, 0, 'Réglages modifiés.' );
		return new WP_REST_Response( $this->settings_payload() );
	}

	public function check_updates(): WP_REST_Response {
		return new WP_REST_Response( $this->updater->check_now() );
	}

	private function can_auto_update(): bool {
		return current_user_can( 'update_plugins' ) && function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' );
	}

	/**
	 * La bascule écrit dans l'option native `auto_update_plugins`, celle de la
	 * colonne « Mises à jour auto » : une seule source de vérité.
	 */
	private function set_auto_update( bool $enabled ): void {
		if ( ! $this->can_auto_update() ) {
			return;
		}
		$plugin  = plugin_basename( LMV_FILE );
		$current = (array) get_site_option( 'auto_update_plugins', array() );
		$next    = array_values( array_diff( $current, array( $plugin ) ) );
		if ( $enabled ) {
			$next[] = $plugin;
		}
		if ( $next !== $current ) {
			update_site_option( 'auto_update_plugins', $next );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings_payload(): array {
		$open = array();
		foreach ( $this->versions->list_ids() as $version_id ) {
			$open[] = array(
				'id'    => $version_id,
				'title' => get_the_title( $version_id ),
				'state' => $this->versions->state( $version_id )->label(),
			);
		}
		return array(
			'settings'      => $this->settings->all(),
			'open_versions' => $open,
			'scheduler'     => function_exists( 'as_schedule_single_action' ) ? 'Action Scheduler' : 'WP-Cron',
			'bricks'        => $this->bricks->version(),
			'css_mode'      => $this->bricks->css_file_mode() ? 'file' : 'inline',
			'updates'       => $this->updater->status() + array(
				'auto_update'           => in_array( plugin_basename( LMV_FILE ), (array) get_site_option( 'auto_update_plugins', array() ), true ),
				'auto_update_available' => $this->can_auto_update(),
				'can_check'             => current_user_can( 'update_plugins' ),
			),
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	Permissions                                                         */
	/* ------------------------------------------------------------------ */

	private function can_source( string $cap, int $post_id ): bool {
		return current_user_can( $cap ) && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @return callable(WP_REST_Request): bool
	 */
	private function can_version( string $cap ): callable {
		return function ( WP_REST_Request $request ) use ( $cap ): bool {
			if ( ! current_user_can( $cap ) ) {
				return false;
			}
			$version_id = (int) $request['id'];
			if ( ! $this->versions->is_version( $version_id ) ) {
				return true; // Le handler renverra « version introuvable » (404).
			}
			$source_id = $this->versions->source_id( $version_id );
			// Version orpheline : l'original n'existe plus, seul le droit sur la version compte.
			return current_user_can( 'edit_post', $version_id ) && ( false === get_post_status( $source_id ) || current_user_can( 'edit_post', $source_id ) );
		};
	}

	public function can_batch( WP_REST_Request $request ): bool {
		if ( ! current_user_can( Capabilities::PUBLISH ) ) {
			return false;
		}
		foreach ( (array) $request['version_ids'] as $version_id ) {
			$source_id = $this->versions->source_id( (int) $version_id );
			if ( $source_id > 0 && ! current_user_can( 'edit_post', $source_id ) ) {
				return false;
			}
		}
		return true;
	}

	public function can_snapshot( WP_REST_Request $request ): bool {
		return $this->snapshot_allowed( (int) $request['id'] );
	}

	public function can_compare( WP_REST_Request $request ): bool {
		if ( (int) $request['version'] > 0 ) {
			return current_user_can( Capabilities::CREATE ) && ( ! $this->versions->is_version( (int) $request['version'] ) || current_user_can( 'edit_post', $this->versions->source_id( (int) $request['version'] ) ) );
		}
		return $this->snapshot_allowed( (int) $request['snapshot'] );
	}

	private function snapshot_allowed( int $snapshot_id ): bool {
		if ( ! current_user_can( Capabilities::RESTORE ) ) {
			return false;
		}
		$snapshot = $this->snapshots->get( $snapshot_id );
		return null === $snapshot || current_user_can( 'edit_post', (int) $snapshot['post_id'] );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Outils                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param non-falsy-string                    $path
	 * @param array<string, array<string, mixed>> $args
	 */
	private function route( string $path, string $methods, string $handler, callable $permission, array $args = array() ): void {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $handler ),
				'permission_callback' => $permission,
				'args'                => $args,
			),
			false
		);
	}

	/**
	 * Convertit les erreurs métier en réponses REST lisibles.
	 *
	 * @param callable(): array<string, mixed> $operation
	 */
	private function guard( callable $operation, int $status = 200 ): WP_REST_Response|\WP_Error {
		try {
			return new WP_REST_Response( $operation(), $status );
		} catch ( VersionException $e ) {
			return $e->to_wp_error();
		}
	}
}

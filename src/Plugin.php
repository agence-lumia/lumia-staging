<?php
/**
 * Démarrage : conteneur de services et branchement des hooks.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Adapter\CacheAdapter;
use Lumia\Staging\Adapter\WooAdapter;
use Lumia\Staging\Admin\Admin;
use Lumia\Staging\Admin\AdminBar;
use Lumia\Staging\Builder\BuilderIntegration;
use Lumia\Staging\Cli\CliCommand;
use Lumia\Staging\Diff\ReferenceReplacer;
use Lumia\Staging\Front\FrontBanner;
use Lumia\Staging\Install\Schema;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Notify\Mailer;
use Lumia\Staging\Post\PostStatus;
use Lumia\Staging\Preview\FeedbackRepository;
use Lumia\Staging\Preview\PreviewController;
use Lumia\Staging\Preview\RateLimiter;
use Lumia\Staging\Preview\TokenRepository;
use Lumia\Staging\Publish\ChangeSummary;
use Lumia\Staging\Publish\PublishPipeline;
use Lumia\Staging\Publish\RecoveryService;
use Lumia\Staging\Publish\ScheduleService;
use Lumia\Staging\Rest\Presenter;
use Lumia\Staging\Rest\RestController;
use Lumia\Staging\Scheduler\Scheduler;
use Lumia\Staging\Service\ConflictDetector;
use Lumia\Staging\Service\ContentCopier;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Lock;
use Lumia\Staging\Support\Settings;
use Lumia\Staging\Support\Transaction;
use Lumia\Staging\Update\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Container $container = null;

	public static function container(): Container {
		if ( null === self::$container ) {
			self::$container = self::build();
		}
		return self::$container;
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public static function get( string $id ): object {
		return self::container()->get( $id );
	}

	/**
	 * plugins_loaded (priorité 5).
	 */
	public static function boot(): void {
		$c = self::container();

		load_plugin_textdomain( 'lumia-staging', false, dirname( plugin_basename( LMV_FILE ) ) . '/languages' );

		// Toujours actif, même sans Bricks : les versions restent invisibles.
		$c->get( PostStatus::class )->register();
		WooAdapter::declare_compatibility( LMV_FILE );
		$c->get( GitHubUpdater::class )->register();

		// Aperçu : doit intervenir avant l'identification de l'utilisateur.
		$c->get( PreviewController::class )->boot();

		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ), 1 );
		add_action( 'after_setup_theme', array( self::class, 'boot_features' ), 20 );
	}

	/**
	 * Les fonctions demandant Bricks démarrent après le chargement du thème.
	 */
	public static function boot_features(): void {
		$c = self::container();
		if ( ! $c->get( BricksAdapter::class )->is_available() ) {
			add_action( 'admin_notices', array( self::class, 'missing_bricks_notice' ) );
			return;
		}

		$c->get( RestController::class )->register();
		$c->get( ScheduleService::class )->register();
		$c->get( Admin::class )->register();
		$c->get( AdminBar::class )->register();
		$c->get( BuilderIntegration::class )->register();
		$c->get( FrontBanner::class )->register();

		add_action( Scheduler::DAILY_HOOK, array( self::class, 'daily_cleanup' ) );
		add_action( 'wp_trash_post', array( self::class, 'on_trash' ) );
		add_action( 'before_delete_post', array( self::class, 'on_trash' ) );
		add_action( 'untrashed_post', array( self::class, 'on_untrash' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'lmv', $c->get( CliCommand::class ) );
		}
	}

	public static function missing_bricks_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Lümia Staging a besoin du thème Bricks (ou d\'un thème enfant de Bricks) pour fonctionner. Les versions de travail existantes sont conservées et restent invisibles.', 'lumia-staging' ) . '</p></div>';
	}

	/**
	 * R11 : original à la corbeille → versions orphelines.
	 */
	public static function on_trash( int $post_id ): void {
		$versions = self::get( VersionService::class );
		if ( $versions->is_version( $post_id ) ) {
			return;
		}
		$versions->mark_orphans( $post_id, true );
	}

	public static function on_untrash( int $post_id ): void {
		self::get( VersionService::class )->mark_orphans( $post_id, false );
	}

	/**
	 * Tâche quotidienne : rétention, journal, jetons, reprise.
	 */
	public static function daily_cleanup(): void {
		$c        = self::container();
		$settings = $c->get( Settings::class );
		$c->get( RecoveryService::class )->run();
		$c->get( SnapshotRepository::class )->cleanup( $settings->int( 'retention_count' ), $settings->int( 'retention_days' ) );
		$c->get( Logger::class )->purge( $settings->int( 'log_months' ) );
		$c->get( FeedbackRepository::class )->purge( $settings->int( 'log_months' ) );
		$c->get( TokenRepository::class )->purge_expired();
	}

	private static function build(): Container {
		global $wpdb;
		$c = new Container();

		$c->set( Settings::class, static fn() => new Settings() );
		$c->set( Lock::class, static fn() => new Lock( $wpdb ) );
		$c->set( Transaction::class, static fn() => new Transaction( $wpdb ) );
		$c->set( Logger::class, static fn() => new Logger( $wpdb ) );
		$c->set( PostStatus::class, static fn() => new PostStatus() );
		$c->set( BricksAdapter::class, static fn() => new BricksAdapter() );
		$c->set( CacheAdapter::class, static fn( Container $c ) => new CacheAdapter( $c->get( BricksAdapter::class ) ) );
		$c->set( ReferenceReplacer::class, static fn() => new ReferenceReplacer( array_values( array_map( 'strval', (array) apply_filters( 'lmv_reference_keys', array( 'postId', 'post_id', 'pageId', 'page_id', 'objectId', 'templateId' ) ) ) ) ) );
		$c->set( ContentCopier::class, static fn( Container $c ) => new ContentCopier( $c->get( BricksAdapter::class ), $c->get( ReferenceReplacer::class ) ) );
		$c->set( ConflictDetector::class, static fn( Container $c ) => new ConflictDetector( $c->get( BricksAdapter::class ) ) );
		$c->set( SnapshotRepository::class, static fn( Container $c ) => new SnapshotRepository( $wpdb, $c->get( BricksAdapter::class ) ) );
		$c->set( TokenRepository::class, static fn() => new TokenRepository( $wpdb ) );
		$c->set( FeedbackRepository::class, static fn() => new FeedbackRepository( $wpdb ) );
		$c->set( RateLimiter::class, static fn() => new RateLimiter() );
		$c->set( Mailer::class, static fn( Container $c ) => new Mailer( $c->get( Settings::class ) ) );
		$c->set( Scheduler::class, static fn() => new Scheduler() );

		$c->set(
			VersionService::class,
			static fn( Container $c ) => new VersionService(
				$wpdb,
				$c->get( BricksAdapter::class ),
				$c->get( ContentCopier::class ),
				$c->get( ConflictDetector::class ),
				$c->get( PostStatus::class ),
				$c->get( Lock::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);
		$c->set(
			PublishPipeline::class,
			static fn( Container $c ) => new PublishPipeline(
				$c->get( BricksAdapter::class ),
				$c->get( CacheAdapter::class ),
				$c->get( SnapshotRepository::class ),
				$c->get( ContentCopier::class ),
				$c->get( ConflictDetector::class ),
				$c->get( VersionService::class ),
				$c->get( TokenRepository::class ),
				$c->get( FeedbackRepository::class ),
				$c->get( Lock::class ),
				$c->get( Transaction::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);
		$c->set(
			ChangeSummary::class,
			static fn( Container $c ) => new ChangeSummary(
				$c->get( BricksAdapter::class ),
				$c->get( ConflictDetector::class ),
				$c->get( ContentCopier::class ),
				$c->get( VersionService::class )
			)
		);
		$c->set(
			RecoveryService::class,
			static fn( Container $c ) => new RecoveryService(
				$c->get( SnapshotRepository::class ),
				$c->get( PublishPipeline::class ),
				$c->get( VersionService::class ),
				$c->get( BricksAdapter::class ),
				$c->get( Lock::class ),
				$c->get( Logger::class )
			)
		);
		$c->set(
			ScheduleService::class,
			static fn( Container $c ) => new ScheduleService(
				$wpdb,
				$c->get( Scheduler::class ),
				$c->get( PublishPipeline::class ),
				$c->get( VersionService::class ),
				$c->get( Mailer::class ),
				$c->get( Logger::class )
			)
		);
		$c->set(
			PreviewController::class,
			static fn( Container $c ) => new PreviewController(
				$c->get( TokenRepository::class ),
				$c->get( FeedbackRepository::class ),
				$c->get( RateLimiter::class ),
				$c->get( PostStatus::class ),
				$c->get( BricksAdapter::class ),
				$c->get( CacheAdapter::class ),
				$c->get( VersionService::class ),
				$c->get( SnapshotRepository::class ),
				$c->get( Mailer::class ),
				$c->get( Logger::class )
			)
		);
		$c->set(
			Presenter::class,
			static fn( Container $c ) => new Presenter(
				$c->get( VersionService::class ),
				$c->get( BricksAdapter::class ),
				$c->get( ConflictDetector::class ),
				$c->get( FeedbackRepository::class ),
				$c->get( TokenRepository::class ),
				$c->get( PreviewController::class )
			)
		);
		$c->set(
			RestController::class,
			static fn( Container $c ) => new RestController(
				$c->get( VersionService::class ),
				$c->get( PublishPipeline::class ),
				$c->get( ScheduleService::class ),
				$c->get( ChangeSummary::class ),
				$c->get( SnapshotRepository::class ),
				$c->get( TokenRepository::class ),
				$c->get( PreviewController::class ),
				$c->get( Presenter::class ),
				$c->get( BricksAdapter::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class ),
				$c->get( GitHubUpdater::class )
			)
		);
		$c->set(
			Admin::class,
			static fn( Container $c ) => new Admin(
				$c->get( VersionService::class ),
				$c->get( BricksAdapter::class ),
				$c->get( RecoveryService::class ),
				$c->get( Presenter::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);
		$c->set( AdminBar::class, static fn( Container $c ) => new AdminBar( $c->get( VersionService::class ), $c->get( BricksAdapter::class ) ) );
		$c->set( BuilderIntegration::class, static fn( Container $c ) => new BuilderIntegration( $c->get( VersionService::class ), $c->get( BricksAdapter::class ), $c->get( Presenter::class ) ) );
		$c->set( FrontBanner::class, static fn( Container $c ) => new FrontBanner( $c->get( VersionService::class ), $c->get( BricksAdapter::class ), $c->get( Presenter::class ) ) );
		$c->set(
			CliCommand::class,
			static fn( Container $c ) => new CliCommand(
				$c->get( VersionService::class ),
				$c->get( PublishPipeline::class ),
				$c->get( SnapshotRepository::class ),
				$c->get( RecoveryService::class ),
				$c->get( Settings::class )
			)
		);
		$c->set( GitHubUpdater::class, static fn( Container $c ) => new GitHubUpdater( $c->get( Settings::class ) ) );

		return $c;
	}
}

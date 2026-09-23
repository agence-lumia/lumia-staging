<?php
/**
 * Publications programmées (F6) et lots (F7).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Publish;

use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Install\Schema;
use Lumia\Staging\Log\Logger;
use Lumia\Staging\Notify\Mailer;
use Lumia\Staging\Scheduler\Scheduler;
use Lumia\Staging\Service\VersionService;

defined( 'ABSPATH' ) || exit;

class ScheduleService {

	public function __construct(
		private \wpdb $db,
		private Scheduler $scheduler,
		private PublishPipeline $pipeline,
		private VersionService $versions,
		private Mailer $mailer,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( Scheduler::VERSION_HOOK, array( $this, 'run_version' ) );
		add_action( Scheduler::BATCH_HOOK, array( $this, 'run_batch' ) );
		add_action( 'lmv_after_publish', array( $this, 'forget_version' ), 10, 2 );
		add_action( 'lmv_version_abandoned', array( $this, 'forget_version' ), 10, 1 );
		add_action( 'updated_post_meta', array( $this, 'track_edit' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'track_edit' ), 10, 3 );
	}

	/**
	 * Convertit une date saisie dans le fuseau du site en timestamp UTC.
	 */
	public static function parse_site_datetime( string $value ): int {
		try {
			$date = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			throw new VersionException( __( 'Date de programmation invalide.', 'lumia-staging' ), 'lmv_bad_date', 400 );
		}
		$timestamp = $date->getTimestamp();
		if ( $timestamp <= time() + 60 ) {
			throw new VersionException( __( 'La date de programmation doit être dans le futur.', 'lumia-staging' ), 'lmv_bad_date', 400 );
		}
		return $timestamp;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Version seule                                                       */
	/* ------------------------------------------------------------------ */

	public function schedule_version( int $version_id, int $timestamp, string $note = '' ): void {
		$this->versions->assert_version( $version_id );
		if ( $this->versions->is_orphan( $version_id ) ) {
			throw new VersionException( __( 'L\'original a été supprimé : impossible de programmer cette version.', 'lumia-staging' ), 'lmv_orphan', 409 );
		}
		$this->versions->transition( $version_id, State::Scheduled );
		update_post_meta( $version_id, Meta::SCHEDULED_AT, $timestamp );
		update_post_meta( $version_id, Meta::SCHEDULE_NOTE, sanitize_textarea_field( $note ) );
		delete_post_meta( $version_id, Meta::EDITED_AFTER );
		$this->scheduler->schedule( Scheduler::VERSION_HOOK, $version_id, $timestamp );
		$this->logger->log( 'scheduled', $this->versions->source_id( $version_id ), $version_id, 'Publication programmée le ' . wp_date( 'd/m/Y H:i', $timestamp ) . '.' );
	}

	public function unschedule_version( int $version_id ): void {
		$this->versions->assert_version( $version_id );
		$batch_id = (int) get_post_meta( $version_id, Meta::BATCH_ID, true );
		if ( $batch_id > 0 ) {
			$this->cancel_batch( $batch_id );
			return;
		}
		$this->versions->transition( $version_id, State::InProgress );
		$this->clear_schedule_meta( $version_id );
		$this->scheduler->cancel( Scheduler::VERSION_HOOK, $version_id );
		$this->logger->log( 'unscheduled', $this->versions->source_id( $version_id ), $version_id, 'Programmation annulée.' );
	}

	/**
	 * @internal Tâche programmée.
	 */
	public function run_version( int $version_id ): void {
		if ( ! $this->versions->is_version( $version_id ) || State::Scheduled !== $this->versions->state( $version_id ) ) {
			return;
		}
		$source_id = $this->versions->source_id( $version_id );
		$title     = (string) get_the_title( $source_id );
		$author    = (int) get_post_field( 'post_author', $version_id );
		wp_set_current_user( $author );

		try {
			$this->pipeline->publish(
				array( $version_id ),
				array(
					'note'    => (string) get_post_meta( $version_id, Meta::SCHEDULE_NOTE, true ),
					'context' => 'scheduled',
				)
			);
			$this->mailer->scheduled_result( $author, $title, true, '', (string) get_permalink( $source_id ) );
		} catch ( \Throwable $e ) {
			if ( $this->versions->is_version( $version_id ) ) {
				update_post_meta( $version_id, Meta::STATE, State::InProgress->value );
				$this->clear_schedule_meta( $version_id );
			}
			$this->logger->log( 'scheduled_failed', $source_id, $version_id, $e->getMessage(), array(), 'error' );
			$this->mailer->scheduled_result( $author, $title, false, $e->getMessage(), admin_url( 'admin.php?page=lumia-staging' ) );
		}
	}

	/*
	------------------------------------------------------------------ */
	/*
	Lots                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Crée un lot ; le publie tout de suite si $timestamp est nul.
	 *
	 * @param list<int> $version_ids
	 * @return array{batch_id: int, result: array<string, mixed>|null}
	 */
	public function create_batch( array $version_ids, ?int $timestamp, string $note = '' ): array {
		$version_ids = array_values( array_unique( array_map( 'intval', $version_ids ) ) );
		if ( count( $version_ids ) < 1 ) {
			throw new VersionException( __( 'Sélectionnez au moins une version.', 'lumia-staging' ), 'lmv_empty', 400 );
		}
		foreach ( $version_ids as $version_id ) {
			$this->versions->assert_version( $version_id );
			$existing = (int) get_post_meta( $version_id, Meta::BATCH_ID, true );
			if ( $existing > 0 && State::Scheduled === $this->versions->state( $version_id ) ) {
				throw new VersionException( sprintf( /* translators: %s: title */ __( '« %s » fait déjà partie d\'un lot programmé. Annulez ce lot d\'abord.', 'lumia-staging' ), get_the_title( $version_id ) ), 'lmv_in_batch', 409 );
			}
		}

		$this->db->insert(
			Schema::table( 'lmv_batches' ),
			array(
				'status'       => null === $timestamp ? 'running' : 'scheduled',
				'scheduled_at' => null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp ),
				'author_id'    => get_current_user_id(),
				'note'         => sanitize_textarea_field( $note ),
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		$batch_id = (int) $this->db->insert_id;

		if ( null === $timestamp ) {
			try {
				$result = $this->pipeline->publish(
					$version_ids,
					array(
						'batch_id' => $batch_id,
						'note'     => $note,
					)
				);
				$this->finish_batch( $batch_id, 'done', '' );
				return array(
					'batch_id' => $batch_id,
					'result'   => $result,
				);
			} catch ( VersionException $e ) {
				$this->finish_batch( $batch_id, 'failed', $e->getMessage() );
				throw $e;
			}
		}

		foreach ( $version_ids as $version_id ) {
			if ( State::Scheduled === $this->versions->state( $version_id ) ) {
				// Programmée seule auparavant : le lot remplace cette programmation.
				$this->scheduler->cancel( Scheduler::VERSION_HOOK, $version_id );
				update_post_meta( $version_id, Meta::STATE, State::InProgress->value );
			}
			$this->versions->transition( $version_id, State::Scheduled );
			update_post_meta( $version_id, Meta::BATCH_ID, $batch_id );
			update_post_meta( $version_id, Meta::SCHEDULED_AT, $timestamp );
			delete_post_meta( $version_id, Meta::EDITED_AFTER );
		}
		$this->scheduler->schedule( Scheduler::BATCH_HOOK, $batch_id, $timestamp );
		$this->logger->log( 'batch_scheduled', 0, 0, sprintf( 'Lot #%d programmé le %s (%d contenus).', $batch_id, wp_date( 'd/m/Y H:i', $timestamp ), count( $version_ids ) ) );
		return array(
			'batch_id' => $batch_id,
			'result'   => null,
		);
	}

	public function cancel_batch( int $batch_id ): void {
		foreach ( $this->versions->list_ids( array( 'batch_id' => $batch_id ) ) as $version_id ) {
			if ( State::Scheduled === $this->versions->state( $version_id ) ) {
				update_post_meta( $version_id, Meta::STATE, State::InProgress->value );
			}
			$this->clear_schedule_meta( $version_id );
		}
		$this->scheduler->cancel( Scheduler::BATCH_HOOK, $batch_id );
		$this->finish_batch( $batch_id, 'cancelled', '' );
		$this->logger->log( 'batch_cancelled', 0, 0, sprintf( 'Lot #%d annulé.', $batch_id ) );
	}

	/**
	 * @internal Tâche programmée.
	 */
	public function run_batch( int $batch_id ): void {
		$table = Schema::table( 'lmv_batches' );
		$batch = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $batch ) || 'scheduled' !== $batch['status'] ) {
			return;
		}
		$version_ids = $this->versions->list_ids( array( 'batch_id' => $batch_id ) );
		$author      = (int) $batch['author_id'];
		wp_set_current_user( $author );

		try {
			$this->pipeline->publish(
				$version_ids,
				array(
					'batch_id' => $batch_id,
					'note'     => (string) $batch['note'],
					'context'  => 'scheduled',
				)
			);
			$this->finish_batch( $batch_id, 'done', '' );
			/* translators: %d: batch id */
			$this->mailer->scheduled_result( $author, sprintf( __( 'Lot #%d', 'lumia-staging' ), $batch_id ), true, '', home_url( '/' ) );
		} catch ( \Throwable $e ) {
			foreach ( $version_ids as $version_id ) {
				if ( $this->versions->is_version( $version_id ) ) {
					update_post_meta( $version_id, Meta::STATE, State::InProgress->value );
					$this->clear_schedule_meta( $version_id );
				}
			}
			$this->finish_batch( $batch_id, 'failed', $e->getMessage() );
			$this->logger->log( 'batch_failed', 0, 0, sprintf( 'Lot #%d : %s', $batch_id, $e->getMessage() ), array(), 'error' );
			/* translators: %d: batch id */
			$this->mailer->scheduled_result( $author, sprintf( __( 'Lot #%d', 'lumia-staging' ), $batch_id ), false, $e->getMessage(), admin_url( 'admin.php?page=lumia-staging' ) );
		}
	}

	/**
	 * Lots programmés à venir.
	 *
	 * @return list<array{id: int, scheduled_at: string, note: string, versions: list<int>}>
	 */
	public function upcoming_batches(): array {
		$table = Schema::table( 'lmv_batches' );
		$rows  = $this->db->get_results( "SELECT * FROM {$table} WHERE status = 'scheduled' ORDER BY scheduled_at ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'           => (int) $row['id'],
				'scheduled_at' => mysql_to_rfc3339( (string) $row['scheduled_at'] ),
				'note'         => (string) $row['note'],
				'versions'     => $this->versions->list_ids( array( 'batch_id' => (int) $row['id'] ) ),
			);
		}
		return $out;
	}

	/**
	 * @internal Nettoie la programmation d'une version publiée ou abandonnée.
	 */
	public function forget_version( int $first, int $version_id = 0 ): void {
		$version_id = 'lmv_after_publish' === current_action() ? $version_id : $first;
		if ( $version_id > 0 ) {
			$this->scheduler->cancel( Scheduler::VERSION_HOOK, $version_id );
		}
	}

	/**
	 * F6 : toute modification d'une version programmée affiche une alerte.
	 *
	 * @internal
	 */
	public function track_edit( int $meta_id, int $post_id, string $meta_key ): void {
		unset( $meta_id );
		if ( ! str_starts_with( $meta_key, '_bricks_' ) || ! $this->versions->is_version( $post_id ) ) {
			return;
		}
		if ( State::Scheduled === $this->versions->state( $post_id ) ) {
			update_post_meta( $post_id, Meta::EDITED_AFTER, time() );
		}
	}

	private function clear_schedule_meta( int $version_id ): void {
		delete_post_meta( $version_id, Meta::SCHEDULED_AT );
		delete_post_meta( $version_id, Meta::SCHEDULE_NOTE );
		delete_post_meta( $version_id, Meta::BATCH_ID );
		delete_post_meta( $version_id, Meta::EDITED_AFTER );
	}

	private function finish_batch( int $batch_id, string $status, string $message ): void {
		$this->db->update(
			Schema::table( 'lmv_batches' ),
			array(
				'status'      => $status,
				'message'     => $message,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $batch_id )
		);
	}
}

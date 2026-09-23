<?php
/**
 * Mise en forme des versions et sauvegardes pour l'interface.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Rest;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Preview\FeedbackRepository;
use Lumia\Staging\Preview\PreviewController;
use Lumia\Staging\Preview\TokenRepository;
use Lumia\Staging\Service\ConflictDetector;
use Lumia\Staging\Service\VersionService;

defined( 'ABSPATH' ) || exit;

class Presenter {

	public function __construct(
		private VersionService $versions,
		private BricksAdapter $bricks,
		private ConflictDetector $conflicts,
		private FeedbackRepository $feedback,
		private TokenRepository $tokens,
		private PreviewController $preview
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function version( int $version_id, bool $detailed = false ): array {
		$source_id    = $this->versions->source_id( $version_id );
		$state        = $this->versions->state( $version_id );
		$author       = get_userdata( (int) get_post_field( 'post_author', $version_id ) );
		$scheduled    = (int) get_post_meta( $version_id, Meta::SCHEDULED_AT, true );
		$created_gmt  = (string) get_post_time( 'Y-m-d H:i:s', true, $version_id );
		$modified_gmt = (string) get_post_modified_time( 'Y-m-d H:i:s', true, $version_id );
		$is_template  = $this->bricks->is_template( $source_id );
		$source_ok    = false !== get_post_status( $source_id );

		$data = array(
			'id'            => $version_id,
			'source_id'     => $source_id,
			'title'         => $source_ok ? get_the_title( $source_id ) : get_the_title( $version_id ),
			'post_type'     => get_post_type( $source_id ),
			'is_template'   => $is_template,
			'template_type' => $is_template ? $this->bricks->template_type( $source_id ) : '',
			'state'         => $state->value,
			'state_label'   => $state->label(),
			'orphan'        => $this->versions->is_orphan( $version_id ) || ! $source_ok || 'trash' === get_post_status( $source_id ),
			'conflict'      => $source_ok && $this->conflicts->source_changed( $version_id, $source_id ),
			'note'          => $this->versions->note( $version_id ),
			'author'        => $author ? $author->display_name : '',
			'author_id'     => $author ? $author->ID : 0,
			'created'       => mysql_to_rfc3339( $created_gmt ),
			'modified'      => mysql_to_rfc3339( $modified_gmt ),
			'age_days'      => (int) floor( ( time() - (int) strtotime( $created_gmt . ' UTC' ) ) / DAY_IN_SECONDS ),
			'scheduled_at'  => $scheduled > 0 ? gmdate( 'c', $scheduled ) : null,
			'batch_id'      => (int) get_post_meta( $version_id, Meta::BATCH_ID, true ),
			'feedback'      => $this->feedback->latest( $version_id ),
			'edit_url'      => $this->bricks->builder_url( $version_id ),
			'live_url'      => $source_ok ? (string) get_permalink( $source_id ) : '',
			'history_url'   => admin_url( 'admin.php?page=lumia-staging-history&post=' . $source_id ),
			'compare_url'   => admin_url( 'admin.php?page=lumia-staging-compare&version=' . $version_id ),
		);

		if ( $detailed ) {
			$data['tokens']        = $this->tokens->for_version( $version_id );
			$data['feedbacks']     = $this->feedback->for_version( $version_id );
			$data['preview_url']   = $this->preview->signed_url( $this->preview->base_url( $source_id ), $version_id, 0, false );
			$data['edited_after']  = '' !== (string) get_post_meta( $version_id, Meta::EDITED_AFTER, true );
			$data['schedule_note'] = (string) get_post_meta( $version_id, Meta::SCHEDULE_NOTE, true );
		}
		return $data;
	}

	/**
	 * Historique affiché : chaque sauvegarde est l'état en ligne jusqu'à la
	 * publication suivante. La note « contenu » d'une sauvegarde est celle de
	 * la publication qui l'avait mise en ligne (la sauvegarde précédente).
	 *
	 * @param list<array<string, mixed>> $rows Plus récentes d'abord.
	 * @return list<array<string, mixed>>
	 */
	public function history( int $post_id, array $rows ): array {
		$out   = array();
		$count = count( $rows );
		foreach ( $rows as $i => $row ) {
			$older      = $rows[ $i + 1 ] ?? null;
			$author     = get_userdata( (int) $row['author_id'] );
			$validation = json_decode( (string) $row['validation'], true );
			$out[]      = array(
				'id'              => (int) $row['id'],
				'replaced_at'     => mysql_to_rfc3339( (string) $row['created_at'] ),
				'replaced_by'     => $author ? $author->display_name : __( 'Système', 'lumia-staging' ),
				'replaced_note'   => (string) $row['note'],
				'replaced_kind'   => (string) $row['kind'],
				'validation'      => is_array( $validation ) ? $validation : null,
				'content_note'    => null !== $older ? (string) $older['note'] : ( $i === $count - 1 ? __( 'Version d\'origine', 'lumia-staging' ) : '' ),
				'bricks_version'  => (string) $row['bricks_version'],
				'bricks_outdated' => '' !== (string) $row['bricks_version'] && BricksAdapter::major( (string) $row['bricks_version'] ) !== BricksAdapter::major( $this->bricks->version() ),
				'preview_url'     => $this->preview->signed_url( $this->preview->base_url( $post_id ), 0, (int) $row['id'], false ),
				'compare_url'     => admin_url( 'admin.php?page=lumia-staging-compare&snapshot=' . (int) $row['id'] ),
			);
		}
		return $out;
	}
}

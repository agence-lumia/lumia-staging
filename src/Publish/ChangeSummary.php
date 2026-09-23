<?php
/**
 * Résumé des changements (F4) et alertes de la fenêtre de publication (F5).
 *
 * Chaque alerte donne la cause, l'impact et l'action possible (§9).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Publish;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Diff\StructureDiff;
use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Service\ConflictDetector;
use Lumia\Staging\Service\ContentCopier;
use Lumia\Staging\Service\VersionService;

defined( 'ABSPATH' ) || exit;

class ChangeSummary {

	public function __construct(
		private BricksAdapter $bricks,
		private ConflictDetector $conflicts,
		private ContentCopier $copier,
		private VersionService $versions
	) {}

	/**
	 * @return array{diff: array<string, mixed>, alerts: list<array{code: string, level: string, message: string}>, blocking: bool, conflict: bool}
	 */
	public function for_version( int $version_id ): array {
		$this->versions->assert_version( $version_id );
		$source_id = $this->versions->source_id( $version_id );

		$before = $this->bricks->meta( $source_id );
		$after  = $this->bricks->meta( $version_id );
		$diff   = $this->diff( $before, $after );

		$diff['fields'] = $this->copier->changed_fields( $version_id, $source_id );

		$alerts   = array();
		$blocking = false;
		$conflict = false;

		$source_status = get_post_status( $source_id );
		if ( $this->versions->is_orphan( $version_id ) || false === $source_status || 'trash' === $source_status ) {
			$blocking = true;
			$alerts[] = $this->alert( 'orphan', 'error', __( 'L\'original a été mis à la corbeille. Cette version ne peut plus être publiée : exportez-la ou abandonnez-la.', 'lumia-staging' ) );
		}
		if ( $this->bricks->is_template( $source_id ) && $this->bricks->template_type( $version_id ) !== $this->bricks->template_type( $source_id ) ) {
			$blocking = true;
			$alerts[] = $this->alert( 'template_type', 'error', __( 'Le type de template a été changé dans la version. La publication est bloquée : remettez le type d\'origine dans les réglages du template.', 'lumia-staging' ) );
		}
		if ( $this->conflicts->source_changed( $version_id, $source_id ) ) {
			$conflict = true;
			$alerts[] = $this->alert( 'conflict', 'conflict', __( 'L\'original a changé : quelqu\'un l\'a modifié directement depuis la création de cette version. Publier écrasera ces modifications. Comparez avant de décider.', 'lumia-staging' ) );
		}
		if ( $this->conflicts->globals_changed( $version_id ) ) {
			$alerts[] = $this->alert( 'globals', 'warning', __( 'Des éléments globaux Bricks (classes, variables, couleurs, styles de thème ou composants) ont été modifiés depuis la création de la version. Ces changements sont déjà en ligne depuis leur modification : ils ne font pas partie de cette publication.', 'lumia-staging' ) );
		}
		if ( State::InReview === $this->versions->state( $version_id ) ) {
			$alerts[] = $this->alert( 'review_pending', 'warning', __( 'Le client n\'a pas encore répondu à la demande de validation. Vous pouvez publier quand même.', 'lumia-staging' ) );
		}
		if ( '' !== (string) get_post_meta( $version_id, Meta::EDITED_AFTER, true ) ) {
			$alerts[] = $this->alert( 'edited_after_schedule', 'warning', __( 'La version a été modifiée après sa programmation : vérifiez l\'aperçu, c\'est ce contenu qui sera publié.', 'lumia-staging' ) );
		}
		if ( $this->conflicts->bricks_major_changed( $version_id ) ) {
			$alerts[] = $this->alert(
				'bricks_version',
				'warning',
				sprintf(
					/* translators: 1: Bricks version at creation, 2: current version */
					__( 'Bricks est passé de la version %1$s à %2$s depuis la création de la version. Vérifiez l\'aperçu avant de publier.', 'lumia-staging' ),
					(string) get_post_meta( $version_id, Meta::BRICKS_VERSION, true ),
					$this->bricks->version()
				)
			);
		}
		$missing = $this->bricks->missing_media( $after );
		if ( array() !== $missing ) {
			$names    = array_unique( array_map( static fn( $m ) => '' !== $m['name'] ? $m['name'] . ' (#' . $m['element'] . ')' : '#' . $m['element'], $missing ) );
			$alerts[] = $this->alert(
				'missing_media',
				'warning',
				sprintf(
					/* translators: %s: element list */
					__( 'Des images utilisées dans la version ont été supprimées de la médiathèque ; elles ne s\'afficheront pas. Éléments concernés : %s.', 'lumia-staging' ),
					implode( ', ', $names )
				)
			);
		}

		return array(
			'diff'     => $diff,
			'alerts'   => $alerts,
			'blocking' => $blocking,
			'conflict' => $conflict,
		);
	}

	/**
	 * Résumé entre deux jeux de métas (aussi utilisé pour les sauvegardes).
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	public function diff( array $before, array $after ): array {
		$elements = array(
			'added'    => 0,
			'removed'  => 0,
			'modified' => 0,
		);
		$zones    = array();
		foreach ( $this->bricks->content_keys() as $zone => $key ) {
			$d = StructureDiff::elements( $before[ $key ] ?? array(), $after[ $key ] ?? array() );
			foreach ( $d as $k => $v ) {
				$elements[ $k ] += $v;
			}
			if ( array_sum( $d ) > 0 ) {
				$zones[] = $zone;
			}
		}
		$settings_key    = $this->bricks->page_settings_key();
		$before_settings = is_array( $before[ $settings_key ] ?? null ) ? $before[ $settings_key ] : array();
		$after_settings  = is_array( $after[ $settings_key ] ?? null ) ? $after[ $settings_key ] : array();

		$css_changed = StructureDiff::differs( $before_settings['customCss'] ?? '', $after_settings['customCss'] ?? '' );
		unset( $before_settings['customCss'], $after_settings['customCss'] );

		return array(
			'elements'         => $elements,
			'zones'            => $zones,
			'css_changed'      => $css_changed,
			'settings_changed' => StructureDiff::differs( $before_settings, $after_settings ),
			'has_changes'      => array_sum( $elements ) > 0 || $css_changed || StructureDiff::differs( $before_settings, $after_settings ),
		);
	}

	/**
	 * @return array{code: string, level: string, message: string}
	 */
	private function alert( string $code, string $level, string $message ): array {
		return array(
			'code'    => $code,
			'level'   => $level,
			'message' => $message,
		);
	}
}

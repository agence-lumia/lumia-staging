<?php
/**
 * Configuration du script d'interface partagé par le builder Bricks et le
 * front (bandeau de version, fenêtres Publier / Partager).
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Front;

use Lumia\Staging\Adapter\BricksAdapter;
use Lumia\Staging\Rest\Presenter;
use Lumia\Staging\Rest\RestController;
use Lumia\Staging\Service\VersionService;
use Lumia\Staging\Support\Capabilities;
use Lumia\Staging\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class UiConfig {

	/**
	 * Enfile le script ; renvoie faux si rien à afficher pour ce contenu.
	 */
	public static function enqueue( string $context, int $post_id, VersionService $versions, BricksAdapter $bricks, Presenter $presenter ): bool {
		if ( $post_id <= 0 || ! current_user_can( Capabilities::CREATE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$mode    = 'none';
		$version = null;
		$open    = null;
		if ( $versions->is_version( $post_id ) ) {
			$mode    = 'version';
			$version = $presenter->version( $post_id, true );
		} elseif ( $versions->supports( $post_id ) ) {
			$open_id = $versions->open_version_for( $post_id );
			$mode    = $open_id > 0 ? 'original' : 'free';
			$open    = $open_id > 0 ? $presenter->version( $open_id ) : null;
		}
		if ( 'free' === $mode && ! ( new Settings() )->bool( 'builder_button' ) ) {
			$mode = 'none';
		}
		if ( 'none' === $mode || ( 'front' === $context && 'version' !== $mode ) ) {
			return false;
		}

		wp_enqueue_style( 'lmv-ui', LMV_URL . 'assets/builder/ui.css', array(), LMV_VERSION );
		wp_enqueue_script( 'lmv-ui', LMV_URL . 'assets/builder/ui.js', array( 'wp-api-fetch' ), LMV_VERSION, true );

		$settings = new Settings();
		wp_add_inline_script(
			'lmv-ui',
			'window.lmvUi=' . wp_json_encode(
				array(
					'context'     => $context,
					'theme'       => 'builder' === $context ? 'dark' : 'light',
					'mode'        => $mode,
					'postId'      => $post_id,
					'version'     => $version,
					'openVersion' => $open,
					'namespace'   => RestController::NS,
					'adminUrl'    => admin_url(),
					'previewDays' => $settings->int( 'preview_days' ),
					'timezone'    => wp_timezone_string(),
					'poll'        => 60000,
					'caps'        => array(
						'publish' => current_user_can( Capabilities::PUBLISH ),
						'share'   => current_user_can( Capabilities::SHARE ),
						'restore' => current_user_can( Capabilities::RESTORE ),
					),
					'i18n'        => self::strings(),
				)
			) . ';',
			'before'
		);
		return true;
	}

	/**
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			/* translators: %s: title */
			'banner'            => __( 'Vous modifiez une version de travail de « %s ». Les visiteurs voient toujours la version en ligne.', 'lumia-staging' ),
			'tip'               => __( 'Astuce : créez de nouvelles classes plutôt que de modifier les classes globales existantes — ces modifications sont en ligne immédiatement.', 'lumia-staging' ),
			'share'             => __( 'Aperçu client', 'lumia-staging' ),
			'compare'           => __( 'Comparer', 'lumia-staging' ),
			'publish'           => __( 'Publier', 'lumia-staging' ),
			'abandon'           => __( 'Abandonner', 'lumia-staging' ),
			'previewOn'         => __( 'Aperçu sur…', 'lumia-staging' ),
			'collapse'          => __( 'Réduire le bandeau', 'lumia-staging' ),
			'expand'            => __( 'Afficher le bandeau de version', 'lumia-staging' ),
			'note'              => __( 'Note (reprise dans l\'historique)', 'lumia-staging' ),
			'notePh'            => __( 'Ex. : refonte du hero + nouvelle section avis', 'lumia-staging' ),
			'publishTitle'      => __( 'Publier la version', 'lumia-staging' ),
			'publishNow'        => __( 'Publier maintenant', 'lumia-staging' ),
			'schedule'          => __( 'Programmer', 'lumia-staging' ),
			'scheduleAt'        => __( 'Date et heure de mise en ligne (fuseau du site)', 'lumia-staging' ),
			'scheduleOk'        => __( 'Publication programmée.', 'lumia-staging' ),
			'unschedule'        => __( 'Annuler la programmation', 'lumia-staging' ),
			'forcePublish'      => __( 'Publier quand même (écrase)', 'lumia-staging' ),
			'seeDiff'           => __( 'Voir les différences', 'lumia-staging' ),
			'cancel'            => __( 'Annuler', 'lumia-staging' ),
			'close'             => __( 'Fermer', 'lumia-staging' ),
			'saveFirst'         => __( 'Enregistrez vos modifications dans Bricks avant de publier : seul le contenu enregistré est publié.', 'lumia-staging' ),
			'loading'           => __( 'Chargement…', 'lumia-staging' ),
			'noChanges'         => __( 'Aucune différence de mise en page avec la version en ligne.', 'lumia-staging' ),
			/* translators: 1: added, 2: removed, 3: modified */
			/* translators: 1: added, 2: removed, 3: modified */
			'elements'          => __( '%1$d élément(s) ajouté(s), %2$d supprimé(s), %3$d modifié(s)', 'lumia-staging' ),
			'cssChanged'        => __( 'CSS de la page modifié', 'lumia-staging' ),
			'settingsChanged'   => __( 'Réglages de la page modifiés', 'lumia-staging' ),
			/* translators: %s: field list */
			'fieldsChanged'     => __( 'Champs modifiés : %s', 'lumia-staging' ),
			'field_title'       => __( 'titre', 'lumia-staging' ),
			'field_excerpt'     => __( 'extrait', 'lumia-staging' ),
			'field_thumbnail'   => __( 'image mise en avant', 'lumia-staging' ),
			'published'         => __( 'Publié.', 'lumia-staging' ),
			'undo'              => __( 'Annuler', 'lumia-staging' ),
			'undone'            => __( 'Publication annulée : la version précédente est de nouveau en ligne.', 'lumia-staging' ),
			'viewLive'          => __( 'Voir la page', 'lumia-staging' ),
			'editOriginal'      => __( 'Modifier dans Bricks', 'lumia-staging' ),
			'publishedAway'     => __( 'Cette version a été publiée ou abandonnée. Vos modifications ne sont plus enregistrées ici.', 'lumia-staging' ),
			'shareTitle'        => __( 'Partager au client', 'lumia-staging' ),
			'shareDays'         => __( 'Durée de validité du lien (jours)', 'lumia-staging' ),
			'shareOn'           => __( 'Page d\'aperçu du template', 'lumia-staging' ),
			'shareCreate'       => __( 'Créer le lien', 'lumia-staging' ),
			'shareCopied'       => __( 'Lien copié dans le presse-papiers. Il ne sera plus affiché : gardez-le ou créez-en un autre.', 'lumia-staging' ),
			'shareLinks'        => __( 'Liens existants', 'lumia-staging' ),
			'revoke'            => __( 'Révoquer', 'lumia-staging' ),
			/* translators: %s: date */
			'expires'           => __( 'expire le %s', 'lumia-staging' ),
			'revoked'           => __( 'révoqué', 'lumia-staging' ),
			'expired'           => __( 'expiré', 'lumia-staging' ),
			'abandonConfirm'    => __( 'Abandonner cette version ? Elle sera supprimée ; la version en ligne ne change pas.', 'lumia-staging' ),
			'abandoned'         => __( 'Version abandonnée.', 'lumia-staging' ),
			'openTitle'         => __( 'Une version de travail est ouverte', 'lumia-staging' ),
			/* translators: %s: author */
			'openText'          => __( 'Une version de travail de ce contenu est en cours (par %s). Modifier l\'original maintenant créera un conflit à la publication de la version.', 'lumia-staging' ),
			'goVersion'         => __( 'Aller à la version', 'lumia-staging' ),
			'editAnyway'        => __( 'Modifier quand même', 'lumia-staging' ),
			'create'            => __( 'Créer une version', 'lumia-staging' ),
			'creating'          => __( 'Création…', 'lumia-staging' ),
			'createHelp'        => __( 'Crée une copie de travail de cette page : les visiteurs continuent de voir la version en ligne.', 'lumia-staging' ),
			'hideCreate'        => __( 'Réduire le bouton', 'lumia-staging' ),
			'showCreate'        => __( 'Lümia Staging : créer une version de travail', 'lumia-staging' ),
			'more'              => __( 'Plus d’actions', 'lumia-staging' ),
			'visitorsSee'       => __( 'Les visiteurs voient toujours la version en ligne.', 'lumia-staging' ),
			'pickPage'          => __( 'Rechercher une page…', 'lumia-staging' ),
			'error'             => __( 'Une erreur est survenue.', 'lumia-staging' ),
			'orphan'            => __( 'L\'original a été supprimé', 'lumia-staging' ),
			'conflict'          => __( 'L\'original a changé', 'lumia-staging' ),
			/* translators: %s: date */
			'scheduledOn'       => __( 'En ligne le %s', 'lumia-staging' ),
			/* translators: %s: name */
			'approvedBy'        => __( 'Validée par %s', 'lumia-staging' ),
			'state_in_progress' => __( 'Version de travail', 'lumia-staging' ),
			'state_in_review'   => __( 'En attente du client', 'lumia-staging' ),
			'state_approved'    => __( 'Validée', 'lumia-staging' ),
			'state_scheduled'   => __( 'Programmée', 'lumia-staging' ),
		);
	}
}

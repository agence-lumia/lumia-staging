<?php
/**
 * Scénarios d'intégration (wp eval-file, utilisateur admin).
 * Couvre : création, publication même ID, restauration, conflit, templates
 * (R5/R6), lot avec échec simulé, reprise après incident, visibilité,
 * protection du statut, programmation, jetons.
 *
 * Écrit /tmp/lmv-state.json pour les tests HTTP (http.php).
 */

use Lumia\Staging\Domain\Meta;
use Lumia\Staging\Domain\State;
use Lumia\Staging\Domain\VersionException;
use Lumia\Staging\Plugin;
use Lumia\Staging\Preview\TokenRepository;
use Lumia\Staging\Publish\ChangeSummary;
use Lumia\Staging\Publish\PublishPipeline;
use Lumia\Staging\Publish\RecoveryService;
use Lumia\Staging\Publish\ScheduleService;
use Lumia\Staging\Service\SnapshotRepository;
use Lumia\Staging\Service\VersionService;

$GLOBALS['lmv_fail'] = 0;
$GLOBALS['lmv_pass'] = 0;

function check( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['lmv_pass'];
		WP_CLI::line( "  ✔ $label" );
	} else {
		++$GLOBALS['lmv_fail'];
		WP_CLI::line( "  ✘ $label" );
	}
}

function el( $id, $text, $name = 'text-basic' ) {
	return array(
		'id'       => $id,
		'name'     => $name,
		'parent'   => 0,
		'children' => array(),
		'settings' => array( 'text' => $text ),
	);
}

function expect_error( callable $fn, $code, $label ) {
	try {
		$fn();
		check( false, "$label (aucune erreur levée)" );
	} catch ( VersionException $e ) {
		check( $e->error_code === $code, "$label [$e->error_code]" );
	}
}

$versions  = Plugin::get( VersionService::class );
$pipeline  = Plugin::get( PublishPipeline::class );
$snapshots = Plugin::get( SnapshotRepository::class );
$summary   = Plugin::get( ChangeSummary::class );
$schedule  = Plugin::get( ScheduleService::class );
$recovery  = Plugin::get( RecoveryService::class );
$tokens    = Plugin::get( TokenRepository::class );

update_option( 'bricks_global_settings', array( 'cssLoading' => 'file' ) );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'P1 — Créer, modifier, publier une page' );

$home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Accueil',
		'post_name'    => 'accueil',
		'post_content' => '',
	)
);
update_post_meta( $home, '_bricks_editor_mode', 'bricks' );
update_post_meta( $home, '_bricks_page_content_2', wp_slash( array( el( 'a1', 'Ancien titre' ), el( 'a2', 'Paragraphe "citation" \\ antislash' ) ) ) );
update_post_meta( $home, '_bricks_page_settings', array( 'customCss' => '.x{color:red}' ) );
update_post_meta( $home, '_bricks_old_key', 'à supprimer' );
update_post_meta( $home, '_yoast_wpseo_title', 'SEO original' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );

$contact = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact', 'post_name' => 'contact' ) );
update_post_meta( $contact, '_bricks_editor_mode', 'bricks' );
update_post_meta( $contact, '_bricks_page_content_2', array( el( 'c1', 'Page contact' ) ) );

$t0  = microtime( true );
$vid = $versions->create( $home, 'Refonte hero' );
$vcss = wp_upload_dir()['basedir'] . '/bricks/css/post-' . $vid . '.min.css';
check( is_file( $vcss ) && str_contains( (string) file_get_contents( $vcss ), '#brxe-a1' ), 'CSS de la version généré avec son contenu (aperçu stylé)' );
check( microtime( true ) - $t0 < 3, 'Création en moins de 3 s' );
check( 'lmv-version' === get_post_status( $vid ), 'Statut lmv-version' );
check( (int) get_post_meta( $vid, Meta::SOURCE_ID, true ) === $home, 'ID original enregistré' );
check( '' !== get_post_meta( $vid, Meta::SOURCE_HASH, true ), 'Empreinte de l\'original enregistrée' );
check( get_post_meta( $vid, '_bricks_page_content_2', true ) === get_post_meta( $home, '_bricks_page_content_2', true ), 'Métas Bricks copiées à l\'identique (guillemets, antislash)' );
check( '' === get_post_meta( $vid, '_yoast_wpseo_title', true ), 'Métas SEO non copiées' );
check( get_post_field( 'post_name', $vid ) !== 'accueil', 'Slug non copié' );
check( State::InProgress === $versions->state( $vid ), 'État : en cours' );
expect_error( fn() => $versions->create( $home ), 'lmv_version_exists', 'Une seule version ouverte par contenu' );

// Travail sur la version.
update_post_meta( $vid, '_bricks_page_content_2', wp_slash( array( el( 'a1', 'Nouveau titre' ), el( 'a2', 'Paragraphe "citation" \\ antislash' ), el( 'a3', 'Section avis' ) ) ) );
delete_post_meta( $vid, '_bricks_old_key' );
update_post_meta( $vid, '_bricks_page_settings', array( 'customCss' => '.x{color:blue}' ) );
wp_update_post( array( 'ID' => $vid, 'post_title' => 'Accueil (nouveau)' ) );

$s = $summary->for_version( $vid );
check( 1 === $s['diff']['elements']['added'] && 1 === $s['diff']['elements']['modified'] && 0 === $s['diff']['elements']['removed'], 'Résumé : 1 ajouté, 1 modifié' );
check( $s['diff']['css_changed'], 'Résumé : CSS de page modifié' );
check( in_array( 'title', $s['diff']['fields'], true ), 'Résumé : titre modifié' );
check( ! $s['conflict'] && ! $s['blocking'], 'Pas de conflit ni de blocage' );

// Protection du statut : « Publier » depuis un autre outil ne rend pas la version publique.
wp_update_post( array( 'ID' => $vid, 'post_status' => 'publish' ) );
check( 'lmv-version' === get_post_status( $vid ), 'Statut protégé contre une publication externe' );

$t0     = microtime( true );
$result = $pipeline->publish( array( $vid ), array( 'note' => 'Nouvelle home' ) );
check( microtime( true ) - $t0 < 5, 'Publication en moins de 5 s' );
$snap_publish = $result['items'][0]['snapshot_id'];
check( null === get_post( $vid ), 'Version supprimée après publication' );
check( 'Nouveau titre' === get_post_meta( $home, '_bricks_page_content_2', true )[0]['settings']['text'], 'Contenu publié sur l\'original' );
check( 'Paragraphe "citation" \\ antislash' === get_post_meta( $home, '_bricks_page_content_2', true )[1]['settings']['text'], 'Caractères spéciaux intacts après publication' );
check( '' === get_post_meta( $home, '_bricks_old_key', true ), 'R1 : clé absente de la version supprimée de l\'original' );
check( 'accueil' === get_post_field( 'post_name', $home ), 'Slug de l\'original conservé' );
check( 'SEO original' === get_post_meta( $home, '_yoast_wpseo_title', true ), 'SEO de l\'original conservé' );
check( (int) get_option( 'page_on_front' ) === $home, 'Toujours page d\'accueil (même ID)' );
check( 'Accueil (nouveau)' === get_the_title( $home ), 'Titre publié (option par défaut)' );
$css_file = wp_upload_dir()['basedir'] . '/bricks/css/post-' . $home . '.min.css';
check( is_file( $css_file ), 'R3 : CSS de l\'original régénéré' );
// Régression : le save_post de Bricks génère le CSS pendant la publication ;
// la régénération qui suit ne doit pas réécrire un fichier sans les styles
// des éléments (dédoublonnage statique de Bricks dans la même requête).
$css = (string) file_get_contents( $css_file );
check( str_contains( $css, '#brxe-a1' ) && str_contains( $css, '#brxe-a3' ), 'R3 : CSS publié complet (styles des éléments présents)' );
$row = $snapshots->get( $snap_publish );
check( 'committed' === $row['status'] && 'Nouvelle home' === $row['note'], 'Sauvegarde validée avec la note' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'P5 — Revenir en arrière' );
$undo = $pipeline->restore( $snap_publish );
check( 'Ancien titre' === get_post_meta( $home, '_bricks_page_content_2', true )[0]['settings']['text'], 'Contenu restauré' );
check( 'à supprimer' === get_post_meta( $home, '_bricks_old_key', true ), 'Clé supprimée restaurée' );
check( 'Accueil' === get_the_title( $home ), 'Titre restauré' );
$pipeline->restore( $undo );
check( 'Nouveau titre' === get_post_meta( $home, '_bricks_page_content_2', true )[0]['settings']['text'], 'La restauration est elle-même annulable' );
check( count( $snapshots->history( $home ) ) === 3, 'Historique : 3 entrées' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'R10 — Conflit' );
$vid2 = $versions->create( $home );
update_post_meta( $vid2, '_bricks_page_content_2', array( el( 'a1', 'Version 2' ) ) );
update_post_meta( $home, '_bricks_page_content_2', array( el( 'a1', 'Modifié en direct' ) ) );
check( $summary->for_version( $vid2 )['conflict'], 'Conflit détecté dans le résumé' );
expect_error( fn() => $pipeline->publish( array( $vid2 ) ), 'lmv_conflict', 'Publication bloquée sans « publier quand même »' );
check( 'lmv-version' === get_post_status( $vid2 ), 'Version conservée après refus' );
$pipeline->publish( array( $vid2 ), array( 'force' => true ) );
check( 'Version 2' === get_post_meta( $home, '_bricks_page_content_2', true )[0]['settings']['text'], 'Publier quand même : écrase' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'R5/R6 — Templates' );
$header = wp_insert_post( array( 'post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Header' ) );
update_post_meta( $header, '_bricks_template_type', 'header' );
update_post_meta( $header, '_bricks_template_settings', array( 'templateConditions' => array( array( 'main' => 'any' ) ), 'headerPosition' => 'top' ) );
update_post_meta( $header, '_bricks_page_content_2', array( el( 'h1', 'Header actuel' ) ) );
update_option( 'stub_header_template', $header );

$hv = $versions->create( $header );
$hv_settings = get_post_meta( $hv, '_bricks_template_settings', true );
check( ! isset( $hv_settings['templateConditions'] ) && 'top' === $hv_settings['headerPosition'], 'R5 : version sans conditions, autres réglages gardés' );
update_post_meta( $hv, '_bricks_page_content_2', array( el( 'h1', 'Header nouveau' ) ) );
update_post_meta( $hv, '_bricks_template_type', 'footer' );
expect_error( fn() => $pipeline->publish( array( $hv ) ), 'lmv_template_type', 'R6 : changement de type bloqué' );
update_post_meta( $hv, '_bricks_template_type', 'header' );
update_post_meta( $hv, '_bricks_template_settings', array( 'headerPosition' => 'left' ) );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'F7 — Lot avec échec simulé' );
$pv = $versions->create( $contact );
update_post_meta( $pv, '_bricks_page_content_2', array( el( 'c1', 'Contact nouveau' ) ) );
$fail = static function ( $step, $item ) use ( $contact ) {
	if ( 'css' === $step && $item['post_id'] === $contact ) {
		throw new RuntimeException( 'échec simulé' );
	}
};
add_action( 'lmv_before_step', $fail, 10, 2 );
expect_error( fn() => $pipeline->publish( array( $pv, $hv ), array( 'batch_id' => 99 ) ), 'lmv_publish_failed', 'Le lot échoue' );
remove_action( 'lmv_before_step', $fail, 10 );
check( 'Header actuel' === get_post_meta( $header, '_bricks_page_content_2', true )[0]['settings']['text'], 'Tout ou rien : le header (publié en premier) est restauré' );
check( 'Page contact' === get_post_meta( $contact, '_bricks_page_content_2', true )[0]['settings']['text'], 'Tout ou rien : la page est restaurée' );
check( 'lmv-version' === get_post_status( $pv ) && 'lmv-version' === get_post_status( $hv ), 'Versions conservées' );

$order = array();
$track = static function ( $step, $item ) use ( &$order ) {
	if ( 'copy' === $step ) {
		$order[] = $item['post_id'];
	}
};
add_action( 'lmv_before_step', $track, 10, 2 );
$pipeline->publish( array( $pv, $hv ) );
remove_action( 'lmv_before_step', $track, 10 );
check( array( $header, $contact ) === $order, 'Ordre : templates d\'abord, puis pages' );
$settings_after = get_post_meta( $header, '_bricks_template_settings', true );
check( array( array( 'main' => 'any' ) ) === $settings_after['templateConditions'] && 'left' === $settings_after['headerPosition'], 'R5 : conditions de l\'original conservées, réglages publiés' );
check( 'header' === get_post_meta( $header, '_bricks_template_type', true ), 'Type du template conservé' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'Reprise après incident' );
$before = get_post_meta( $contact, '_bricks_page_content_2', true );
$sid    = $snapshots->create( $contact, array( 'kind' => 'publish', 'status' => 'pending' ) );
update_post_meta( $contact, '_bricks_page_content_2', array( el( 'c1', 'À moitié copié' ) ) ); // PHP « s'arrête » ici.
$restored = $recovery->run();
check( 1 === $restored, 'Publication inachevée détectée' );
check( $before === get_post_meta( $contact, '_bricks_page_content_2', true ), 'Original restauré à l\'identique' );
check( 'recovered' === $snapshots->get( $sid )['status'], 'Sauvegarde marquée « recovered »' );
check( ! empty( get_option( 'lmv_recovery_notices' ) ), 'Notice admin préparée' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'R8 — Éléments globaux' );
$gv = $versions->create( $contact );
update_option( 'bricks_global_classes', array( array( 'id' => 'x', 'name' => 'btn' ) ) );
$codes = array_column( $summary->for_version( $gv )['alerts'], 'code' );
check( in_array( 'globals', $codes, true ), 'Alerte : classe globale modifiée' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'Médias supprimés' );
update_post_meta( $gv, '_bricks_page_content_2', array( array( 'id' => 'i1', 'name' => 'image', 'settings' => array( 'image' => array( 'id' => 999999, 'url' => 'http://x/y.jpg' ) ) ) ) );
$codes = array_column( $summary->for_version( $gv )['alerts'], 'code' );
check( in_array( 'missing_media', $codes, true ), 'Alerte : image supprimée de la médiathèque' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'R11 — Original à la corbeille' );
$tmp  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Temp' ) );
update_post_meta( $tmp, '_bricks_editor_mode', 'bricks' );
$tv = $versions->create( $tmp );
wp_trash_post( $tmp );
check( $versions->is_orphan( $tv ), 'Version marquée orpheline' );
expect_error( fn() => $pipeline->publish( array( $tv ) ), 'lmv_orphan', 'Version orpheline non publiable' );
check( is_array( $versions->export( $tv )['meta'] ), 'Export possible' );
$versions->abandon( $tv );
check( null === get_post( $tv ), 'Abandon : version supprimée' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'Visibilité' );
$editor = wp_insert_user( array( 'user_login' => 'client', 'user_pass' => 'client', 'role' => 'editor', 'user_email' => 'client@example.com' ) );
wp_set_current_user( $editor );
check( ! current_user_can( 'edit_post', $gv ) && ! current_user_can( 'read_post', $gv ), 'Éditeur sans capacité : ni lecture ni modification' );
$q = new WP_Query( array( 'p' => $gv, 'post_type' => 'page', 'post_status' => 'any' ) );
check( 0 === $q->post_count, 'Éditeur : version absente des requêtes' );
$q = new WP_Query( array( 'post_type' => 'page', 'post_status' => array( 'lmv-version' ), 'posts_per_page' => -1 ) );
check( 0 === $q->post_count, 'Éditeur : même en demandant le statut' );
wp_set_current_user( 1 );
$q = new WP_Query( array( 'post_type' => 'page', 's' => 'Contact', 'posts_per_page' => -1 ) );
check( ! in_array( $gv, wp_list_pluck( $q->posts, 'ID' ), true ), 'Recherche : version exclue même pour un admin' );
check( ! in_array( 'lmv-version', get_post_stati( array( 'public' => true ) ), true ), 'Statut non public' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'F6 — Programmation' );
$schedule->schedule_version( $gv, time() + 600, 'Programmée' );
check( State::Scheduled === $versions->state( $gv ), 'État : programmée' );
check( false !== wp_next_scheduled( 'lmv_scheduled_publish', array( $gv ) ), 'Tâche WP-Cron planifiée' );
update_post_meta( $gv, '_bricks_page_content_2', array( el( 'c1', 'Contact programmé' ) ) );
check( in_array( 'edited_after_schedule', array_column( $summary->for_version( $gv )['alerts'], 'code' ), true ), 'Alerte : modifiée après programmation' );
$schedule->run_version( $gv );
check( null === get_post( $gv ), 'Publication programmée exécutée' );
check( 'Contact programmé' === get_post_meta( $contact, '_bricks_page_content_2', true )[0]['settings']['text'], 'Contenu programmé en ligne' );
check( false === wp_next_scheduled( 'lmv_scheduled_publish', array( $gv ) ), 'Tâche nettoyée' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'F7 — Lot programmé' );
$b1 = $versions->create( $contact );
update_post_meta( $b1, '_bricks_page_content_2', array( el( 'c1', 'Contact lot' ) ) );
$b2 = $versions->create( $header );
update_post_meta( $b2, '_bricks_page_content_2', array( el( 'h1', 'Header lot' ) ) );
$batch = $schedule->create_batch( array( $b1, $b2 ), time() + 600, 'Refonte' );
check( State::Scheduled === $versions->state( $b1 ) && State::Scheduled === $versions->state( $b2 ), 'Versions du lot programmées' );
$schedule->run_batch( $batch['batch_id'] );
check( 'Contact lot' === get_post_meta( $contact, '_bricks_page_content_2', true )[0]['settings']['text'] && 'Header lot' === get_post_meta( $header, '_bricks_page_content_2', true )[0]['settings']['text'], 'Lot publié à l\'heure' );

/* ------------------------------------------------------------------ */
WP_CLI::line( 'Jetons' );
$pvid = $versions->create( $home );
update_post_meta( $pvid, '_bricks_page_content_2', array( el( 'a1', 'Titre en aperçu client' ) ) );
$tok = $tokens->create( $pvid, 7 );
check( 43 === strlen( $tok['token'] ), 'Jeton 256 bits en base64url' );
global $wpdb;
check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lmv_tokens WHERE token_hash = %s", $tok['token'] ) ), 'Jeton jamais stocké en clair' );
check( null !== $tokens->find_valid( $tok['token'] ), 'Jeton valide' );
$revoked = $tokens->create( $pvid, 7 );
$tokens->revoke( $revoked['id'], $pvid );
check( null === $tokens->find_valid( $revoked['token'] ), 'Jeton révoqué refusé' );
$expired = $tokens->create( $pvid, 1 );
$wpdb->update( $wpdb->prefix . 'lmv_tokens', array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expired['id'] ) );
check( null === $tokens->find_valid( $expired['token'] ), 'Jeton expiré refusé' );
$versions->transition( $pvid, State::InReview );

$hv2 = $versions->create( $header );
update_post_meta( $hv2, '_bricks_page_content_2', array( el( 'h1', 'Header en aperçu' ) ) );
$htok = $tokens->create( $hv2, 7 );

file_put_contents(
	'/tmp/lmv-state.json',
	wp_json_encode(
		array(
			'home'          => $home,
			'contact'       => $contact,
			'version'       => $pvid,
			'token'         => $tok['token'],
			'revoked'       => $revoked['token'],
			'expired'       => $expired['token'],
			'header'        => $header,
			'header_token'  => $htok['token'],
			'header_version' => $hv2,
			'signed_live'   => Plugin::get( \Lumia\Staging\Preview\PreviewController::class )->signed_url( home_url( '/' ), 0, 0, true ),
			'signed_snap'   => Plugin::get( \Lumia\Staging\Preview\PreviewController::class )->signed_url( home_url( '/' ), 0, $snap_publish, false ),
		)
	)
);

WP_CLI::line( '' );
WP_CLI::line( sprintf( 'Intégration : %d réussis, %d échoués', $GLOBALS['lmv_pass'], $GLOBALS['lmv_fail'] ) );
if ( $GLOBALS['lmv_fail'] > 0 ) {
	WP_CLI::halt( 1 );
}

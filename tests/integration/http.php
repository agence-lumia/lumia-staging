<?php
/**
 * Tests HTTP de l'aperçu client, contre le serveur PHP intégré (port 8080).
 */

$state = json_decode( file_get_contents( '/tmp/lmv-state.json' ), true );
$base  = 'http://localhost:8080';
$fail  = 0;
$pass  = 0;

function req( $url, $method = 'GET', $body = null, $cookie = '' ) {
	$headers = "Content-Type: application/x-www-form-urlencoded\r\n";
	if ( $cookie ) {
		$headers .= "Cookie: $cookie\r\n";
	}
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'          => $method,
				'header'          => $headers,
				'content'         => $body ? http_build_query( $body ) : '',
				'ignore_errors'   => true,
				'follow_location' => 0,
				'timeout'         => 20,
			),
		)
	);
	$html = file_get_contents( $url, false, $ctx );
	$code = 0;
	$hdrs = array( 'cookies' => array() );
	foreach ( $http_response_header ?? array() as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d+)#', $line, $m ) ) {
			$code = (int) $m[1];
		} elseif ( str_contains( $line, ':' ) ) {
			[ $k, $v ] = explode( ':', $line, 2 );
			$hdrs[ strtolower( trim( $k ) ) ] = trim( $v );
			if ( 'set-cookie' === strtolower( trim( $k ) ) ) {
				$hdrs['cookies'][] = trim( explode( ';', $v )[0] );
			}
		}
	}
	return array( $code, (string) $html, $hdrs );
}

function check( $cond, $label ) {
	global $fail, $pass;
	if ( $cond ) {
		++$pass;
		echo "  ✔ $label\n";
	} else {
		++$fail;
		echo "  ✘ $label\n";
	}
}

echo "HTTP — visiteurs\n";
[ $code, $html ] = req( "$base/" );
check( 200 === $code && str_contains( $html, 'Nouveau titre' ) === false, 'Accueil servi normalement' );
check( ! str_contains( $html, 'Titre en aperçu client' ), 'Le visiteur ne voit pas la version de travail' );
check( ! str_contains( $html, 'lmv-' ), 'Aucun fichier ni script du plugin chargé pour un visiteur' );
[ $code, $html ] = req( "$base/?page_id={$state['version']}" );
check( ! str_contains( $html, 'Titre en aperçu client' ), 'Version inaccessible par ?page_id=ID' );
[ $code, $html ] = req( "$base/?p={$state['version']}&post_type=page" );
check( ! str_contains( $html, 'Titre en aperçu client' ), 'Version inaccessible par ?p=ID' );
[ $code, $html ] = req( "$base/wp-json/wp/v2/pages/{$state['version']}" );
check( in_array( $code, array( 401, 403, 404 ), true ), "API REST publique : version refusée ($code)" );
[ $code, $html ] = req( "$base/wp-json/wp/v2/pages?per_page=100&status=lmv-version" );
check( ! str_contains( $html, 'Titre en aperçu client' ), 'API REST publique : aucune version listée' );
[ $code, $html ] = req( "$base/?s=Accueil" );
check( ! str_contains( $html, 'data-id="' . $state['version'] . '"' ), 'Recherche : version absente' );

echo "HTTP — aperçu client\n";
[ $code, $html, $h ] = req( "$base/?lmv_preview={$state['token']}" );
check( 200 === $code, "Aperçu : 200 ($code)" );
check( str_contains( $html, 'Titre en aperçu client' ), 'Aperçu : contenu de la version affiché' );
check( str_contains( $html, 'data-front="1"' ), 'R13 : rendue comme page d\'accueil' );
check( str_contains( $h['cache-control'] ?? '', 'no-store' ) && str_contains( $h['cache-control'] ?? '', 'private' ), 'Cache-Control: private, no-store' );
check( str_contains( $h['x-robots-tag'] ?? '', 'noindex' ), 'X-Robots-Tag: noindex' );
check( 'no-referrer' === ( $h['referrer-policy'] ?? '' ), 'Referrer-Policy: no-referrer' );
check( str_contains( $h['content-security-policy'] ?? '', "frame-ancestors 'self'" ), "frame-ancestors 'self'" );
check( str_contains( $html, 'contact/?lmv_preview=' . $state['token'] ), 'Liens internes : le jeton reste dans l\'URL' );
check( str_contains( $html, 'toolbar.js' ) && str_contains( $html, 'lmvPreview' ), 'Barre d\'aperçu client chargée' );
check( ! str_contains( $html, 'wpadminbar' ), 'Rendu anonyme (pas de barre d\'admin)' );

[ $code, $html ] = req( "$base/accueil/?lmv_preview={$state['token']}" );
check( str_contains( $html, 'Titre en aperçu client' ), 'Aperçu par l\'URL de l\'original (slug)' );
[ $code, $html ] = req( "$base/contact/?lmv_preview={$state['token']}" );
check( str_contains( $html, 'Contact lot' ) && str_contains( $html, 'lmv_preview=' ), 'Navigation vers une autre page avec le jeton' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}&lmv_side=before" );
check( ! str_contains( $html, 'Titre en aperçu client' ) && str_contains( $html, 'data-id="' . $state['home'] . '"' ), 'Avant : version en ligne' );

echo "HTTP — template (R7)\n";
[ $code, $html ] = req( "$base/contact/?lmv_preview={$state['header_token']}" );
check( str_contains( $html, 'Header en aperçu' ), 'Header de la version affiché sur une page' );
[ $code, $html ] = req( "$base/contact/" );
check( str_contains( $html, 'Header lot' ) && ! str_contains( $html, 'Header en aperçu' ), 'Visiteur : header en ligne inchangé' );

echo "HTTP — soumissions et retours\n";
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}", 'POST', array( 'foo' => 'bar' ) );
check( 403 === $code, "Soumission de formulaire bloquée ($code)" );
[ $code, $html ] = req( "$base/wp-admin/admin-ajax.php?action=test&lmv_preview={$state['token']}", 'POST', array( 'foo' => 'bar' ) );
check( 403 === $code && str_contains( $html, 'success":false' ), "admin-ajax bloqué ($code)" );

[ , $page ] = req( "$base/?lmv_preview={$state['token']}" );
preg_match( '/"nonce":"([a-f0-9]+)"/', $page, $m );
$nonce = $m[1] ?? '';
check( '' !== $nonce, 'Nonce de retour présent' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}", 'POST', array( 'lmv_action' => 'feedback', 'lmv_nonce' => 'bad', 'name' => 'X', 'decision' => 'approve' ) );
check( 403 === $code, 'Retour refusé sans nonce valide' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}", 'POST', array( 'lmv_action' => 'feedback', 'lmv_nonce' => $nonce, 'name' => 'Marie', 'decision' => 'changes', 'comment' => '' ) );
check( 400 === $code, 'Demande de modifications : commentaire obligatoire' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}", 'POST', array( 'lmv_action' => 'feedback', 'lmv_nonce' => $nonce, 'name' => 'Marie', 'decision' => 'approve', 'comment' => str_repeat( 'a', 2001 ) ) );
check( 400 === $code, 'Commentaire limité à 2 000 caractères' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['token']}", 'POST', array( 'lmv_action' => 'feedback', 'lmv_nonce' => $nonce, 'name' => '<b>Marie</b>', 'decision' => 'approve', 'comment' => 'Parfait <script>alert(1)</script>' ) );
check( 200 === $code && str_contains( $html, '"success":true' ), "Validation client enregistrée ($code)" );

echo "HTTP — jetons invalides\n";
[ $code, $html ] = req( "$base/?lmv_preview={$state['revoked']}" );
check( 404 === $code && ! str_contains( $html, 'Accueil' ) && ! str_contains( $html, 'wp-content' ), 'Jeton révoqué : page neutre' );
[ $code, $html ] = req( "$base/?lmv_preview={$state['expired']}" );
check( 404 === $code && str_contains( $html, 'Lien indisponible' ), 'Jeton expiré : page neutre' );

echo "HTTP — URLs signées (comparatif, historique)\n";
[ $code, $html, $h ] = req( $state['signed_live'] );
check( 200 === $code && str_contains( $html, 'data-id="' . $state['home'] . '"' ) && ! str_contains( $html, 'lmvPreview' ), 'Côté « en ligne » du comparatif, sans barre client' );
[ $code, $html ] = req( $state['signed_snap'] );
check( str_contains( $html, 'Ancien titre' ), 'Aperçu d\'une sauvegarde (état d\'avant publication)' );
[ $code, $html ] = req( str_replace( 'lmv_sig=', 'lmv_sig=0', $state['signed_live'] ) );
check( 404 === $code, 'Signature altérée refusée' );

echo "HTTP — administration (admin connecté)\n";
[ , , $h ] = req( "$base/wp-login.php", 'POST', array( 'log' => 'admin', 'pwd' => 'admin', 'testcookie' => '1' ), 'wordpress_test_cookie=WP%20Cookie%20check' );
$cookie = implode( '; ', $h['cookies'] );
check( str_contains( $cookie, 'wordpress_logged_in' ), 'Connexion admin' );
foreach ( array( 'lumia-staging', 'lumia-staging-history&post=' . $state['home'], 'lumia-staging-compare&version=' . $state['version'], 'lumia-staging-log', 'lumia-staging-settings' ) as $page ) {
	[ $code, $html ] = req( "$base/wp-admin/admin.php?page=$page", 'GET', null, $cookie );
	check( 200 === $code && str_contains( $html, 'id="lmv-admin"' ) && str_contains( $html, 'build/admin.js' ) && str_contains( $html, 'style-admin.css' ), "Écran admin « $page » rendu" );
}
[ $code, $html ] = req( "$base/wp-admin/edit.php?post_type=page", 'GET', null, $cookie );
check( str_contains( $html, 'Reprendre la version' ) && str_contains( $html, 'Créer une version' ) && str_contains( $html, 'Version de travail ouverte' ), 'Liste des pages : actions et état' );
check( ! preg_match( '/<tr id="post-' . $state['version'] . '"/', $html ), 'Liste des pages : la version n\'y figure pas' );
[ $code, $html ] = req( "$base/wp-admin/index.php", 'GET', null, $cookie );
check( str_contains( $html, 'lmv_dashboard' ), 'Widget du tableau de bord WordPress' );
[ , $html ] = req( "$base/wp-admin/admin.php?page=lumia-staging", 'GET', null, $cookie );
preg_match( '/createNonceMiddleware\(\s*"([a-f0-9]+)"/', $html, $m );
$rest_nonce = $m[1] ?? '';
check( '' !== $rest_nonce, 'Nonce REST disponible' );
function rest( $path, $method, $body, $cookie, $nonce ) {
	$ctx  = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => "Content-Type: application/json\r\nCookie: $cookie\r\nX-WP-Nonce: $nonce\r\n", 'content' => $body ? json_encode( $body ) : '', 'ignore_errors' => true ) ) );
	$out  = file_get_contents( 'http://localhost:8080/wp-json/lumia-staging/v1' . $path, false, $ctx );
	preg_match( '#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $mm );
	return array( (int) ( $mm[1] ?? 0 ), json_decode( (string) $out, true ) );
}
[ $code, $list ] = rest( '/versions', 'GET', null, $cookie, $rest_nonce );
check( 200 === $code && count( $list['items'] ) >= 2, "REST : liste des versions ($code)" );
[ $code ] = rest( '/versions', 'GET', null, $cookie, 'mauvais' );
check( 403 === $code, "REST : nonce invalide refusé ($code)" );
[ $code, $res ] = rest( '/versions', 'POST', array( 'source_id' => $state['home'] ), $cookie, $rest_nonce );
check( 409 === $code && 'lmv_version_exists' === ( $res['code'] ?? '' ) && ! empty( $res['data']['edit_url'] ), 'REST : version existante → reprendre' );
[ $code, $res ] = rest( '/versions', 'POST', array( 'source_id' => $state['contact'] ), $cookie, $rest_nonce );
check( 201 === $code && ! empty( $res['edit_url'] ), "REST : création d'une version ($code)" );
$new = $res['id'] ?? 0;
[ $code, $res ] = rest( "/versions/$new/summary", 'GET', null, $cookie, $rest_nonce );
check( 200 === $code && isset( $res['diff']['elements'] ), 'REST : résumé des changements' );
[ $code, $res ] = rest( "/versions/$new/share", 'POST', array( 'days' => 3 ), $cookie, $rest_nonce );
check( 201 === $code && str_contains( $res['url'] ?? '', 'lmv_preview=' ) && 'in_review' === $res['version']['state'], 'REST : lien client créé, état « en attente du client »' );
[ $code, $res ] = rest( "/versions/$new/publish", 'POST', array( 'note' => 'via REST' ), $cookie, $rest_nonce );
check( 200 === $code && $res['snapshot_id'] > 0, "REST : publication ($code)" );
[ $code, $res ] = rest( "/snapshots/{$res['snapshot_id']}/restore", 'POST', array( 'confirm' => true ), $cookie, $rest_nonce );
check( 200 === $code && $res['snapshot_id'] > 0, 'REST : annulation de la publication' );
[ $code, $res ] = rest( "/compare?version={$state['version']}", 'GET', null, $cookie, $rest_nonce );
check( 200 === $code && str_contains( $res['left'] ?? '', 'lmv_sig=' ) && count( $res['breakpoints'] ) >= 1, 'REST : comparatif (URL signées, formats)' );
[ $code, $res ] = rest( "/posts/{$state['home']}/history", 'GET', null, $cookie, $rest_nonce );
check( 200 === $code && count( $res['items'] ) >= 3 && ! empty( $res['items'][0]['preview_url'] ), 'REST : historique' );
[ $code, $res ] = rest( '/log', 'GET', null, $cookie, $rest_nonce );
check( 200 === $code && $res['total'] > 10, 'REST : journal' );
[ $code, $res ] = rest( '/settings', 'POST', array( 'preview_days' => 99, 'retention_days' => 1 ), $cookie, $rest_nonce );
check( 200 === $code && 30 === $res['settings']['preview_days'] && 30 === $res['settings']['retention_days'], 'REST : réglages bornés (1–30 jours, rétention ≥ 30)' );
check( isset( $res['updates']['installed'], $res['updates']['channel'] ) && 'stable' === $res['updates']['channel'], 'REST : état des mises à jour dans les réglages (sans appel réseau)' );
[ $code, $res ] = rest( '/settings', 'POST', array( 'update_channel' => 'dev' ), $cookie, $rest_nonce );
check( 200 === $code && 'dev' === $res['settings']['update_channel'] && 'dev' === $res['updates']['channel'], 'REST : canal de mise à jour « dev »' );
[ $code, $res ] = rest( '/settings', 'POST', array( 'update_channel' => 'nightly' ), $cookie, $rest_nonce );
check( 'dev' === $res['settings']['update_channel'], 'REST : canal inconnu refusé' );
if ( ! empty( $res['updates']['auto_update_available'] ) ) {
	[ $code, $res ] = rest( '/settings', 'POST', array( 'auto_update' => true ), $cookie, $rest_nonce );
	check( true === $res['updates']['auto_update'] && ! isset( $res['settings']['auto_update'] ), 'REST : mise à jour auto écrite dans l’option native, pas dans nos réglages' );
	rest( '/settings', 'POST', array( 'auto_update' => false ), $cookie, $rest_nonce );
}
rest( '/settings', 'POST', array( 'update_channel' => 'stable' ), $cookie, $rest_nonce );
[ $code, $csv ] = req( "$base/wp-admin/admin-post.php?action=lmv_export_log", 'GET', null, $cookie );
check( 403 === $code || ! str_contains( (string) $csv, 'date_utc' ), 'Export CSV refusé sans nonce' );

echo "HTTP — client connecté sans droits (Éditeur)\n";
[ , , $h ] = req( "$base/wp-login.php", 'POST', array( 'log' => 'client', 'pwd' => 'client', 'testcookie' => '1' ), 'wordpress_test_cookie=WP%20Cookie%20check' );
$ecookie = implode( '; ', $h['cookies'] );
[ $code, $html ] = req( "$base/wp-admin/edit.php?post_type=page", 'GET', null, $ecookie );
check( 200 === $code && ! str_contains( $html, 'Créer une version' ) && ! str_contains( $html, 'lmv-' ), 'Éditeur : aucune interface du plugin' );
[ $code, $html ] = req( "$base/?page_id={$state['version']}", 'GET', null, $ecookie );
check( ! str_contains( $html, 'Titre en aperçu client' ), 'Éditeur : version invisible en front' );
[ $code ] = req( "$base/wp-admin/admin.php?page=lumia-staging", 'GET', null, $ecookie );
check( 403 === $code, "Éditeur : tableau de bord refusé ($code)" );

echo "HTTP — limitation des essais\n";
$last = 0;
for ( $i = 0; $i < 22; $i++ ) {
	[ $last ] = req( "$base/?lmv_preview=invalid$i" );
}
check( 429 === $last, "429 après 20 jetons invalides ($last)" );
[ $code ] = req( "$base/?lmv_preview={$state['token']}" );
check( 429 === $code, 'IP bloquée même avec un jeton valide pendant 15 min' );

echo "\nHTTP : $pass réussis, $fail échoués\n";
exit( $fail > 0 ? 1 : 0 );

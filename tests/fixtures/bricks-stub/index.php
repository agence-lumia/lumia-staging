<?php
/**
 * Rendu minimal : affiche les textes des éléments Bricks du contenu et du header actif.
 */

function stub_texts( $post_id, $key = '_bricks_page_content_2' ) {
	$out = array();
	foreach ( (array) get_post_meta( $post_id, $key, true ) as $el ) {
		if ( is_array( $el ) && isset( $el['settings']['text'] ) ) {
			$out[] = $el['settings']['text'];
		}
	}
	return $out;
}
?><!doctype html>
<html><head><meta charset="utf-8"><?php wp_head(); ?></head>
<body>
<?php
$active = apply_filters( 'bricks/active_templates', array( 'header' => (int) get_option( 'stub_header_template' ) ), get_queried_object_id(), 'content' );
if ( ! empty( $active['header'] ) ) {
	echo '<header data-template="' . (int) $active['header'] . '">' . esc_html( implode( ' ', stub_texts( (int) $active['header'] ) ) ) . '</header>';
}
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		echo '<main data-id="' . (int) get_the_ID() . '" data-front="' . ( is_front_page() ? '1' : '0' ) . '">';
		foreach ( stub_texts( get_the_ID() ) as $text ) {
			echo '<p>' . esc_html( $text ) . '</p>';
		}
		echo '<a id="contact" href="' . esc_url( home_url( '/contact/' ) ) . '">contact</a>';
		echo '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><button type="submit">go</button></form>';
		echo '</main>';
	}
} else {
	echo '<main>NOTFOUND</main>';
}
wp_footer();
?>
</body></html>

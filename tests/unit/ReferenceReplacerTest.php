<?php

namespace Lumia\Staging\Tests;

use Lumia\Staging\Diff\ReferenceReplacer;
use PHPUnit\Framework\TestCase;

final class ReferenceReplacerTest extends TestCase {

	public function test_replaces_id_keys_keeping_type(): void {
		$r    = new ReferenceReplacer();
		$data = array(
			array(
				'settings' => array(
					'link'  => array( 'type' => 'internal', 'postId' => 42 ),
					'query' => array( 'post_id' => '42' ),
					'width' => 42,
				),
			),
		);
		$out  = $r->replace( $data, 42, 7 );
		$this->assertSame( 7, $out[0]['settings']['link']['postId'] );
		$this->assertSame( '7', $out[0]['settings']['query']['post_id'] );
		$this->assertSame( 42, $out[0]['settings']['width'], 'Une valeur numérique quelconque ne doit pas être remplacée' );
	}

	public function test_replaces_urls_and_query_links(): void {
		$r   = new ReferenceReplacer();
		$out = $r->replace(
			'<a href="https://site.test/?page_id=42">a</a> <a href="/?p=420">b</a> {post_title:42}',
			42,
			7,
			array( 'https://site.test/?page_id=42' => 'https://site.test/accueil/' )
		);
		$this->assertStringContainsString( 'https://site.test/accueil/', $out );
		$this->assertStringContainsString( '?p=420', $out, 'Un ID plus long ne doit pas être touché' );
		$this->assertStringContainsString( '{post_title:7}', $out );
	}

	public function test_noop_when_same_id(): void {
		$r = new ReferenceReplacer();
		$this->assertSame( array( 'postId' => 3 ), $r->replace( array( 'postId' => 3 ), 3, 3 ) );
	}
}

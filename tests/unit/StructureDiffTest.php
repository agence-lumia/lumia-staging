<?php

namespace Lumia\Staging\Tests;

use Lumia\Staging\Diff\StructureDiff;
use PHPUnit\Framework\TestCase;

final class StructureDiffTest extends TestCase {

	private function el( string $id, string $text, array $children = array() ): array {
		return array( 'id' => $id, 'name' => 'text', 'parent' => 0, 'children' => $children, 'settings' => array( 'text' => $text ) );
	}

	public function test_counts_added_removed_modified(): void {
		$before = array( $this->el( 'a', 'A' ), $this->el( 'b', 'B' ), $this->el( 'c', 'C' ) );
		$after  = array( $this->el( 'a', 'A' ), $this->el( 'b', 'B2' ), $this->el( 'd', 'D' ) );
		$this->assertSame( array( 'added' => 1, 'removed' => 1, 'modified' => 1 ), StructureDiff::elements( $before, $after ) );
	}

	public function test_settings_key_order_is_ignored(): void {
		$a = array( array( 'id' => 'x', 'name' => 'div', 'settings' => array( 'a' => 1, 'b' => 2 ) ) );
		$b = array( array( 'id' => 'x', 'name' => 'div', 'settings' => array( 'b' => 2, 'a' => 1 ) ) );
		$this->assertSame( 0, StructureDiff::elements( $a, $b )['modified'] );
	}

	public function test_children_order_counts_as_modification(): void {
		$a = array( $this->el( 'p', '', array( 'x', 'y' ) ) );
		$b = array( $this->el( 'p', '', array( 'y', 'x' ) ) );
		$this->assertSame( 1, StructureDiff::elements( $a, $b )['modified'] );
	}

	public function test_handles_empty_and_invalid(): void {
		$this->assertSame( array( 'added' => 0, 'removed' => 0, 'modified' => 0 ), StructureDiff::elements( '', null ) );
		$this->assertFalse( StructureDiff::differs( '', array() ) );
		$this->assertTrue( StructureDiff::differs( '.a{}', '.b{}' ) );
	}
}

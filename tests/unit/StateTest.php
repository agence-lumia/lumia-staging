<?php

namespace Lumia\Staging\Tests;

use Lumia\Staging\Domain\State;
use Lumia\Staging\Preview\TokenRepository;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase {

	public function test_transitions_follow_the_spec(): void {
		$this->assertTrue( State::InProgress->can( State::InReview ) );
		$this->assertTrue( State::InReview->can( State::Approved ) );
		$this->assertTrue( State::InReview->can( State::InProgress ) );
		$this->assertTrue( State::Approved->can( State::Scheduled ) );
		$this->assertTrue( State::Scheduled->can( State::InProgress ) );
		$this->assertTrue( State::Scheduled->can( State::Published ) );
		$this->assertTrue( State::InProgress->can( State::Published ), 'Validation client facultative' );
		$this->assertFalse( State::Published->can( State::InProgress ) );
		$this->assertFalse( State::Abandoned->can( State::Published ) );
		$this->assertFalse( State::InProgress->can( State::Approved ) );
	}

	public function test_open_states(): void {
		$this->assertTrue( State::Scheduled->is_open() );
		$this->assertFalse( State::Published->is_open() );
		$this->assertSame( State::InProgress, State::from_meta( 'nimporte' ) );
	}

	public function test_token_format_and_hash(): void {
		$this->assertTrue( TokenRepository::well_formed( str_repeat( 'a', 43 ) ) );
		$this->assertFalse( TokenRepository::well_formed( 'court' ) );
		$this->assertFalse( TokenRepository::well_formed( str_repeat( 'a', 42 ) . '/' ) );
		$this->assertSame( 64, strlen( TokenRepository::hash( 'x' ) ) );
		$this->assertNotSame( TokenRepository::hash( 'x' ), 'x' );
	}
}

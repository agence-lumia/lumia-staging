<?php

namespace Lumia\Staging\Tests;

use Lumia\Staging\Update\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubUpdaterTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function release( string $tag, bool $prerelease = false, bool $assets = true ): array {
		$version = ltrim( $tag, 'v' );
		return array(
			'tag_name'     => $tag,
			'prerelease'   => $prerelease,
			'draft'        => false,
			'html_url'     => 'https://github.com/agence-lumia/lumia-staging/releases/tag/' . $tag,
			'published_at' => '2026-09-23T10:00:00Z',
			'body_html'    => '<p>Notes ' . $version . '</p>',
			'assets'       => $assets ? array(
				array(
					'name' => 'lumia-staging-' . $version . '.zip',
					'url'  => 'https://api.github.com/repos/agence-lumia/lumia-staging/releases/assets/' . crc32( $tag ),
				),
				array(
					'name' => 'SHA256SUMS',
					'url'  => 'https://api.github.com/repos/agence-lumia/lumia-staging/releases/assets/' . ( crc32( $tag ) + 1 ),
				),
			) : array(),
		);
	}

	public function test_stable_channel_ignores_prereleases_and_sorts_by_version(): void {
		$api = array(
			$this->release( 'v0.1.10' ),
			$this->release( 'v0.2.0-dev.3', true ),
			$this->release( 'v0.1.9' ),
			$this->release( 'v0.3.0', false, false ), // Sans archive : ignorée.
		);

		$stable = GitHubUpdater::eligible( $api, false );
		$this->assertSame( array( '0.1.10', '0.1.9' ), array_column( $stable, 'version' ) );
		$this->assertSame( 'lumia-staging-0.1.10.zip', $stable[0]['zip_name'] );
		$this->assertSame( '<p>Notes 0.1.10</p>', $stable[0]['notes'][0]['html'] );
	}

	public function test_dev_channel_takes_the_highest_version_stable_included(): void {
		$api = array(
			$this->release( 'v0.1.1-dev.2', true ),
			$this->release( 'v0.1.1-dev.10', true ),
			$this->release( 'v0.1.0' ),
		);
		$this->assertSame( array( '0.1.1-dev.10', '0.1.1-dev.2', '0.1.0' ), array_column( GitHubUpdater::eligible( $api, true ), 'version' ) );

		// Une stable sortie après les pré-versions l'emporte.
		$api[] = $this->release( 'v0.1.1' );
		$this->assertSame( '0.1.1', GitHubUpdater::eligible( $api, true )[0]['version'] );
	}

	public function test_versions_order_matches_the_release_workflows(): void {
		// release-dev.yml : pré-versions du patch suivant la dernière stable.
		$this->assertTrue( version_compare( '0.1.1-dev.1', '0.1.0', '>' ) );
		$this->assertTrue( version_compare( '0.1.1-dev.10', '0.1.1-dev.9', '>' ) );
		// release.yml : la stable passe devant ses pré-versions.
		$this->assertTrue( version_compare( '0.1.1', '0.1.1-dev.10', '>' ) );
		$this->assertTrue( version_compare( '0.2.0', '0.1.1-dev.10', '>' ) );
	}

	public function test_expected_sum_reads_sha256sum_format(): void {
		$hash = str_repeat( 'ab', 32 );
		$sums = "{$hash}  lumia-staging-0.1.0.zip\n" . str_repeat( 'cd', 32 ) . " *other.zip\r\n";
		$this->assertSame( $hash, GitHubUpdater::expected_sum( $sums, 'lumia-staging-0.1.0.zip' ) );
		$this->assertSame( str_repeat( 'cd', 32 ), GitHubUpdater::expected_sum( $sums, 'other.zip' ) );
		$this->assertSame( '', GitHubUpdater::expected_sum( $sums, 'missing.zip' ) );
	}
}

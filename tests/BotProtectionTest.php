<?php
/**
 * Tests for the Bot_Protection path matching logic.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Bot_Protection;
use Supertab_Connect\Settings;
use Yoast\PHPUnitPolyfills\Polyfills\AssertionRenames;

class BotProtectionTest extends TestCase {

	use AssertionRenames;

	private Settings $settings;

	/**
	 * Reflection method for is_path_active.
	 *
	 * @var \ReflectionMethod
	 */
	private \ReflectionMethod $is_path_active;

	/**
	 * Bot_Protection instance (constructed without a real SDK).
	 *
	 * @var Bot_Protection
	 */
	private Bot_Protection $bot;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();

		$this->settings = new Settings();

		// Use reflection to construct Bot_Protection without a real SupertabConnect.
		$ref       = new \ReflectionClass( Bot_Protection::class );
		$this->bot = $ref->newInstanceWithoutConstructor();

		// Inject the settings dependency.
		$settings_prop = $ref->getProperty( 'settings' );
		$settings_prop->setAccessible( true );
		$settings_prop->setValue( $this->bot, $this->settings );

		// Make is_path_active accessible.
		$this->is_path_active = $ref->getMethod( 'is_path_active' );
		$this->is_path_active->setAccessible( true );
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	/**
	 * Invoke is_path_active with the given request path.
	 *
	 * @param string $path The request path.
	 * @return bool
	 */
	private function is_active( string $path ): bool {
		return $this->is_path_active->invoke( $this->bot, $path );
	}

	public function test_wildcard_matches_any_path(): void {
		$this->settings->set_active_paths( array( '*' ) );
		$this->assertTrue( $this->is_active( 'any/path' ) );
	}

	public function test_pattern_matches_subpath(): void {
		$this->settings->set_active_paths( array( 'blog/*' ) );
		$this->assertTrue( $this->is_active( 'blog/my-post' ) );
	}

	public function test_pattern_does_not_match_unrelated_path(): void {
		$this->settings->set_active_paths( array( 'blog/*' ) );
		$this->assertFalse( $this->is_active( 'pricing' ) );
	}

	public function test_exact_match(): void {
		$this->settings->set_active_paths( array( 'pricing' ) );
		$this->assertTrue( $this->is_active( 'pricing' ) );
	}

	public function test_exact_does_not_match_different_path(): void {
		$this->settings->set_active_paths( array( 'pricing' ) );
		$this->assertFalse( $this->is_active( 'about' ) );
	}

	public function test_multiple_patterns_matches_second(): void {
		$this->settings->set_active_paths( array( 'blog/*', 'news/*' ) );
		$this->assertTrue( $this->is_active( 'news/article' ) );
	}

	public function test_multiple_patterns_no_match(): void {
		$this->settings->set_active_paths( array( 'blog/*', 'news/*' ) );
		$this->assertFalse( $this->is_active( 'about' ) );
	}

	public function test_default_paths_match_everything(): void {
		// No active paths set — default is ['*'].
		$this->assertTrue( $this->is_active( 'some/random/path' ) );
	}

	public function test_nested_wildcard(): void {
		$this->settings->set_active_paths( array( 'archives/2026/*' ) );
		$this->assertTrue( $this->is_active( 'archives/2026/my-post' ) );
		$this->assertFalse( $this->is_active( 'archives/2025/old-post' ) );
	}
}

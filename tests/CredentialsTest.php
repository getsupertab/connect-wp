<?php
/**
 * Tests for the Credentials class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Credentials;
use Yoast\PHPUnitPolyfills\Polyfills\AssertionRenames;

class CredentialsTest extends TestCase {

	use AssertionRenames;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	public function test_get_merchant_api_key_returns_empty_string_when_not_set(): void {
		$credentials = new Credentials();
		$this->assertSame( '', $credentials->get_merchant_api_key() );
	}

	public function test_get_website_urn_returns_empty_string_when_not_set(): void {
		$credentials = new Credentials();
		$this->assertSame( '', $credentials->get_website_urn() );
	}

	public function test_has_credentials_returns_false_when_not_set(): void {
		$credentials = new Credentials();
		$this->assertFalse( $credentials->has_credentials() );
	}

	public function test_save_stores_credentials(): void {
		$credentials = new Credentials();
		$credentials->save( 'test-api-key', 'urn:supertab:merchant-system:123' );

		$this->assertSame( 'test-api-key', $credentials->get_merchant_api_key() );
		$this->assertSame( 'urn:supertab:merchant-system:123', $credentials->get_website_urn() );
	}

	public function test_has_credentials_returns_true_when_both_set(): void {
		$credentials = new Credentials();
		$credentials->save( 'test-api-key', 'urn:supertab:merchant-system:123' );

		$this->assertTrue( $credentials->has_credentials() );
	}

	public function test_has_credentials_returns_false_when_only_api_key_set(): void {
		$credentials = new Credentials();

		global $wp_test_options;
		$wp_test_options['supertab_connect_merchant_api_key'] = 'test-api-key';

		$this->assertFalse( $credentials->has_credentials() );
	}

	public function test_has_credentials_returns_false_when_only_urn_set(): void {
		$credentials = new Credentials();

		global $wp_test_options;
		$wp_test_options['supertab_connect_website_urn'] = 'urn:supertab:merchant-system:123';

		$this->assertFalse( $credentials->has_credentials() );
	}

	public function test_delete_removes_credentials(): void {
		$credentials = new Credentials();
		$credentials->save( 'test-api-key', 'urn:supertab:merchant-system:123' );
		$credentials->delete();

		$this->assertSame( '', $credentials->get_merchant_api_key() );
		$this->assertSame( '', $credentials->get_website_urn() );
		$this->assertFalse( $credentials->has_credentials() );
	}

	public function test_save_invalidates_license_xml_cache(): void {
		global $wp_test_transients;
		$wp_test_transients['supertab_connect_license_xml'] = '<xml>cached</xml>';

		$credentials = new Credentials();
		$credentials->save( 'new-key', 'new-urn' );

		$this->assertArrayNotHasKey( 'supertab_connect_license_xml', $wp_test_transients );
	}

	public function test_delete_invalidates_license_xml_cache(): void {
		global $wp_test_transients;
		$wp_test_transients['supertab_connect_license_xml'] = '<xml>cached</xml>';

		$credentials = new Credentials();
		$credentials->delete();

		$this->assertArrayNotHasKey( 'supertab_connect_license_xml', $wp_test_transients );
	}

	public function test_save_overwrites_existing_credentials(): void {
		$credentials = new Credentials();
		$credentials->save( 'old-key', 'old-urn' );
		$credentials->save( 'new-key', 'new-urn' );

		$this->assertSame( 'new-key', $credentials->get_merchant_api_key() );
		$this->assertSame( 'new-urn', $credentials->get_website_urn() );
	}
}

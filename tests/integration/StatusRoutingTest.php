<?php
/**
 * Integration test covering the /.well-known/supertab/status route.
 *
 * Proves WP's rewrite pipeline delivers the dot-prefixed path to
 * parse_request handlers. Response behavior is covered by unit tests
 * (tests/StatusHandlerTest.php); the registered handler exit()s on a
 * path match, so parse_request callbacks are removed before go_to().
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use WP_UnitTestCase;

class StatusRoutingTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Pretty permalinks are required for WP::parse_request() to populate
		// $wp->request from the URL path (see LicenseRoutingTest).
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function test_well_known_status_path_resolves_to_parse_request(): void {
		// The status handler exit()s after responding; drop parse_request
		// callbacks so go_to() completes and the parsed path is inspectable.
		remove_all_actions( 'parse_request' );

		$this->go_to( home_url( '/.well-known/supertab/status' ) );

		$this->assertSame( '.well-known/supertab/status', $GLOBALS['wp']->request );
	}
}

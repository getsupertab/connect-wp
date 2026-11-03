<?php
/**
 * Tests for the PKCE helper.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Pkce;

class PkceTest extends TestCase {

	public function test_challenge_matches_the_rfc_7636_example(): void {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	public function test_random_token_is_43_base64url_characters(): void {
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', Pkce::random_token() );
	}

	public function test_random_tokens_differ(): void {
		$this->assertNotSame( Pkce::random_token(), Pkce::random_token() );
	}
}

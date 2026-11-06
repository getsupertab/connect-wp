<?php
/**
 * Tests for the site address helpers.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Utils\Site_Address;

class SiteAddressTest extends TestCase {

	/**
	 * @dataProvider addresses
	 */
	public function test_host_and_origin( string $url, string $host, string $origin ): void {
		$this->assertSame( $host, Site_Address::host( $url ) );
		$this->assertSame( $origin, Site_Address::origin( $url ) );
	}

	public static function addresses(): array {
		return array(
			'mixed case, with a path' => array( 'https://News.Example.com/x', 'news.example.com', 'https://news.example.com' ),
			'local port'              => array( 'http://localhost:8888', 'localhost:8888', 'http://localhost:8888' ),
			'default HTTPS port'      => array( 'https://example.com:443/news', 'example.com', 'https://example.com' ),
			'default HTTP port'       => array( 'http://example.com:80', 'example.com', 'http://example.com' ),
			'other port'              => array( 'https://example.com:8443', 'example.com:8443', 'https://example.com:8443' ),
			'not a URL'               => array( 'not a url', '', '' ),
		);
	}
}

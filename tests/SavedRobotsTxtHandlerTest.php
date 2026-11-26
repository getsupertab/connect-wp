<?php
/**
 * Tests for the Saved_Robots_Txt_Handler class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Supertab_Connect\Saved_Robots_Txt_Handler;
use Supertab_Connect\Settings;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClientInterface;

class SavedRobotsTxtHandlerTest extends TestCase {

	private const KEY = 'supertab_connect_robots_txt';

	private const URN = 'urn:stc:merchant:system:abc';

	private const FILE = "User-agent: *\nDisallow: /private/\n";

	private Settings $settings;

	/**
	 * @var HttpClientInterface&MockObject
	 */
	private HttpClientInterface $http_client;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();

		$this->settings    = new Settings();
		$this->http_client = $this->createMock( HttpClientInterface::class );

		if ( 'test_no_urn_makes_no_request' !== $this->getName( false ) ) {
			$this->settings->save_website_urn( self::URN );
		}
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	private function handler(): Saved_Robots_Txt_Handler {
		return new Saved_Robots_Txt_Handler( $this->settings, 'https://api-connect.sbx.supertab.co/', $this->http_client );
	}

	private function api_answers( int $status, string $body = '' ): void {
		$this->http_client->method( 'get' )->willReturn( array( 'statusCode' => $status, 'body' => $body ) );
	}

	private function stored( ?string $body, bool $failed, int $retry_in ): void {
		set_transient( self::KEY, array( 'body' => $body, 'failed' => $failed, 'retry_at' => time() + $retry_in ) );
	}

	/**
	 * @return mixed The stored record, or false when none is stored.
	 */
	private function record() {
		return get_transient( self::KEY );
	}

	public function test_no_urn_makes_no_request(): void {
		$this->http_client->expects( $this->never() )->method( 'get' );

		$this->assertSame( array( 'body' => null, 'failed' => false ), $this->handler()->lookup() );
		$this->assertFalse( $this->record() );
	}

	public function test_requests_the_public_robots_txt_url(): void {
		$this->http_client
			->expects( $this->once() )
			->method( 'get' )
			->with( 'https://api-connect.sbx.supertab.co/merchants/systems/urn:stc:merchant:system:abc/robots.txt' )
			->willReturn( array( 'statusCode' => 200, 'body' => self::FILE ) );

		$this->handler()->lookup();
	}

	public function test_a_saved_file_is_returned_and_stored(): void {
		$this->api_answers( 200, self::FILE );

		$this->assertSame( array( 'body' => self::FILE, 'failed' => false ), $this->handler()->lookup() );

		$record = $this->record();
		$this->assertSame( self::FILE, $record['body'] );
		$this->assertFalse( $record['failed'] );
		$this->assertEqualsWithDelta( time() + 300, $record['retry_at'], 2 );
	}

	public function test_a_fresh_record_makes_no_request(): void {
		$this->stored( self::FILE, false, 100 );
		$this->http_client->expects( $this->never() )->method( 'get' );

		$this->assertSame( self::FILE, $this->handler()->lookup()['body'] );
	}

	public function test_nothing_saved_is_remembered(): void {
		$this->http_client
			->expects( $this->once() )
			->method( 'get' )
			->willReturn( array( 'statusCode' => 404, 'body' => 'No saved robots.txt' ) );

		$handler = $this->handler();

		$this->assertSame( array( 'body' => null, 'failed' => false ), $handler->lookup() );
		$this->assertSame( array( 'body' => null, 'failed' => false ), $handler->lookup() );
	}

	public function test_a_stale_record_is_refreshed(): void {
		$this->stored( 'old', false, -1 );
		$this->api_answers( 200, self::FILE );

		$this->assertSame( self::FILE, $this->handler()->lookup()['body'] );
	}

	public function test_a_failed_fetch_keeps_the_previous_file(): void {
		$this->stored( self::FILE, false, -1 );
		$this->api_answers( 500 );

		$this->assertSame( array( 'body' => self::FILE, 'failed' => true ), $this->handler()->lookup() );
		$this->assertEqualsWithDelta( time() + 60, $this->record()['retry_at'], 2 );
	}

	public function test_a_failed_fetch_without_a_previous_file(): void {
		$this->http_client->method( 'get' )->willThrowException( new HttpException( 'down', 0 ) );

		$this->assertSame( array( 'body' => null, 'failed' => true ), $this->handler()->lookup() );

		$record = $this->record();
		$this->assertIsArray( $record );
		$this->assertEqualsWithDelta( time() + 60, $record['retry_at'], 2 );
	}

	public function test_a_failed_fetch_is_not_retried_for_a_minute(): void {
		$this->http_client
			->expects( $this->once() )
			->method( 'get' )
			->willReturn( array( 'statusCode' => 503, 'body' => '' ) );

		$handler = $this->handler();
		$handler->lookup();

		$this->assertTrue( $handler->lookup()['failed'] );
	}

	public function test_any_throwable_counts_as_a_failure(): void {
		$this->http_client->method( 'get' )->willThrowException( new \Error( 'boom' ) );

		$this->assertTrue( $this->handler()->lookup()['failed'] );
	}

	public function test_an_empty_body_is_a_failure(): void {
		$this->api_answers( 200, " \n" );

		$this->assertSame( array( 'body' => null, 'failed' => true ), $this->handler()->lookup() );
	}

	public function test_an_oversized_body_is_a_failure(): void {
		$this->api_answers( 200, str_repeat( 'a', 1048577 ) );

		$this->assertTrue( $this->handler()->lookup()['failed'] );
	}

	public function test_a_body_of_exactly_the_cap_is_accepted(): void {
		$this->api_answers( 200, str_repeat( 'a', 1048576 ) );

		$this->assertFalse( $this->handler()->lookup()['failed'] );
	}

	public function test_an_html_page_is_a_failure(): void {
		$this->api_answers( 200, "\n<!DOCTYPE html><html>blocked</html>" );

		$this->assertSame( array( 'body' => null, 'failed' => true ), $this->handler()->lookup() );
	}

	public function test_the_file_is_kept_byte_for_byte(): void {
		$file = "User-agent: *\r\nDisallow: /caf\u{e9}/ \r\n\r\n";
		$this->api_answers( 200, $file );

		$this->assertSame( $file, $this->handler()->lookup()['body'] );
		$this->assertSame( $file, $this->record()['body'] );
	}

	/**
	 * @dataProvider malformed_records
	 *
	 * @param mixed $record A stored value that isn't the shape the handler writes.
	 */
	public function test_a_malformed_record_counts_as_absent( $record ): void {
		set_transient( self::KEY, $record );
		$this->api_answers( 200, self::FILE );

		$this->assertSame( array( 'body' => self::FILE, 'failed' => false ), $this->handler()->lookup() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed_records(): array {
		return array(
			'a string'                 => array( 'text' ),
			'a body of the wrong type' => array( array( 'body' => 5 ) ),
			'missing fields'           => array( array( 'body' => self::FILE ) ),
			'an object'                => array( new \stdClass() ),
		);
	}

	public function test_nothing_saved_replaces_a_previous_file(): void {
		$this->stored( self::FILE, false, -1 );
		$this->api_answers( 404 );

		$this->assertSame( array( 'body' => null, 'failed' => false ), $this->handler()->lookup() );
	}
}

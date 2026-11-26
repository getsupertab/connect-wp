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

		if ( ! in_array( $this->getName( false ), array( 'test_no_urn_makes_no_request', 'test_off_without_a_urn' ), true ) ) {
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

	public function test_requests_during_a_refresh_keep_the_current_copy(): void {
		$this->stored( self::FILE, false, -1 );
		$during_fetch = null;
		$this->http_client->method( 'get' )->willReturnCallback(
			function () use ( &$during_fetch ) {
				$during_fetch = get_transient( self::KEY );
				return array( 'statusCode' => 503, 'body' => '' );
			}
		);

		$this->handler()->lookup();

		$this->assertSame( self::FILE, $during_fetch['body'] );
		$this->assertGreaterThan( time(), $during_fetch['retry_at'], 'Another request arriving mid-fetch must not fetch too.' );
	}

	public function test_a_copy_marked_stale_is_fetched_again(): void {
		$this->stored( 'old', false, 100 );
		$this->http_client
			->expects( $this->once() )
			->method( 'get' )
			->willReturn( array( 'statusCode' => 200, 'body' => self::FILE ) );

		Saved_Robots_Txt_Handler::mark_stale();

		$this->assertSame( array( 'body' => self::FILE, 'failed' => false ), $this->handler()->lookup() );
	}

	public function test_a_copy_marked_stale_is_kept_when_the_fetch_fails(): void {
		$this->stored( self::FILE, false, 100 );
		$this->api_answers( 503 );

		Saved_Robots_Txt_Handler::mark_stale();

		$this->assertSame( array( 'body' => self::FILE, 'failed' => true ), $this->handler()->lookup() );
	}

	public function test_marking_stale_without_a_copy_stores_nothing(): void {
		Saved_Robots_Txt_Handler::mark_stale();

		$this->assertFalse( $this->record() );
	}

	public function test_nothing_saved_replaces_a_previous_file(): void {
		$this->stored( self::FILE, false, -1 );
		$this->api_answers( 404 );

		$this->assertSame( array( 'body' => null, 'failed' => false ), $this->handler()->lookup() );
	}

	private function on(): void {
		$this->settings->set_robots_txt_serving_enabled( true );
	}

	public function test_off_by_default(): void {
		$this->http_client->expects( $this->never() )->method( 'get' );
		$handler = $this->handler();

		$this->assertSame( 'off', $handler->blocked_by() );
		$this->assertNull( $handler->body_to_serve() );
	}

	public function test_off_without_a_urn(): void {
		$this->on();
		$this->http_client->expects( $this->never() )->method( 'get' );

		$this->assertSame( 'off', $this->handler()->blocked_by() );
	}

	/**
	 * @dataProvider discouraged_values
	 *
	 * @param mixed $blog_public The stored "Discourage search engines" option.
	 */
	public function test_discouraged_sites_keep_their_own_file( $blog_public ): void {
		global $wp_test_options;
		$this->on();
		$wp_test_options['blog_public'] = $blog_public;
		$this->http_client->expects( $this->never() )->method( 'get' );
		$handler = $this->handler();

		$this->assertSame( 'discouraged', $handler->blocked_by() );
		$this->assertNull( $handler->body_to_serve() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function discouraged_values(): array {
		return array(
			'integer 0' => array( 0 ),
			'string 0'  => array( '0' ),
		);
	}

	/**
	 * @dataProvider public_values
	 *
	 * @param mixed $blog_public The stored "Discourage search engines" option, or null for none.
	 */
	public function test_public_sites_are_not_blocked( $blog_public ): void {
		global $wp_test_options;
		$this->on();
		if ( null !== $blog_public ) {
			$wp_test_options['blog_public'] = $blog_public;
		}

		$this->assertNull( $this->handler()->blocked_by() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function public_values(): array {
		return array(
			'option missing' => array( null ),
			'integer 1'      => array( 1 ),
			'string 1'       => array( '1' ),
		);
	}

	public function test_serves_the_saved_file(): void {
		$this->on();
		$this->stored( self::FILE, false, 100 );
		$handler = $this->handler();

		$this->assertSame( 'serving', $handler->status() );
		$this->assertSame( self::FILE, $handler->body_to_serve() );
	}

	public function test_nothing_saved(): void {
		$this->on();
		$this->stored( null, false, 100 );
		$handler = $this->handler();

		$this->assertSame( 'not_saved', $handler->status() );
		$this->assertNull( $handler->body_to_serve() );
	}

	public function test_unreachable_with_a_previous_file(): void {
		$this->on();
		$this->stored( self::FILE, true, 30 );
		$handler = $this->handler();

		$this->assertSame( 'unreachable_stale', $handler->status() );
		$this->assertSame( self::FILE, $handler->body_to_serve() );
	}

	public function test_unreachable_without_a_file(): void {
		$this->on();
		$this->stored( null, true, 30 );
		$handler = $this->handler();

		$this->assertSame( 'unreachable', $handler->status() );
		$this->assertNull( $handler->body_to_serve() );
	}

	public function test_registers_on_parse_request_after_bot_protection(): void {
		global $wp_test_actions;

		$this->handler()->register();

		$this->assertSame( 'parse_request', $wp_test_actions[0]['hook'] );
		$this->assertSame( 10, $wp_test_actions[0]['priority'] );
	}
}

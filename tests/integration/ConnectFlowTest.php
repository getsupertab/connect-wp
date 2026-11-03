<?php
/**
 * Integration tests for the connect flow handlers.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Admin\Connect_Flow;
use Supertab_Connect\Connect_Api;
use Supertab_Connect\Pkce;
use Supertab_Connect\Settings;
use WP_UnitTestCase;
use WPDieException;

class ConnectFlowTest extends WP_UnitTestCase {

	private Connect_Flow $flow;

	private Settings $settings;

	private int $admin_id;

	/**
	 * Requests sent to the connect API.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The API's answer: a response array or a WP_Error.
	 *
	 * @var array<string, mixed>|\WP_Error
	 */
	private $api_response;

	protected function setUp(): void {
		parent::setUp();
		catch_redirects();
		// admin_url() takes its scheme from the request, as for a merchant on an HTTPS admin.
		$_SERVER['HTTPS'] = 'on';

		$this->settings = new Settings();
		$this->flow     = new Connect_Flow( $this->settings, new Connect_Api( 'https://api.test' ) );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->use_urls( 'https://news.example.com', 'https://news.example.com' );
		update_option( 'blogname', 'News' );

		$this->api_responds(
			200,
			array(
				'website_urn'  => 'urn:supertab:website:42',
				'website_name' => 'News',
				'base_url'     => 'https://news.example.com',
				'api_key'      => 'connect-key',
				'reconnected'  => false,
			)
		);
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->api_response;
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTPS'] );
		parent::tearDown();
	}

	private function use_urls( string $home, string $siteurl ): void {
		add_filter( 'pre_option_home', static fn() => $home );
		add_filter( 'pre_option_siteurl', static fn() => $siteurl );
	}

	private function api_responds( int $status, $body ): void {
		$this->api_response = array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Run a handler and return where it redirected.
	 */
	private function redirect_of( callable $handler ): string {
		try {
			$handler();
		} catch ( Redirect_Caught $redirect ) {
			return $redirect->location;
		}
		$this->fail( 'The handler did not redirect.' );
	}

	private function start(): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'supertab_connect_start' );
		return $this->redirect_of( array( $this->flow, 'start' ) );
	}

	/**
	 * Store a flow the way start() does and return its state and verifier.
	 *
	 * @return array{state: string, verifier: string}
	 */
	private function begin_flow( ?int $user_id = null ): array {
		$state    = Pkce::random_token();
		$verifier = Pkce::random_token();
		set_transient(
			'supertab_connect_flow_' . $state,
			array(
				'verifier' => $verifier,
				'user_id'  => $user_id ?? $this->admin_id,
			),
			30 * MINUTE_IN_SECONDS
		);
		return array(
			'state'    => $state,
			'verifier' => $verifier,
		);
	}

	/**
	 * Hit the callback with $query (a valid nonce unless given) and return the settings-page query.
	 *
	 * @param array<string, string> $query Callback query.
	 * @return array<string, string>
	 */
	private function come_back( array $query ): array {
		$_GET = array_merge( array( '_wpnonce' => wp_create_nonce( 'supertab_connect_callback' ) ), $query );
		return query_of( $this->redirect_of( array( $this->flow, 'callback' ) ) );
	}

	public function test_start_sends_the_merchant_to_the_portal_with_the_link_it_checks(): void {
		$location = $this->start();

		$this->assertStringStartsWith( 'https://merchant-connect.supertab.co/connect/wordpress?', $location );
		$params = query_of( $location );
		$flow   = get_transient( 'supertab_connect_flow_' . $params['state'] );
		$this->assertSame( $this->admin_id, $flow['user_id'] );
		$this->assertSame( 'https://news.example.com', $params['site_url'] );
		$this->assertSame( 'News', $params['site_name'] );
		$this->assertSame( Pkce::challenge( $flow['verifier'] ), $params['code_challenge'] );
		$this->assertSame( 'S256', $params['code_challenge_method'] );

		$this->assertStringStartsWith( 'https://news.example.com/wp-admin/admin-post.php?', $params['return_url'] );
		$return = query_of( $params['return_url'] );
		$this->assertSame( 'supertab_connect_callback', $return['action'] );
		$this->assertNotFalse( wp_verify_nonce( $return['_wpnonce'], 'supertab_connect_callback' ) );
	}

	public function test_start_refuses_a_forged_request(): void {
		$_REQUEST['_wpnonce'] = 'forged';

		$this->expectException( WPDieException::class );
		$this->flow->start();
	}

	public function test_start_refuses_a_user_who_cannot_manage_the_plugin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'supertab_connect_start' );

		$this->expectException( WPDieException::class );
		$this->flow->start();
	}

	public function test_start_explains_an_admin_on_another_host(): void {
		$this->use_urls( 'https://example.com', 'https://admin.example.com' );

		$this->assertSame( 'admin_host_mismatch', query_of( $this->start() )['connect_error'] );
	}

	public function test_start_explains_an_admin_on_plain_http(): void {
		unset( $_SERVER['HTTPS'] );
		$this->use_urls( 'http://example.com', 'http://example.com' );

		$this->assertSame( 'insecure_admin', query_of( $this->start() )['connect_error'] );
	}

	public function test_start_allows_a_local_site_on_http(): void {
		unset( $_SERVER['HTTPS'] );
		$this->use_urls( 'http://localhost:8888', 'http://localhost:8888' );

		$this->assertStringStartsWith( 'https://merchant-connect.supertab.co/connect/wordpress?', $this->start() );
	}

	public function test_start_sends_a_subdirectory_install_with_its_path(): void {
		$this->use_urls( 'https://example.com/news', 'https://example.com/news' );

		$this->assertSame( 'https://example.com/news', query_of( $this->start() )['site_url'] );
	}

	public function test_start_decodes_the_site_title(): void {
		update_option( 'blogname', 'News &amp; Views' );

		$this->assertSame( 'News & Views', query_of( $this->start() )['site_name'] );
	}

	public function test_start_names_an_untitled_site_after_its_host(): void {
		update_option( 'blogname', '' );

		$this->assertSame( 'news.example.com', query_of( $this->start() )['site_name'] );
	}

	public function test_callback_saves_the_credentials_from_the_code(): void {
		$flow = $this->begin_flow();

		$query = $this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( 'connected', $query['connect'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.test/connect/websites', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'code'          => 'one-time-code',
				'code_verifier' => $flow['verifier'],
				'name'          => 'News',
				'base_url'      => 'https://news.example.com',
			),
			json_decode( $this->requests[0]['args']['body'], true )
		);
		$this->assertSame( 'connect-key', $this->settings->get_merchant_api_key() );
		$this->assertSame( 'urn:supertab:website:42', $this->settings->get_website_urn() );
		$connection = $this->settings->get_connection();
		$this->assertSame( 'connect', $connection['method'] );
		$this->assertSame( 'News', $connection['website_name'] );
		$this->assertSame( 'https://news.example.com', $connection['base_url'] );
		$this->assertFalse( get_transient( 'supertab_connect_flow_' . $flow['state'] ) );
	}

	public function test_callback_sends_a_subdirectory_install_with_its_path(): void {
		$this->use_urls( 'https://example.com/news', 'https://example.com/news' );
		$flow = $this->begin_flow();

		$this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( 'https://example.com/news', json_decode( $this->requests[0]['args']['body'], true )['base_url'] );
	}

	public function test_callback_reports_a_cancelled_connection(): void {
		$flow = $this->begin_flow();

		$query = $this->come_back(
			array(
				'error' => 'access_denied',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( 'cancelled', $query['connect'] );
		$this->assertSame( array(), $this->requests );
		$this->assertFalse( get_transient( 'supertab_connect_flow_' . $flow['state'] ) );
	}

	public function test_callback_rejects_an_unknown_state(): void {
		$query = $this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => 'unknown',
			)
		);

		$this->assertSame( 'invalid_state', $query['connect_error'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', $this->settings->get_merchant_api_key() );
	}

	public function test_callback_rejects_a_forged_nonce(): void {
		$flow = $this->begin_flow();

		$query = $this->come_back(
			array(
				'_wpnonce' => 'forged',
				'code'     => 'one-time-code',
				'state'    => $flow['state'],
			)
		);

		$this->assertSame( 'invalid_state', $query['connect_error'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_callback_rejects_a_flow_another_user_started(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$flow  = $this->begin_flow( $other );

		$query = $this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( 'wrong_user', $query['connect_error'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', $this->settings->get_merchant_api_key() );
	}

	public function test_a_replayed_callback_changes_nothing(): void {
		$flow  = $this->begin_flow();
		$query = array(
			'code'  => 'one-time-code',
			'state' => $flow['state'],
		);
		$this->come_back( $query );
		$this->api_responds(
			200,
			array(
				'website_urn'  => 'urn:supertab:website:other',
				'website_name' => 'Other',
				'base_url'     => 'https://news.example.com',
				'api_key'      => 'other-key',
			)
		);

		$this->assertSame( 'invalid_state', $this->come_back( $query )['connect_error'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'connect-key', $this->settings->get_merchant_api_key() );
	}

	/**
	 * @dataProvider api_failures
	 */
	public function test_callback_reports_an_api_failure_and_saves_nothing( int $status, $body, string $error ): void {
		$this->api_responds( $status, $body );
		$flow = $this->begin_flow();

		$query = $this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( $error, $query['connect_error'] );
		$this->assertSame( '', $this->settings->get_merchant_api_key() );
		$this->assertNull( $this->settings->get_connection() );
	}

	public static function api_failures(): array {
		return array(
			'expired code'   => array( 400, array( 'detail' => array( 'code' => 'invalid_code' ) ), 'invalid_code' ),
			'different site' => array( 400, array( 'detail' => array( 'code' => 'site_mismatch' ) ), 'site_mismatch' ),
			'Supertab down'  => array( 503, 'upstream', 'unreachable' ),
		);
	}

	public function test_connecting_a_manually_connected_site_switches_it_to_connect(): void {
		$this->settings->save( 'pasted-key', 'urn:supertab:website:manual' );
		$this->settings->save_connection( 'manual', '', 'https://news.example.com' );
		$flow = $this->begin_flow();

		$this->come_back(
			array(
				'code'  => 'one-time-code',
				'state' => $flow['state'],
			)
		);

		$this->assertSame( 'connect-key', $this->settings->get_merchant_api_key() );
		$this->assertSame( 'urn:supertab:website:42', $this->settings->get_website_urn() );
		$this->assertSame( 'connect', $this->settings->get_connection_method() );
	}
}

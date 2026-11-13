<?php
/**
 * One-click Connect my website flow.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

use Supertab_Connect\Analytics_Dispatcher;
use Supertab_Connect\Analytics_Queue_Table;
use Supertab_Connect\Connect_Api;
use Supertab_Connect\Connect_Api_Error;
use Supertab_Connect\Pkce;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\Site_Address;

/**
 * Sends the merchant to the portal to approve, then exchanges the returned code for credentials.
 */
final class Connect_Flow {

	public const START_ACTION      = 'supertab_connect_start';
	public const CALLBACK_ACTION   = 'supertab_connect_callback';
	public const DISCONNECT_ACTION = 'supertab_connect_disconnect';

	private const FLOW_TRANSIENT_PREFIX = 'supertab_connect_flow_';
	private const FLOW_TTL              = 30 * MINUTE_IN_SECONDS;
	private const NAME_MAX_LENGTH       = 255;
	private const LOCAL_HOSTS           = array( 'localhost', '127.0.0.1' );

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Connect API client.
	 *
	 * @var Connect_Api
	 */
	private Connect_Api $api;

	/**
	 * Analytics queue, emptied on disconnect.
	 *
	 * @var Analytics_Queue_Table
	 */
	private Analytics_Queue_Table $queue;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings Plugin settings.
	 * @param Connect_Api           $api      Connect API client.
	 * @param Analytics_Queue_Table $queue    Analytics queue.
	 */
	public function __construct( Settings $settings, Connect_Api $api, Analytics_Queue_Table $queue ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->queue    = $queue;
	}

	/**
	 * Register the admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::CALLBACK_ACTION, array( $this, 'callback' ) );
		add_action( 'admin_post_nopriv_' . self::CALLBACK_ACTION, array( $this, 'callback_signed_out' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'disconnect' ) );
	}

	/**
	 * Store a state and PKCE verifier, then send the merchant to the portal to sign in and approve.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->authorize();
		check_admin_referer( self::START_ACTION );

		$problem = $this->site_problem();
		if ( null !== $problem ) {
			$this->back( array( 'connect_error' => $problem ) );
		}

		$state    = Pkce::random_token();
		$verifier = Pkce::random_token();
		set_transient(
			self::FLOW_TRANSIENT_PREFIX . $state,
			array(
				'verifier' => $verifier,
				'user_id'  => get_current_user_id(),
			),
			self::FLOW_TTL
		);

		$return_url = add_query_arg(
			array(
				'action'   => self::CALLBACK_ACTION,
				'_wpnonce' => wp_create_nonce( self::CALLBACK_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
		$portal_url = add_query_arg(
			rawurlencode_deep(
				array(
					'site_url'              => home_url(),
					'site_name'             => $this->site_name(),
					'return_url'            => $return_url,
					'state'                 => $state,
					'code_challenge'        => Pkce::challenge( $verifier ),
					'code_challenge_method' => 'S256',
				)
			),
			SUPERTAB_CONNECT_DASHBOARD_URL . '/websites/setup/wordpress'
		);

		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_dashboard_host' ) );
		wp_safe_redirect( $portal_url );
		exit;
	}

	/**
	 * Handle the portal's redirect back: check nonce, state and user, then redeem the code.
	 *
	 * @return void
	 */
	public function callback(): void {
		$this->authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified below, together with the state.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// A state is good for one return only, so a refresh or Back can't replay it.
		$flow = '' !== $state ? get_transient( self::FLOW_TRANSIENT_PREFIX . $state ) : false;
		if ( '' !== $state ) {
			delete_transient( self::FLOW_TRANSIENT_PREFIX . $state );
		}

		if ( ! wp_verify_nonce( $nonce, self::CALLBACK_ACTION ) || ! is_array( $flow ) ) {
			$this->back( array( 'connect_error' => 'invalid_state' ) );
		}
		if ( (int) ( $flow['user_id'] ?? 0 ) !== get_current_user_id() ) {
			$this->back( array( 'connect_error' => 'wrong_user' ) );
		}
		if ( 'access_denied' === $error ) {
			$this->back( array( 'connect' => 'cancelled' ) );
		}
		if ( '' === $code ) {
			$this->back( array( 'connect_error' => 'failed' ) );
		}

		try {
			$credentials = $this->api->redeem( $code, (string) $flow['verifier'], $this->site_name(), home_url() );
		} catch ( Connect_Api_Error $e ) {
			$this->back( array( 'connect_error' => $e->reason ) );
		}

		$this->settings->save( $credentials['api_key'], $credentials['website_urn'] );
		$this->settings->save_connection( 'connect', $credentials['website_name'], $credentials['base_url'] );
		$this->back( array( 'connect' => 'connected' ) );
	}

	/**
	 * Handle a return after the merchant's WordPress session ended: sign in, then start over.
	 *
	 * The callback nonce belongs to the old session, so the flow can't resume after signing in.
	 *
	 * @return void
	 */
	public function callback_signed_out(): void {
		$settings_url = add_query_arg(
			array(
				'page'          => Settings_Page::PAGE_SLUG,
				'connect_error' => 'invalid_state',
			),
			admin_url( 'options-general.php' )
		);

		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( wp_login_url( $settings_url ) );
		exit;
	}

	/**
	 * Forget the connected website, revoking the key first when the connect flow issued it.
	 *
	 * Pasted keys may be used elsewhere, so they are only cleared here. When Supertab
	 * can't be reached, nothing is cleared until the merchant chooses Disconnect anyway.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->authorize();
		check_admin_referer( self::DISCONNECT_ACTION );

		$force   = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		$api_key = $this->settings->get_merchant_api_key();
		if ( ! $force && 'connect' === $this->settings->get_connection_method() && '' !== $api_key && ! $this->api->disconnect( $api_key ) ) {
			$this->back( array( 'disconnect' => 'unreachable' ) );
		}

		$this->settings->clear_connection();
		$this->queue->clear();
		Analytics_Dispatcher::clear_scheduled();
		$this->back( array( 'connect' => 'disconnected' ) );
	}

	/**
	 * Let wp_safe_redirect() send the merchant to the portal.
	 *
	 * @param array<int, string> $hosts Allowed hosts.
	 * @return array<int, string>
	 */
	public function allow_dashboard_host( array $hosts ): array {
		$hosts[] = (string) wp_parse_url( SUPERTAB_CONNECT_DASHBOARD_URL, PHP_URL_HOST );
		return $hosts;
	}

	/**
	 * Let wp_safe_redirect() return to an admin on its own host.
	 *
	 * @param array<int, string> $hosts Allowed hosts.
	 * @return array<int, string>
	 */
	public function allow_admin_host( array $hosts ): array {
		$hosts[] = (string) wp_parse_url( admin_url(), PHP_URL_HOST );
		return $hosts;
	}

	/**
	 * Why the portal would reject this site's return URL, or null when it won't.
	 *
	 * @return string|null "admin_host_mismatch" or "insecure_admin".
	 */
	private function site_problem(): ?string {
		$admin_url = admin_url();
		if ( Site_Address::host( $admin_url ) !== Site_Address::host( home_url() ) ) {
			return 'admin_host_mismatch';
		}

		$scheme   = strtolower( (string) wp_parse_url( $admin_url, PHP_URL_SCHEME ) );
		$hostname = strtolower( (string) wp_parse_url( $admin_url, PHP_URL_HOST ) );
		if ( 'https' !== $scheme && ! in_array( $hostname, self::LOCAL_HOSTS, true ) ) {
			return 'insecure_admin';
		}

		return null;
	}

	/**
	 * The Site Title, or the host when it's empty, at most 255 characters.
	 *
	 * @return string
	 */
	private function site_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === $name ) {
			$name = Site_Address::host( home_url() );
		}

		return mb_substr( $name, 0, self::NAME_MAX_LENGTH );
	}

	/**
	 * Stop unless the current user may manage the plugin.
	 *
	 * @return void
	 */
	private function authorize(): void {
		if ( ! current_user_can( Access::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'supertab-connect' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect to the settings page with a result flag.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return never
	 */
	private function back( array $args ): never {
		// The admin can live on another host than home_url(), the only host wp_safe_redirect() allows by default.
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => Settings_Page::PAGE_SLUG ), $args ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}

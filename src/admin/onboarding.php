<?php
/**
 * Onboarding screen controller.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

use Supertab_Connect\Credentials;

/**
 * Handles the onboarding setup page for entering OAuth credentials.
 */
class Onboarding {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'supertab-connect-setup';

	/**
	 * Nonce action for the setup form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'supertab_connect_setup';

	/**
	 * Credentials instance.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials  Credentials manager.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_activation_redirect' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Register the hidden admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'options-general.php', // Register under Settings so WordPress populates $title.
			__( 'Supertab Connect Setup', 'supertab-connect' ),
			'',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Remove from the Settings submenu to keep the page hidden.
		// Runs on admin_head, after admin-header.php has already resolved $title.
		add_action(
			'admin_head',
			function (): void {
				remove_submenu_page( 'options-general.php', self::PAGE_SLUG );
			}
		);
	}

	/**
	 * Enqueue scripts for the setup page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'supertab-connect-onboarding',
			plugins_url( 'assets/css/onboarding.css', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION
		);

		wp_enqueue_script(
			'supertab-connect-onboarding',
			plugins_url( 'assets/js/onboarding.js', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION,
			true
		);
	}

	/**
	 * Redirect to setup page after plugin activation.
	 *
	 * @return void
	 */
	public function handle_activation_redirect(): void {
		if ( ! get_transient( 'supertab_connect_activating' ) ) {
			return;
		}

		delete_transient( 'supertab_connect_activating' );

		// Don't redirect on multisite bulk activation or WP-CLI.
		if ( wp_doing_ajax() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Don't redirect if credentials already exist.
		if ( $this->credentials->has_credentials() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the setup form submission.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		if ( ! isset( $_POST['supertab_connect_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value used only for verification.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['supertab_connect_nonce'] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$merchant_api_key = isset( $_POST['merchant_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_api_key'] ) ) : '';
		$website_urn      = isset( $_POST['website_urn'] ) ? sanitize_text_field( wp_unslash( $_POST['website_urn'] ) ) : '';

		if ( '' === $merchant_api_key || '' === $website_urn ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => self::PAGE_SLUG,
						'error' => 'missing_fields',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		$this->credentials->save( $merchant_api_key, $website_urn );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'setup' => 'success',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the setup page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$template_data = array(
			'nonce_action'    => self::NONCE_ACTION,
			'has_credentials' => $this->credentials->has_credentials(),
			'website_urn'     => $this->credentials->get_website_urn(),
		);

		include SUPERTAB_CONNECT_PLUGIN_DIR . 'templates/onboarding.php';
	}
}

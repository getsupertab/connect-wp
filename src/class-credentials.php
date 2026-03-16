<?php
/**
 * Credential storage and retrieval.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

/**
 * Manages Supertab API credentials.
 */
class Credentials {

	/**
	 * Option name for the Merchant API Key.
	 *
	 * @var string
	 */
	private const OPTION_MERCHANT_API_KEY = 'supertab_connect_merchant_api_key';

	/**
	 * Option name for the Website URN.
	 *
	 * @var string
	 */
	private const OPTION_WEBSITE_URN = 'supertab_connect_website_urn';

	/**
	 * Option name for the bot protection enabled flag.
	 *
	 * @var string
	 */
	private const OPTION_BOT_PROTECTION_ENABLED = 'supertab_connect_bot_protection_enabled';

	/**
	 * Get the Merchant API Key.
	 *
	 * @return string The API key, or empty string if not set.
	 */
	public function get_merchant_api_key(): string {
		return (string) get_option( self::OPTION_MERCHANT_API_KEY, '' );
	}

	/**
	 * Get the Website URN.
	 *
	 * @return string The website URN, or empty string if not set.
	 */
	public function get_website_urn(): string {
		return (string) get_option( self::OPTION_WEBSITE_URN, '' );
	}

	/**
	 * Check if both Merchant API Key and Website URN are configured.
	 *
	 * @return bool True if credentials exist.
	 */
	public function has_credentials(): bool {
		return '' !== $this->get_merchant_api_key() && '' !== $this->get_website_urn();
	}

	/**
	 * Check if bot protection is enabled.
	 *
	 * @return bool True if bot protection is enabled.
	 */
	public function is_bot_protection_enabled(): bool {
		return (bool) get_option( self::OPTION_BOT_PROTECTION_ENABLED, false );
	}

	/**
	 * Set the bot protection enabled flag.
	 *
	 * @param bool $enabled Whether bot protection should be enabled.
	 * @return void
	 */
	public function set_bot_protection_enabled( bool $enabled ): void {
		update_option( self::OPTION_BOT_PROTECTION_ENABLED, $enabled );
	}

	/**
	 * Save credentials.
	 *
	 * @param string $merchant_api_key The Merchant API Key.
	 * @param string $website_urn      The Website URN.
	 * @return void
	 */
	public function save( string $merchant_api_key, string $website_urn ): void {
		update_option( self::OPTION_MERCHANT_API_KEY, $merchant_api_key, false );
		update_option( self::OPTION_WEBSITE_URN, $website_urn, false );

		// Invalidate cached license XML since credentials changed.
		delete_transient( 'supertab_connect_license_xml' );
	}

	/**
	 * Delete all stored credentials.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::OPTION_MERCHANT_API_KEY );
		delete_option( self::OPTION_WEBSITE_URN );
		delete_option( self::OPTION_BOT_PROTECTION_ENABLED );

		// Invalidate cached license XML.
		delete_transient( 'supertab_connect_license_xml' );
	}
}

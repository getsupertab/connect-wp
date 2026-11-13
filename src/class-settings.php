<?php
/**
 * Plugin settings storage and retrieval.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

/**
 * Manages Supertab Connect plugin settings.
 */
class Settings {

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
	 * Option name for the analytics enabled flag.
	 *
	 * @var string
	 */
	private const OPTION_ANALYTICS_ENABLED = 'supertab_connect_analytics_enabled';

	/**
	 * Option name for active paths.
	 *
	 * @var string
	 */
	private const OPTION_ACTIVE_PATHS = 'supertab_connect_active_paths';

	/**
	 * Option name for how the site was connected: { method, website_name, base_url, connected_at }.
	 *
	 * @var string
	 */
	private const OPTION_CONNECTION = 'supertab_connect_connection';

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
		return $this->has_merchant_api_key() && $this->has_website_urn();
	}

	/**
	 * Check if the Website URN is configured.
	 *
	 * @return bool True if URN exists.
	 */
	public function has_website_urn(): bool {
		return '' !== $this->get_website_urn();
	}

	/**
	 * Check if the Merchant API Key is configured.
	 *
	 * @return bool True if API key exists.
	 */
	public function has_merchant_api_key(): bool {
		return '' !== $this->get_merchant_api_key();
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
	 * Check if analytics is enabled.
	 *
	 * Opt-in: absent or falsy means disabled.
	 *
	 * @return bool True if analytics is enabled.
	 */
	public function is_analytics_enabled(): bool {
		return (bool) get_option( self::OPTION_ANALYTICS_ENABLED, false );
	}

	/**
	 * Set the analytics enabled flag.
	 *
	 * @param bool $enabled Whether analytics should be enabled.
	 * @return void
	 */
	public function set_analytics_enabled( bool $enabled ): void {
		update_option( self::OPTION_ANALYTICS_ENABLED, $enabled );
	}

	/**
	 * Get the active paths for bot protection.
	 *
	 * @return array<int, string> Array of path patterns. Defaults to ['*'] (all paths).
	 */
	public function get_active_paths(): array {
		$paths = get_option( self::OPTION_ACTIVE_PATHS, array( '*' ) );
		return is_array( $paths ) ? $paths : array( '*' );
	}

	/**
	 * Set the active paths for bot protection.
	 *
	 * @param array<int, string> $paths Array of path patterns.
	 * @return void
	 */
	public function set_active_paths( array $paths ): void {
		update_option( self::OPTION_ACTIVE_PATHS, $paths );
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
		delete_transient( RSL_License_Handler::CACHE_TRANSIENT_KEY );
	}

	/**
	 * Save the Website URN.
	 *
	 * @param string $website_urn The Website URN.
	 * @return void
	 */
	public function save_website_urn( string $website_urn ): void {
		update_option( self::OPTION_WEBSITE_URN, $website_urn, false );

		// Invalidate cached license XML since URN changed.
		delete_transient( RSL_License_Handler::CACHE_TRANSIENT_KEY );
	}

	/**
	 * Save the Merchant API Key.
	 *
	 * @param string $merchant_api_key The Merchant API Key.
	 * @return void
	 */
	public function save_merchant_api_key( string $merchant_api_key ): void {
		update_option( self::OPTION_MERCHANT_API_KEY, $merchant_api_key, false );
	}

	/**
	 * Record how the site was connected.
	 *
	 * @param string $method       "connect" for keys the connect flow issued, "manual" for pasted ones.
	 * @param string $website_name Website name in Supertab Connect; empty for manual credentials.
	 * @param string $base_url     Website base URL in Supertab Connect.
	 * @return void
	 */
	public function save_connection( string $method, string $website_name, string $base_url ): void {
		update_option(
			self::OPTION_CONNECTION,
			array(
				'method'       => $method,
				'website_name' => $website_name,
				'base_url'     => $base_url,
				'connected_at' => time(),
			),
			false
		);
	}

	/**
	 * How the site was connected, or null when nothing is recorded.
	 *
	 * @return array{method: string, website_name: string, base_url: string, connected_at: int}|null
	 */
	public function get_connection(): ?array {
		$connection = get_option( self::OPTION_CONNECTION, null );
		if ( ! is_array( $connection ) ) {
			return null;
		}

		return array(
			'method'       => (string) ( $connection['method'] ?? '' ),
			'website_name' => (string) ( $connection['website_name'] ?? '' ),
			'base_url'     => (string) ( $connection['base_url'] ?? '' ),
			'connected_at' => (int) ( $connection['connected_at'] ?? 0 ),
		);
	}

	/**
	 * "connect" when the connect flow issued the key, otherwise "manual".
	 *
	 * Installs set up before the connect flow have no record and count as manual,
	 * so their pasted keys are never revoked.
	 *
	 * @return string
	 */
	public function get_connection_method(): string {
		$connection = $this->get_connection();

		return null !== $connection && 'connect' === $connection['method'] ? 'connect' : 'manual';
	}

	/**
	 * Forget the connected website: key, URN and connection record.
	 *
	 * CAP and analytics are turned off so a later reconnect doesn't silently
	 * resume enforcement; active paths are kept.
	 *
	 * @return void
	 */
	public function clear_connection(): void {
		delete_option( self::OPTION_MERCHANT_API_KEY );
		delete_option( self::OPTION_WEBSITE_URN );
		delete_option( self::OPTION_CONNECTION );
		$this->set_bot_protection_enabled( false );
		$this->set_analytics_enabled( false );

		delete_transient( RSL_License_Handler::CACHE_TRANSIENT_KEY );
	}

	/**
	 * Delete all stored settings.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::OPTION_MERCHANT_API_KEY );
		delete_option( self::OPTION_WEBSITE_URN );
		delete_option( self::OPTION_BOT_PROTECTION_ENABLED );
		delete_option( self::OPTION_ANALYTICS_ENABLED );
		delete_option( self::OPTION_ACTIVE_PATHS );
		delete_option( self::OPTION_CONNECTION );

		// Invalidate cached license XML.
		delete_transient( RSL_License_Handler::CACHE_TRANSIENT_KEY );
	}
}

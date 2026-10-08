<?php
/**
 * Robots.txt handler.
 *
 * Adds the RSL License directive to the robots.txt that WordPress generates,
 * so crawlers can discover the license.xml served by RSL_License_Handler.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advertises license.xml through the License directive in robots.txt.
 */
class Robots_Txt_Handler {

	/**
	 * Site path where RSL_License_Handler serves the license.
	 *
	 * @var string
	 */
	private const LICENSE_PATH = '/license.xml';

	/**
	 * Matches a License directive line, ignoring case, surrounding blanks and a leading byte order mark.
	 *
	 * @var string
	 */
	private const LICENSE_DIRECTIVE_PATTERN = '/^(?:\xEF\xBB\xBF)?[ \t]*license[ \t]*:/im';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings manager.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * Runs last so the duplicate check sees License lines added by SEO plugins
	 * and theme code through the same filter.
	 *
	 * @return void
	 */
	public function register(): void {
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt -- Output only changes when the website URN is saved; a cached robots.txt picks it up when the host's cache expires.
		add_filter( 'robots_txt', array( $this, 'add_license_directive' ), PHP_INT_MAX );
	}

	/**
	 * Append the License directive unless robots.txt already declares one.
	 *
	 * Existing License directives are left untouched, whatever URL they point at.
	 *
	 * @param mixed $output The robots.txt content from WordPress and earlier filters.
	 * @return mixed The content with the License directive appended, or unchanged.
	 */
	public function add_license_directive( $output ) {
		if ( ! is_string( $output ) || ! $this->settings->has_website_urn() || self::has_license_directive( $output ) ) {
			return $output;
		}

		$output = rtrim( $output, "\r\n" );
		if ( '' !== $output ) {
			$output .= "\n\n";
		}

		return $output . 'License: ' . home_url( self::LICENSE_PATH ) . "\n";
	}

	/**
	 * Whether a physical robots.txt file hides the generated one without declaring a license.
	 *
	 * The web server returns a physical robots.txt directly, so WordPress never
	 * runs and the robots_txt filter can't add the directive.
	 *
	 * @return bool True when the merchant has to add the License directive by hand.
	 */
	public function needs_manual_license_directive(): bool {
		if ( ! $this->settings->has_website_urn() ) {
			return false;
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = get_home_path() . 'robots.txt';
		if ( ! file_exists( $path ) ) {
			return false;
		}

		// An unreadable file still hides the generated robots.txt, so ask for the directive.
		if ( ! is_readable( $path ) ) {
			return true;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file under the site root, not a remote URL.
		$contents = file_get_contents( $path );

		return false === $contents || ! self::has_license_directive( $contents );
	}

	/**
	 * Whether robots.txt content declares a License directive.
	 *
	 * Commented-out lines don't count.
	 *
	 * @param string $content The robots.txt content.
	 * @return bool
	 */
	private static function has_license_directive( string $content ): bool {
		return 1 === preg_match( self::LICENSE_DIRECTIVE_PATTERN, $content );
	}
}

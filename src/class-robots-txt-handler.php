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
	 * Matches a License directive line and captures its URL, ignoring case,
	 * surrounding blanks and a leading byte order mark.
	 *
	 * @var string
	 */
	private const LICENSE_DIRECTIVE_PATTERN = '/^(?:\xEF\xBB\xBF)?[ \t]*license[ \t]*:[ \t]*(\S+)/im';

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
		$contents = $this->read_physical_robots_txt();
		if ( null === $contents ) {
			return false;
		}

		// An unreadable file still hides the generated robots.txt, so ask for the directive.
		return false === $contents || ! self::has_license_directive( $contents );
	}

	/**
	 * License URLs that a physical robots.txt declares instead of this site's license.
	 *
	 * Empty when there is no physical file, it declares no license, or one of its
	 * License directives already points at this site's license.xml (over http or https).
	 *
	 * @return array<int, string> Unique URLs, in file order.
	 */
	public function get_other_license_urls(): array {
		$contents = $this->read_physical_robots_txt();
		if ( ! is_string( $contents ) ) {
			return array();
		}

		preg_match_all( self::LICENSE_DIRECTIVE_PATTERN, $contents, $matches );
		$urls = array_values( array_unique( $matches[1] ) );

		$own_url = self::without_scheme( home_url( self::LICENSE_PATH ) );
		foreach ( $urls as $url ) {
			if ( self::without_scheme( $url ) === $own_url ) {
				return array();
			}
		}

		return $urls;
	}

	/**
	 * Read the physical robots.txt file that hides the generated one.
	 *
	 * @return string|false|null The file contents, false when the file can't be read,
	 *                           or null when there is no file or no website URN.
	 */
	private function read_physical_robots_txt() {
		if ( ! $this->settings->has_website_urn() ) {
			return null;
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = get_home_path() . 'robots.txt';
		if ( ! file_exists( $path ) ) {
			return null;
		}

		if ( ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file under the site root, not a remote URL.
		return file_get_contents( $path );
	}

	/**
	 * Strip the http or https scheme from a URL.
	 *
	 * @param string $url The URL.
	 * @return string
	 */
	private static function without_scheme( string $url ): string {
		return (string) preg_replace( '#^https?://#i', '', $url );
	}

	/**
	 * Whether robots.txt content declares a License directive.
	 *
	 * Commented-out lines and License lines without a URL don't count.
	 *
	 * @param string $content The robots.txt content.
	 * @return bool
	 */
	private static function has_license_directive( string $content ): bool {
		return 1 === preg_match( self::LICENSE_DIRECTIVE_PATTERN, $content );
	}
}

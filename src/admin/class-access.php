<?php
/**
 * Who may manage the plugin.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

/**
 * The capability that guards the settings page and the connect flow.
 */
final class Access {

	/**
	 * The capability, `manage_options` unless a platform opens the screen to other roles.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return (string) apply_filters( 'supertab_connect_capability', 'manage_options' );
	}
}

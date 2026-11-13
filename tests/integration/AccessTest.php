<?php
/**
 * Tests for the capability filter.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Admin\Access;
use WP_UnitTestCase;

class AccessTest extends WP_UnitTestCase {

	public function test_admins_manage_the_plugin_by_default(): void {
		$this->assertSame( 'manage_options', Access::capability() );
	}

	public function test_a_platform_can_open_the_screen_to_editors(): void {
		add_filter(
			'supertab_connect_capability',
			static function (): string {
				return 'edit_others_posts';
			}
		);

		$this->assertSame( 'edit_others_posts', Access::capability() );
	}
}

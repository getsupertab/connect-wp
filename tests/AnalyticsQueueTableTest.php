<?php
/**
 * Tests for Analytics_Queue_Table schema install and row operations.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Analytics_Queue_Table;

class AnalyticsQueueTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	public function test_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_supertab_connect_analytics_queue', ( new Analytics_Queue_Table() )->name() );
	}

	public function test_install_runs_dbdelta_and_stores_version(): void {
		global $wp_test_dbdelta_queries, $wp_test_option_autoload;

		( new Analytics_Queue_Table() )->install();

		$this->assertCount( 1, $wp_test_dbdelta_queries );
		$sql = $wp_test_dbdelta_queries[0];
		$this->assertStringContainsString( 'CREATE TABLE wp_supertab_connect_analytics_queue', $sql );
		$this->assertStringContainsString( 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql );
		$this->assertStringContainsString( 'payload LONGTEXT NOT NULL', $sql );
		$this->assertStringContainsString( 'created_at DATETIME NOT NULL', $sql );
		// dbDelta requires exactly two spaces after PRIMARY KEY.
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertSame( Analytics_Queue_Table::DB_VERSION, get_option( 'supertab_connect_db_version' ) );
		$this->assertFalse( $wp_test_option_autoload['supertab_connect_db_version'], 'Schema version option must not autoload.' );
	}

	public function test_install_skips_when_version_current(): void {
		global $wp_test_dbdelta_queries;

		update_option( 'supertab_connect_db_version', Analytics_Queue_Table::DB_VERSION );

		( new Analytics_Queue_Table() )->install();

		$this->assertCount( 0, $wp_test_dbdelta_queries );
	}

	public function test_insert_writes_payload_row(): void {
		global $wpdb;

		$result = ( new Analytics_Queue_Table() )->insert( '{"request_id":"req-1"}' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $wpdb->insert_calls );
		$call = $wpdb->insert_calls[0];
		$this->assertSame( 'wp_supertab_connect_analytics_queue', $call['table'] );
		$this->assertSame( '{"request_id":"req-1"}', $call['data']['payload'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $call['data']['created_at'] );
		$this->assertSame( array( '%s', '%s' ), $call['format'] );
	}

	public function test_insert_returns_false_on_db_error(): void {
		global $wpdb;

		$wpdb->insert_result = false;

		$this->assertFalse( ( new Analytics_Queue_Table() )->insert( '{}' ) );
	}

	public function test_count_returns_row_count(): void {
		global $wpdb;

		$wpdb->var_result = '42';

		$this->assertSame( 42, ( new Analytics_Queue_Table() )->count() );
	}

	public function test_claim_batch_returns_empty_without_delete(): void {
		global $wpdb;

		$wpdb->results_queue = array( array() );

		$this->assertSame( array(), ( new Analytics_Queue_Table() )->claim_batch( 500 ) );
		// Only the SELECT ran — no DELETE.
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'SELECT', $wpdb->queries[0] );
	}

	public function test_claim_batch_selects_deletes_and_returns_payloads(): void {
		global $wpdb;

		$wpdb->results_queue = array(
			array(
				array( 'id' => '1', 'payload' => '{"a":1}' ),
				array( 'id' => '2', 'payload' => '{"b":2}' ),
			),
		);

		$payloads = ( new Analytics_Queue_Table() )->claim_batch( 500 );

		$this->assertSame( array( '{"a":1}', '{"b":2}' ), $payloads );
		$this->assertCount( 2, $wpdb->queries );
		$this->assertStringContainsString( 'ORDER BY id ASC', $wpdb->queries[0] );
		$this->assertStringContainsString( 'LIMIT 500', $wpdb->queries[0] );
		$this->assertSame( 'DELETE FROM wp_supertab_connect_analytics_queue WHERE id IN (1,2)', $wpdb->queries[1] );
	}
}

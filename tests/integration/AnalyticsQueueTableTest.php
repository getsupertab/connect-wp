<?php
/**
 * Integration tests covering Analytics_Queue_Table against a real database.
 *
 * The unit suite exercises this class through an in-memory wpdb spy, which
 * records SQL strings but cannot execute them. Everything that is actually
 * SQL semantics — dbDelta schema creation, oldest-first drain ordering, the
 * id-span capacity probe, and above all the FOR UPDATE claim transaction —
 * is only provable here. Three techniques make that possible:
 *
 *   1. Real (non-TEMPORARY) tables: the test framework rewrites CREATE TABLE
 *      to CREATE TEMPORARY TABLE, but temporary tables are invisible to any
 *      second database connection, which the lock test requires. The rewrite
 *      filters are removed in set_up and cleanup is explicit in tear_down.
 *   2. Explicit cleanup instead of rollback: claim_batch() issues its own
 *      COMMIT, which terminates the framework's per-test rollback wrapper,
 *      so state written before a claim persists across tests unless dropped.
 *   3. A second mysqli connection to hold FOR UPDATE row locks while the
 *      main connection claims, proving overlapping claimants cannot
 *      double-deliver.
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Analytics_Queue_Table;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration tests deliberately exercise raw SQL against the plugin's own table.

class AnalyticsQueueTableTest extends WP_UnitTestCase {

	/**
	 * Table under test.
	 *
	 * @var Analytics_Queue_Table
	 */
	private Analytics_Queue_Table $table;

	protected function setUp(): void {
		parent::setUp();

		// Technique 1: create real tables so a second connection can see them.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// A previous test's mid-test COMMIT may have persisted the version
		// option; clear it so install() actually runs.
		delete_option( 'supertab_connect_db_version' );

		$this->table = new Analytics_Queue_Table();
		$this->table->install();
	}

	protected function tearDown(): void {
		global $wpdb;

		// Technique 2: rollback cannot be relied on once claim_batch() has
		// committed, so drop the real table and its version option explicitly.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}supertab_connect_analytics_queue" );
		delete_option( 'supertab_connect_db_version' );

		parent::tearDown();
	}

	/**
	 * Proves dbDelta materialises the exact schema the spec mandates.
	 *
	 * Type strings differ across MySQL 8 ("bigint unsigned") and MariaDB
	 * ("bigint(20) unsigned"), so integer types are matched loosely while
	 * payload/created_at are exact.
	 */
	public function test_install_creates_schema_and_records_version(): void {
		global $wpdb;

		$columns = $wpdb->get_results( "DESCRIBE {$this->table->name()}", ARRAY_A );
		$by_name = array_column( $columns, null, 'Field' );

		$this->assertSame( array( 'id', 'payload', 'created_at' ), array_keys( $by_name ) );

		$this->assertStringContainsString( 'bigint', $by_name['id']['Type'] );
		$this->assertStringContainsString( 'unsigned', $by_name['id']['Type'] );
		$this->assertSame( 'auto_increment', $by_name['id']['Extra'] );
		$this->assertSame( 'PRI', $by_name['id']['Key'] );

		$this->assertSame( 'longtext', $by_name['payload']['Type'] );
		$this->assertSame( 'NO', $by_name['payload']['Null'] );

		$this->assertSame( 'datetime', $by_name['created_at']['Type'] );
		$this->assertSame( 'NO', $by_name['created_at']['Null'] );

		$this->assertSame( Analytics_Queue_Table::DB_VERSION, get_option( 'supertab_connect_db_version' ) );
	}

	/**
	 * Proves a version-current install() is a true no-op: it must not
	 * recreate, truncate, or otherwise disturb a table holding data.
	 */
	public function test_install_is_idempotent_and_preserves_rows(): void {
		global $wpdb;

		$this->assertTrue( $this->table->insert( '{"request_id":"req-keep"}' ) );

		$this->table->install();

		$this->assertSame(
			'1',
			$wpdb->get_var( "SELECT COUNT(*) FROM {$this->table->name()}" ),
			'A second install() must leave existing rows untouched.'
		);
	}

	/**
	 * Proves a payload survives the round trip byte-for-byte (multibyte
	 * UTF-8 included) and created_at is written as current UTC.
	 */
	public function test_insert_round_trips_payload_and_utc_timestamp(): void {
		global $wpdb;

		$payload = '{"request_id":"req-utf8","url":"https:\/\/example.com\/héllo-世界"}';

		$this->assertTrue( $this->table->insert( $payload ) );

		$row = $wpdb->get_row( "SELECT payload, created_at FROM {$this->table->name()}", ARRAY_A );

		$this->assertSame( $payload, $row['payload'] );
		$this->assertEqualsWithDelta(
			time(),
			strtotime( $row['created_at'] . ' UTC' ),
			5,
			'created_at must be stored as current UTC time.'
		);
	}

	/**
	 * Proves claim_batch() drains oldest-first, deletes what it returns, and
	 * reports an empty buffer as an empty claim.
	 */
	public function test_claim_batch_drains_oldest_first_and_deletes(): void {
		global $wpdb;

		$payloads = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$payloads[] = '{"request_id":"req-' . $i . '"}';
			$this->assertTrue( $this->table->insert( end( $payloads ) ) );
		}

		$this->assertSame( array_slice( $payloads, 0, 5 ), $this->table->claim_batch( 5 ) );
		$this->assertSame( array_slice( $payloads, 5, 2 ), $this->table->claim_batch( 5 ) );
		$this->assertSame( array(), $this->table->claim_batch( 5 ) );

		$this->assertSame(
			'0',
			$wpdb->get_var( "SELECT COUNT(*) FROM {$this->table->name()}" ),
			'Claimed rows must be deleted, not merely returned.'
		);
	}

	/**
	 * Proves is_full() capacity semantics, including the documented id-span
	 * early trip: auto-increment gaps inflate the span past the true row
	 * count, tripping the cap early — the fail-open direction.
	 */
	public function test_is_full_uses_id_span_and_trips_early_on_gaps(): void {
		global $wpdb;

		$this->assertFalse( $this->table->is_full( 1 ), 'An empty buffer is never full.' );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( $this->table->insert( '{"request_id":"req-' . $i . '"}' ) );
		}

		$this->assertTrue( $this->table->is_full( 5 ) );
		$this->assertFalse( $this->table->is_full( 6 ) );

		// Carve a gap: delete the three interior ids, leaving two rows whose
		// span still covers all five slots.
		$bounds = $wpdb->get_row( "SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM {$this->table->name()}", ARRAY_A );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table->name()} WHERE id > %d AND id < %d",
				(int) $bounds['min_id'],
				(int) $bounds['max_id']
			)
		);

		$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table->name()}" ) );
		$this->assertTrue(
			$this->table->is_full( 5 ),
			'Span (5) must trip the cap even though only 2 rows remain — documented early-trip.'
		);
		$this->assertFalse( $this->table->is_full( 6 ) );
	}

	/**
	 * Proves the FOR UPDATE claim transaction: while a concurrent claimant
	 * holds locks on the oldest rows, claim_batch() must never return those
	 * rows — it waits, then times out claiming nothing. Once the holder
	 * deletes its rows and commits, a follow-up claim returns exactly the
	 * survivors. Claims are disjoint and exhaustive: nothing delivered
	 * twice, nothing lost.
	 *
	 * Technique 3: the lock holder is a second, independent mysqli
	 * connection — the framework's shared wpdb connection cannot contend
	 * with itself.
	 */
	public function test_claim_batch_never_returns_rows_locked_by_concurrent_claimant(): void {
		global $wpdb;

		$payloads = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$payloads[] = '{"request_id":"req-' . $i . '"}';
			$this->assertTrue( $this->table->insert( end( $payloads ) ) );
		}

		// The holder connection reads committed data only: end the test
		// wrapper transaction so the four rows are visible to it.
		$wpdb->query( 'COMMIT' );

		$holder = $this->connect_second_session();
		$table  = $this->table->name();

		try {
			$holder->begin_transaction();

			$result = $holder->query( "SELECT id, payload FROM {$table} ORDER BY id ASC LIMIT 2 FOR UPDATE" );
			$this->assertNotFalse( $result, 'Lock holder SELECT ... FOR UPDATE failed: ' . $holder->error );
			$held = $result->fetch_all( MYSQLI_ASSOC );
			$this->assertCount( 2, $held, 'Lock holder must have claimed the two oldest rows.' );

			// Fail fast instead of InnoDB's 50s default lock wait, and keep
			// wpdb from printing the expected lock-timeout error into the
			// test output (PHPUnit flags printed output as risky).
			$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
			$suppress = $wpdb->suppress_errors( true );

			try {
				$claimed_while_locked = $this->table->claim_batch( 4 );
			} finally {
				$wpdb->suppress_errors( $suppress );
				$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );
			}

			$this->assertSame(
				array(),
				$claimed_while_locked,
				'A claim contending with held FOR UPDATE locks must return nothing — never the locked rows.'
			);

			// The holder completes its claim: delete-then-commit.
			$held_ids = implode( ',', array_map( 'intval', array_column( $held, 'id' ) ) );
			$this->assertTrue(
				$holder->query( "DELETE FROM {$table} WHERE id IN ({$held_ids})" ),
				'Lock holder DELETE failed: ' . $holder->error
			);
			$holder->commit();
		} finally {
			$holder->close();
		}

		$survivors = $this->table->claim_batch( 4 );

		$held_payloads = array_column( $held, 'payload' );
		$this->assertSame( array_slice( $payloads, 0, 2 ), $held_payloads, 'Holder claimed the oldest two.' );
		$this->assertSame( array_slice( $payloads, 2, 2 ), $survivors, 'Follow-up claim gets exactly the survivors.' );
		$this->assertSame(
			array(),
			array_intersect( $held_payloads, $survivors ),
			'Disjoint: no payload may be delivered by both claimants.'
		);
		$this->assertSame(
			$payloads,
			array_merge( $held_payloads, $survivors ),
			'Exhaustive: every payload is delivered exactly once.'
		);
	}

	/**
	 * Open an independent database session using the suite's credentials.
	 *
	 * @return \mysqli
	 */
	private function connect_second_session(): \mysqli {
		global $wpdb;

		list( $host, $port, $socket ) = array_pad( $wpdb->parse_db_host( DB_HOST ), 3, null );

		// phpcs:ignore WordPress.DB.RestrictedFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions -- A second, independent connection is the point: the shared wpdb session cannot contend with itself.
		$mysqli = mysqli_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, null === $port ? 0 : (int) $port, (string) $socket );

		$this->assertNotFalse( $mysqli, 'Could not open a second database connection with the suite credentials.' );

		return $mysqli;
	}
}

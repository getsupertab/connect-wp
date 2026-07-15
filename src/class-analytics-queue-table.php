<?php
/**
 * Custom table that buffers analytics events between visitor requests and the
 * hourly batch flush.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the analytics queue table: schema install and row operations.
 *
 * Storage rationale (VIP): write-heavy data must not live in options
 * (alloptions cache churn) and object cache is evictable; a prefixed custom
 * table with atomic inserts is the platform-documented pattern.
 */
class Analytics_Queue_Table {

	/**
	 * Schema version; bump to trigger a dbDelta run on already-active installs.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1';

	/**
	 * Option that records the installed schema version.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'supertab_connect_db_version';

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	public function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'supertab_connect_analytics_queue';
	}

	/**
	 * Create or upgrade the table. Idempotent: no-op when the recorded schema
	 * version is current. Runs dbDelta otherwise.
	 *
	 * @return void
	 */
	public function install(): void {
		if ( self::DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY.
		$sql = 'CREATE TABLE ' . $this->name() . " (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	payload LONGTEXT NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Insert one serialized event payload.
	 *
	 * @param string $payload JSON-encoded analytics event.
	 * @return bool True when the row was written.
	 */
	public function insert( string $payload ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Write to the plugin's own queue table; no core API covers it.
		$result = $wpdb->insert(
			$this->name(),
			array(
				'payload'    => $payload,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Whether the buffer has reached $max_rows.
	 *
	 * Uses the id span (MAX - MIN + 1) rather than COUNT(*): rows are inserted
	 * with ascending ids and only ever deleted oldest-first, so the span bounds
	 * the row count, and MIN/MAX are O(1) index lookups where COUNT(*) scans
	 * the index on every buffered event. Auto-increment gaps can only inflate
	 * the span, making the cap trip early — the fail-open direction.
	 *
	 * @param int $max_rows Row cap.
	 * @return bool True when at or above the cap.
	 */
	public function is_full( int $max_rows ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live capacity check on the plugin's own table; name from $wpdb->prefix.
		$span = $wpdb->get_var( "SELECT MAX(id) - MIN(id) + 1 FROM {$this->name()}" );

		return null !== $span && (int) $span >= $max_rows;
	}

	/**
	 * Claim up to $limit oldest rows: select them, delete them, return their
	 * payloads. Deliver-once semantics — once claimed, rows are gone whether or
	 * not the subsequent send succeeds.
	 *
	 * The claim runs in a transaction with FOR UPDATE row locks so overlapping
	 * flush runners (e.g. WP-Cron and Action Scheduler firing together during
	 * a backend migration) block on the locked rows instead of both claiming —
	 * and double-delivering — the same batch.
	 *
	 * @param int $limit Maximum rows to claim.
	 * @return list<string> JSON payload strings, oldest first.
	 * @throws \Throwable Rethrows any claim-query failure after rolling back.
	 */
	public function claim_batch( int $limit ): array {
		global $wpdb;

		$table = $this->name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim must be atomic across SELECT and DELETE.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queue drain on the plugin's own table; name from $wpdb->prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, payload FROM {$table} ORDER BY id ASC LIMIT %d FOR UPDATE", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Close the claim transaction.
				$wpdb->query( 'COMMIT' );
				return array();
			}

			$ids = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are intval()-sanitized; plugin's own table.
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Close the claim transaction.
			$wpdb->query( 'COMMIT' );

			return array_column( $rows, 'payload' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release locks; caller handles the error.
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}

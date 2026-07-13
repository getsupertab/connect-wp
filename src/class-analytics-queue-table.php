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
	 * Current number of buffered rows.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live queue-size check on the plugin's own table; name from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->name()}" );
	}

	/**
	 * Claim up to $limit oldest rows: select them, delete them, return their
	 * payloads. Deliver-once semantics — once claimed, rows are gone whether or
	 * not the subsequent send succeeds.
	 *
	 * @param int $limit Maximum rows to claim.
	 * @return list<string> JSON payload strings, oldest first.
	 */
	public function claim_batch( int $limit ): array {
		global $wpdb;

		$table = $this->name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queue drain on the plugin's own table; name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, payload FROM {$table} ORDER BY id ASC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are intval()-sanitized; plugin's own table.
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );

		return array_column( $rows, 'payload' );
	}
}

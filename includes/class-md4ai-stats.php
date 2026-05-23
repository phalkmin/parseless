<?php
/**
 * Aggregated reads against the requests table.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read helpers for the dashboard widget and analytics tab.
 */
class MD4AI_Stats {

	/**
	 * Returns total requests within the past N days.
	 *
	 * @param int $days Window length.
	 * @return int
	 */
	public static function total_requests( int $days ): int {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$table,
				max( 1, $days )
			)
		);
	}

	/**
	 * Returns cache hit rate (0..1) over the window, or null if no rows.
	 *
	 * @param int $days Window length.
	 * @return float|null
	 */
	public static function cache_hit_rate( int $days ): ?float {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(cache_hit) AS hits FROM %i WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$table,
				max( 1, $days )
			)
		);
		if ( ! $row || (int) $row->total === 0 ) {
			return null;
		}
		return (float) $row->hits / (float) $row->total;
	}

	/**
	 * Returns the top N bots over the window.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Number of rows to return.
	 * @return array<int, array{bot_name:string, hits:int, last_seen:string}>
	 */
	public static function top_bots( int $days, int $limit = 5 ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_name, COUNT(*) AS hits, MAX(created_at) AS last_seen
				 FROM %i
				 WHERE bot_name <> '' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY bot_name
				 ORDER BY hits DESC
				 LIMIT %d",
				$table,
				max( 1, $days ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the top N fetched posts over the window.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Number of rows to return.
	 * @return array<int, array{post_id:int, hits:int, last_seen:string}>
	 */
	public static function top_posts( int $days, int $limit = 5 ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, COUNT(*) AS hits, MAX(created_at) AS last_seen
				 FROM %i
				 WHERE post_id > 0 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY post_id
				 ORDER BY hits DESC
				 LIMIT %d',
				$table,
				max( 1, $days ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns recent unknown UA hits.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Number of rows to return.
	 * @return array<int, array{user_agent:string, hits:int, last_seen:string}>
	 */
	public static function unknown_uas( int $days, int $limit = 50 ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_agent, COUNT(*) AS hits, MAX(created_at) AS last_seen
				 FROM %i
				 WHERE bot_name = 'unknown' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY user_agent
				 ORDER BY last_seen DESC
				 LIMIT %d",
				$table,
				max( 1, $days ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns raw rows for CSV export over the window.
	 *
	 * @param int $days Window length.
	 * @return array<int, array<string,mixed>>
	 */
	public static function export_rows( int $days ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, post_id, url, user_agent, bot_name, bytes_served, cache_hit
				 FROM %i
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 ORDER BY created_at DESC',
				$table,
				max( 1, $days )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}

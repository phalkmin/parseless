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
				'SELECT COUNT(*) AS total, SUM(cache_hit) AS hits FROM %i WHERE served = \'markdown\' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$table,
				max( 1, $days )
			)
		);
		if ( ! $row || 0 === (int) $row->total ) {
			return null;
		}
		return (float) $row->hits / (float) $row->total;
	}

	/**
	 * Returns the top N bots that successfully fetched an individual post.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Number of rows to return.
	 * @return array<int, array{bot_name:string, hits:int, last_seen:string}>
	 */
	public static function top_content_bots( int $days, int $limit = 5 ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_name, COUNT(*) AS hits, MAX(created_at) AS last_seen
				 FROM %i
				 WHERE bot_name <> '' AND served = 'markdown' AND post_id > 0
				   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
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
	 * Returns per-bot reachability, including listed bots never seen.
	 *
	 * @param int $days Window length.
	 * @return array<int, array{bot_name:string, last_seen:string, markdown:int, html:int, blocked:int, throttled:int, verified:int, unverified:int}>
	 */
	public static function bot_reachability( int $days ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_name, served, COUNT(*) AS hits, MAX(created_at) AS last_seen,
				        SUM(verified = 1) AS verified_hits, SUM(verified = 0) AS unverified_hits
				 FROM %i
				 WHERE bot_name <> '' AND bot_name NOT IN ('manual', 'unknown')
				   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY bot_name, served",
				$table,
				max( 1, $days )
			),
			ARRAY_A
		);

		$seen = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = strtolower( (string) $row['bot_name'] );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = self::empty_reach_row( (string) $row['bot_name'] );
			}
			$served = (string) $row['served'];
			if ( isset( $seen[ $key ][ $served ] ) ) {
				$seen[ $key ][ $served ] += (int) $row['hits'];
			}
			$seen[ $key ]['verified']   += (int) $row['verified_hits'];
			$seen[ $key ]['unverified'] += (int) $row['unverified_hits'];
			if ( strcmp( (string) $row['last_seen'], $seen[ $key ]['last_seen'] ) > 0 ) {
				$seen[ $key ]['last_seen'] = (string) $row['last_seen'];
			}
		}

		$result = array();
		foreach ( MD4AI_Detector::get_bot_list() as $bot ) {
			$key      = strtolower( (string) $bot );
			$result[] = $seen[ $key ] ?? self::empty_reach_row( (string) $bot );
			unset( $seen[ $key ] );
		}
		foreach ( $seen as $row ) {
			$result[] = $row;
		}

		usort(
			$result,
			static fn( array $a, array $b ): int => strcmp( $b['last_seen'], $a['last_seen'] )
		);
		return $result;
	}

	/**
	 * Returns a zeroed reachability row.
	 *
	 * @param string $bot_name Bot identifier.
	 * @return array{bot_name:string, last_seen:string, markdown:int, html:int, blocked:int, throttled:int, verified:int, unverified:int}
	 */
	private static function empty_reach_row( string $bot_name ): array {
		return array(
			'bot_name'   => $bot_name,
			'last_seen'  => '',
			'markdown'   => 0,
			'html'       => 0,
			'blocked'    => 0,
			'throttled'  => 0,
			'verified'   => 0,
			'unverified' => 0,
		);
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
	 * Returns the top N fetched URLs over the window.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Number of rows to return.
	 * @return array<int, array{url:string, hits:int, last_seen:string}>
	 */
	public static function top_urls( int $days, int $limit = 20 ): array {
		global $wpdb;
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, COUNT(*) AS hits, MAX(created_at) AS last_seen
				 FROM %i
				 WHERE url <> '' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY url
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
	 * Returns zero-filled daily hits for the top N bots, ready for the chart.
	 *
	 * @param int $days Window length.
	 * @param int $top  Number of bots to chart.
	 * @return array{labels: array<int,string>, datasets: array<int, array{label:string, data:array<int,int>}>}
	 */
	public static function daily_bot_hits( int $days, int $top = 5 ): array {
		global $wpdb;
		$days  = max( 1, $days );
		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, bot_name, COUNT(*) AS hits
				 FROM %i
				 WHERE bot_name <> '' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY d, bot_name
				 ORDER BY d ASC",
				$table,
				$days
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'labels'   => array(),
				'datasets' => array(),
			);
		}

		$labels = array();
		$cursor = strtotime( '-' . ( $days - 1 ) . ' days', strtotime( gmdate( 'Y-m-d' ) ) );
		for ( $i = 0; $i < $days; $i++ ) {
			$labels[] = gmdate( 'Y-m-d', $cursor );
			$cursor   = strtotime( '+1 day', $cursor );
		}

		$totals = array();
		$by_day = array();
		foreach ( $rows as $row ) {
			$bot                    = (string) $row['bot_name'];
			$day                    = (string) $row['d'];
			$hits                   = (int) $row['hits'];
			$totals[ $bot ]         = ( $totals[ $bot ] ?? 0 ) + $hits;
			$by_day[ $bot ][ $day ] = $hits;
		}
		arsort( $totals );
		$top_bots = array_slice( array_keys( $totals ), 0, max( 1, $top ) );

		$datasets = array();
		foreach ( $top_bots as $bot ) {
			$data = array();
			foreach ( $labels as $label ) {
				$data[] = (int) ( $by_day[ $bot ][ $label ] ?? 0 );
			}
			$datasets[] = array(
				'label' => $bot,
				'data'  => $data,
			);
		}

		return array(
			'labels'   => $labels,
			'datasets' => $datasets,
		);
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
				'SELECT created_at, post_id, url, user_agent, bot_name, bytes_served, cache_hit, served, verified
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

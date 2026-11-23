<?php
/**
 * Per-bot request throttling for the Markdown-serving endpoints.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed sliding-window rate limiter for known crawlers.
 */
class MD4AI_Rate_Limiter {

	public const TRANSIENT_PREFIX = 'md4ai_rl_';

	/**
	 * Whether the current request exceeds the configured per-bot limit.
	 *
	 * @return bool
	 */
	public static function is_throttled(): bool {
		if ( ! md4ai_get_setting( 'rate_limit_enabled' ) ) {
			return false;
		}

		$bot = MD4AI_Detector::bot_name();
		if ( '' === $bot || 'manual' === $bot ) {
			return false;
		}

		$base       = (int) md4ai_get_setting( 'rate_limit_per_minute' );
		$unverified = false === MD4AI_Verifier::verify( $bot );
		if ( $unverified ) {
			$base = (int) md4ai_get_setting( 'rate_limit_unverified_per_minute' );
		}
		$limit = (int) apply_filters( 'md4ai_rate_limit', $base, $bot );
		if ( $limit <= 0 ) {
			return false;
		}

		// Separate window, so a forger cannot spend the genuine crawler's quota.
		$bucket   = $unverified ? $bot . '|unverified' : $bot;
		$now      = time();
		$minute   = intdiv( $now, MINUTE_IN_SECONDS );
		$elapsed  = $now % MINUTE_IN_SECONDS;
		$key      = self::key( $bucket, $minute );
		$current  = self::increment( $key );
		$previous = (int) get_transient( self::key( $bucket, $minute - 1 ) );

		$weighted = $previous * ( ( MINUTE_IN_SECONDS - $elapsed ) / MINUTE_IN_SECONDS ) + $current;

		return $weighted > $limit;
	}

	/**
	 * Atomically increments a minute bucket and returns its new value.
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private static function increment( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'transient', 2 * MINUTE_IN_SECONDS );
			$value = wp_cache_incr( $key, 1, 'transient' );
			if ( false !== $value ) {
				return (int) $value;
			}

			$value = (int) get_transient( $key ) + 1;
			set_transient( $key, $value, 2 * MINUTE_IN_SECONDS );
			return $value;
		}

		global $wpdb;
		$option = '_transient_' . $key;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, '1', 'no')
				 ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
				$option
			)
		);
		wp_cache_delete( $option, 'options' );
		if ( ! $affected ) {
			return 0;
		}

		// A fresh row reports 1 affected row, and LAST_INSERT_ID() would return its option_id, not the count.
		if ( 1 === $affected ) {
			add_option( '_transient_timeout_' . $key, time() + 2 * MINUTE_IN_SECONDS, '', 'no' );
			return 1;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Seconds until the current minute bucket rolls over.
	 *
	 * @return int
	 */
	public static function retry_after(): int {
		return max( 1, MINUTE_IN_SECONDS - ( time() % MINUTE_IN_SECONDS ) );
	}

	/**
	 * Builds the bucket key for a bot and minute.
	 *
	 * @param string $bot    Bot identifier.
	 * @param int    $minute Unix minute.
	 * @return string
	 */
	private static function key( string $bot, int $minute ): string {
		return self::TRANSIENT_PREFIX . substr( md5( strtolower( $bot ) ), 0, 12 ) . '_' . $minute;
	}
}

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
 * Transient-backed sliding window: the current and previous minute buckets
 * are weighted by how far into the current minute we are. Off by default;
 * only known crawlers are counted, never a human on ?format=md.
 */
class MD4AI_Rate_Limiter {

	public const TRANSIENT_PREFIX = 'md4ai_rl_';

	/**
	 * Whether the current request exceeds the configured per-bot limit.
	 *
	 * Counts the request as a side effect: a throttled bot keeps paying for
	 * each retry, so hammering through the window never succeeds.
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

		$limit = (int) apply_filters( 'md4ai_rate_limit', (int) md4ai_get_setting( 'rate_limit_per_minute' ), $bot );
		if ( $limit <= 0 ) {
			return false;
		}

		$now      = time();
		$minute   = intdiv( $now, MINUTE_IN_SECONDS );
		$elapsed  = $now % MINUTE_IN_SECONDS;
		$key      = self::key( $bot, $minute );
		$current  = (int) get_transient( $key );
		$previous = (int) get_transient( self::key( $bot, $minute - 1 ) );

		// Two buckets outlive the window by design: the previous minute is
		// still read until the current one ends.
		set_transient( $key, $current + 1, 2 * MINUTE_IN_SECONDS );

		$weighted = $previous * ( ( MINUTE_IN_SECONDS - $elapsed ) / MINUTE_IN_SECONDS ) + $current + 1;

		return $weighted > $limit;
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
	 * Bot names come from the owner's list and may contain characters a
	 * transient key cannot; hash them, and keep the whole key well under the
	 * 172-character option-name limit.
	 *
	 * @param string $bot    Bot identifier.
	 * @param int    $minute Unix minute.
	 * @return string
	 */
	private static function key( string $bot, int $minute ): string {
		return self::TRANSIENT_PREFIX . substr( md5( strtolower( $bot ) ), 0, 12 ) . '_' . $minute;
	}
}

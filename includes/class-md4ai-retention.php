<?php
/**
 * Daily log retention.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs daily pruning of request logs.
 */
class MD4AI_Retention {

	public const CRON_HOOK = 'md4ai_prune_logs';

	/**
	 * Registers the cron action.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedules the daily event if not already scheduled.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedules the daily event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Deletes rows older than the configured retention window.
	 */
	public static function run(): void {
		global $wpdb;

		$days = (int) md4ai_get_setting( 'log_retention_days' );
		if ( ! in_array( $days, array( 7, 30, 90, 365 ), true ) ) {
			$days = 30;
		}

		$table = MD4AI_Logger::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}

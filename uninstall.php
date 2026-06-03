<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all plugin transients (md4ai_md_{post_id}, md4ai_llms_txt, md4ai_sitemap).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_md4ai_%'
	    OR option_name LIKE '_transient_timeout_md4ai_%'"
);

// Drop the requests table if it exists.
$md4ai_table = $wpdb->prefix . 'md4ai_requests';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $md4ai_table ) );

// Unschedule the prune cron, if any.
$md4ai_timestamp = wp_next_scheduled( 'md4ai_prune_logs' );
if ( $md4ai_timestamp ) {
	wp_unschedule_event( $md4ai_timestamp, 'md4ai_prune_logs' );
}

delete_option( 'md4ai_settings' );
delete_option( 'md4ai_db_version' );

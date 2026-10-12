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

// Delete all plugin transients from wp_options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_md4ai_%'
	    OR option_name LIKE '_transient_timeout_md4ai_%'"
);

// On a persistent object cache transients never touched wp_options, so the
// sweep above finds nothing there; delete every known key through the API.
// Rate-limit buckets (md4ai_rl_*) are not enumerable and expire in 2 minutes.
if ( wp_using_ext_object_cache() ) {
	foreach ( array( 'md4ai_llms_txt', 'md4ai_llms_full_txt', 'md4ai_sitemap', 'md4ai_rsl_xml', 'md4ai_okf_index', 'md4ai_okf_log', 'md4ai_okf_map' ) as $md4ai_key ) {
		delete_transient( $md4ai_key );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ID scan for a one-off cache purge.
	$md4ai_post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish'" );
	foreach ( (array) $md4ai_post_ids as $md4ai_post_id ) {
		delete_transient( 'md4ai_md_' . (int) $md4ai_post_id );
		delete_transient( 'md4ai_okf_c_' . (int) $md4ai_post_id );
	}
}

// Per-post "Exclude from AI" flags.
delete_post_meta_by_key( '_md4ai_exclude' );

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

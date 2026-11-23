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

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_md4ai_%'
	    OR option_name LIKE '_transient_timeout_md4ai_%'"
);

// A persistent object cache keeps transients out of wp_options, so the sweep above misses them.
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

delete_post_meta_by_key( '_md4ai_exclude' );

$md4ai_table = $wpdb->prefix . 'md4ai_requests';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $md4ai_table ) );

foreach ( array( 'md4ai_prune_logs', 'md4ai_refresh_ip_ranges' ) as $md4ai_hook ) {
	$md4ai_timestamp = wp_next_scheduled( $md4ai_hook );
	if ( $md4ai_timestamp ) {
		wp_unschedule_event( $md4ai_timestamp, $md4ai_hook );
	}
}

delete_option( 'md4ai_settings' );
delete_option( 'md4ai_db_version' );
delete_option( 'md4ai_ip_ranges' );

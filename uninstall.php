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

// Delete all plugin transients (md4ai_md_{post_id} and md4ai_llms_txt).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_md4ai_%'
	    OR option_name LIKE '_transient_timeout_md4ai_%'"
);

delete_option( 'md4ai_settings' );

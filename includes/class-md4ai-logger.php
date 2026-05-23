<?php
/**
 * Request logger and schema owner.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists request rows for the analytics layer.
 */
class MD4AI_Logger {

	private const DB_VERSION = '1';

	/**
	 * Returns the full table name including the WordPress prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'md4ai_requests';
	}

	/**
	 * Creates or upgrades the requests table via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(255) NOT NULL DEFAULT '',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			bot_name VARCHAR(100) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			bytes_served INT UNSIGNED NOT NULL DEFAULT 0,
			cache_hit TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_bot (bot_name, created_at),
			KEY idx_post (post_id, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'md4ai_db_version', self::DB_VERSION );
	}

	/**
	 * Drops the requests table.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'md4ai_db_version' );
	}

	/**
	 * Writes a request row.
	 *
	 * @param array{post_id:int,url:string,user_agent:string,bot_name:string,bytes_served:int,cache_hit:bool} $context Request context.
	 */
	public static function record( array $context ): void {
		if ( ! md4ai_get_setting( 'enable_logging' ) ) {
			return;
		}

		$allow = apply_filters( 'md4ai_log_request', true, $context );
		if ( ! $allow ) {
			return;
		}

		global $wpdb;

		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_hash = '' === $ip ? '' : hash( 'sha256', $ip . wp_salt( 'auth' ) );

		$row = array(
			'post_id'      => max( 0, (int) ( $context['post_id'] ?? 0 ) ),
			'url'          => mb_substr( (string) ( $context['url'] ?? '' ), 0, 255 ),
			'user_agent'   => mb_substr( (string) ( $context['user_agent'] ?? '' ), 0, 500 ),
			'bot_name'     => mb_substr( (string) ( $context['bot_name'] ?? '' ), 0, 100 ),
			'ip_hash'      => $ip_hash,
			'bytes_served' => max( 0, (int) ( $context['bytes_served'] ?? 0 ) ),
			'cache_hit'    => ! empty( $context['cache_hit'] ) ? 1 : 0,
			'created_at'   => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table_name(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		$row_id = (int) $wpdb->insert_id;
		if ( $row_id > 0 ) {
			do_action( 'md4ai_logged_request', $row_id, $context );
		}
	}

	/**
	 * Deletes all rows.
	 */
	public static function purge_all(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}

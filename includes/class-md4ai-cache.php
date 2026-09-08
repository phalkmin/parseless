<?php
/**
 * Markdown cache.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores generated Markdown in transients.
 */
class MD4AI_Cache {

	private const PREFIX = 'md4ai_md_';

	/**
	 * Gets cached Markdown for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|false
	 */
	public static function get( int $post_id ): string|false {
		return get_transient( self::key( $post_id ) );
	}

	/**
	 * Stores Markdown for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $markdown Markdown content.
	 */
	public static function set( int $post_id, string $markdown ): void {
		$ttl = (int) md4ai_get_setting( 'cache_ttl' );
		$ttl = (int) apply_filters( 'md4ai_cache_ttl', $ttl );
		set_transient( self::key( $post_id ), $markdown, $ttl );
	}

	/**
	 * Deletes cached Markdown for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete( int $post_id ): void {
		delete_transient( self::key( $post_id ) );
	}

	/**
	 * Deletes every cached Markdown body.
	 */
	public static function purge_all(): void {
		self::purge_prefix( self::PREFIX );
	}

	/**
	 * Deletes every transient whose name starts with a prefix.
	 *
	 * Per-post keys are not enumerable via the transients API, hence the
	 * direct LIKE delete — on persistent object caches the rows aren't in
	 * wp_options and stale entries simply expire by TTL.
	 *
	 * @param string $prefix Transient-name prefix, e.g. "md4ai_md_".
	 */
	public static function purge_prefix( string $prefix ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prefixed transient keys cannot be enumerated via the transients API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}

	/**
	 * Builds the transient key for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function key( int $post_id ): string {
		return self::PREFIX . $post_id;
	}
}

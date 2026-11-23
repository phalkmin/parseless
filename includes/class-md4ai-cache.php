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
	 * Published post IDs, memoised for the request.
	 *
	 * @var int[]|null
	 */
	private static ?array $post_ids = null;

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
	 * Stores Markdown for a post unless the cache is bypassed.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $markdown Markdown content.
	 */
	public static function set( int $post_id, string $markdown ): void {
		if ( self::bypass() ) {
			return;
		}

		set_transient( self::key( $post_id ), $markdown, self::ttl( $post_id ) );
	}

	/**
	 * Returns the filtered cache lifetime for a post's documents.
	 *
	 * @param int $post_id Post ID, or 0 for multi-post documents.
	 * @return int Seconds.
	 */
	public static function ttl( int $post_id = 0 ): int {
		return (int) apply_filters( 'md4ai_cache_ttl', (int) md4ai_get_setting( 'cache_ttl' ), $post_id );
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
	 * Whether this request must skip shared-cache writes, as logged-in renders may be personalised.
	 *
	 * @return bool
	 */
	public static function bypass(): bool {
		return (bool) apply_filters( 'md4ai_cache_bypass', is_user_logged_in() );
	}

	/**
	 * Deletes every cached Markdown body, on every cache backend.
	 */
	public static function purge_all(): void {
		self::purge_prefix( self::PREFIX );
		self::purge_object_cache( self::PREFIX );
	}

	/**
	 * Deletes every wp_options transient whose name starts with a prefix.
	 *
	 * @param string $prefix Transient-name prefix.
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
	 * Deletes every post's prefixed transient from a persistent object cache.
	 *
	 * @param string $prefix Transient-name prefix.
	 */
	public static function purge_object_cache( string $prefix ): void {
		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		foreach ( self::all_post_ids() as $post_id ) {
			delete_transient( $prefix . $post_id );
		}
	}

	/**
	 * Returns the ID of every published post, of any type, that can have a cached document.
	 *
	 * @return int[]
	 */
	private static function all_post_ids(): array {
		global $wpdb;

		if ( null === self::$post_ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ID scan for a cache purge; nothing here to cache.
			$ids            = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish'" );
			self::$post_ids = array_map( 'intval', (array) $ids );
		}

		return self::$post_ids;
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

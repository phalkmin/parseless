<?php
/**
 * Markdown cache.
 *
 * @package WP_Botfood
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
	 * Builds the transient key for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function key( int $post_id ): string {
		return self::PREFIX . $post_id;
	}
}

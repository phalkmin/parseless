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
	 * Published post IDs, memoised for the request (see all_post_ids()).
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
	 * Stores Markdown for a post, unless this request must not populate the
	 * shared cache (see bypass()).
	 *
	 * The md4ai_cache_ttl filter receives the post ID so callers can vary the
	 * lifetime per post (products, for instance, go stale faster).
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
	 * Returns the cache lifetime for a post's documents: the configured TTL
	 * with the md4ai_cache_ttl filter applied.
	 *
	 * The one place the filter runs for per-post documents, so the single-post
	 * Markdown, the OKF concept and llms-full.txt all agree on how long a
	 * given post may be cached.
	 *
	 * @param int $post_id Post ID, or 0 for a document spanning many posts.
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
	 * Whether the current request must not write to any shared cache.
	 *
	 * Rendering runs the_content in the requester's own session, so a
	 * logged-in request can see what a membership plugin hides from the
	 * public, or hide what it shows. Every cache in this plugin is keyed
	 * without regard to who asked; storing a personalised rendering in one
	 * would hand it to whoever asks next. Reads stay allowed — whatever is
	 * cached was rendered anonymously, which is the public view these
	 * endpoints exist to serve.
	 *
	 * Integrations whose output varies by other request state (a cookie, a
	 * geolocated currency) can force the bypass via md4ai_cache_bypass.
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
	 * Deletes every transient whose name starts with a prefix.
	 *
	 * Per-post keys are not enumerable via the transients API, hence the
	 * direct LIKE delete. On a persistent object cache the transients never
	 * touched wp_options, so this finds nothing there; callers that need a
	 * full clear on every backend follow up with purge_object_cache().
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
	 * Deletes "<prefix><post ID>" for every post from a persistent object
	 * cache, where purge_prefix()'s sweep cannot reach.
	 *
	 * O(posts), so callers reserve it for settings saves — where the
	 * alternative is a settings change that takes up to a week to show —
	 * and never pay it on a single post save.
	 *
	 * @param string $prefix Transient-name prefix, e.g. "md4ai_md_".
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
	 * Returns the ID of every post that can have a cached document.
	 *
	 * Only published posts are ever cached (is_post_servable), whatever
	 * their type — so no type filter, which would miss types added through
	 * the md4ai_supported_post_types filter. Memoised because a settings
	 * save purges two prefixes and need not scan twice.
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

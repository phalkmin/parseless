<?php
/**
 * Main Markdown serving controller.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates Markdown output and cache invalidation.
 */
class MD4AI {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_markdown' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			add_action( $meta_hook, array( __CLASS__, 'on_post_meta_change' ), 10, 3 );
		}
		add_action( 'init', array( __CLASS__, 'maybe_serve_llms_txt' ) );
		add_action( 'init', array( 'MD4AI_OKF', 'maybe_serve' ) );
		MD4AI_Headers::init();
	}

	/**
	 * Serves Markdown for eligible singular requests.
	 */
	public static function maybe_serve_markdown(): void {
		$should_serve = apply_filters( 'md4ai_should_serve_markdown', MD4AI_Detector::should_serve() );
		if ( ! $should_serve ) {
			self::maybe_log_unknown_ua();
			self::maybe_log_html_bot_hit();
			return;
		}

		// Archives, the home page, unservable posts: the bot still gets HTML
		// here, and that contact is what the reachability table counts.
		$post_types = md4ai_enabled_post_types();
		if ( empty( $post_types ) || ! is_singular( $post_types ) ) {
			self::maybe_log_html_bot_hit();
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			self::maybe_log_html_bot_hit();
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! MD4AI_Detector::is_post_servable( $post ) ) {
			self::maybe_log_html_bot_hit();
			return;
		}

		MD4AI_Headers::enforce_access( $post_id );

		$cache_hit = true;
		$markdown  = MD4AI_Cache::get( $post_id );
		if ( false === $markdown ) {
			$markdown  = self::build_markdown_for_preview( $post );
			$cache_hit = false;
			MD4AI_Cache::set( $post_id, $markdown );
		}

		MD4AI_Logger::record(
			array(
				'post_id'      => $post_id,
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => MD4AI_Detector::bot_name(),
				'bytes_served' => strlen( $markdown ),
				'cache_hit'    => $cache_hit,
			)
		);

		MD4AI_Headers::send_markdown( $markdown );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text Markdown response, not HTML; entity-encoding would corrupt the output.
		echo $markdown;
		exit;
	}

	/**
	 * Logs a request whose UA matched the unknown-UA heuristic.
	 */
	private static function maybe_log_unknown_ua(): void {
		if ( ! md4ai_get_setting( 'enable_logging' ) ) {
			return;
		}
		if ( ! md4ai_get_setting( 'log_unknown_uas' ) ) {
			return;
		}
		if ( '' === MD4AI_Detector::unknown_ua_match() ) {
			return;
		}

		MD4AI_Logger::record(
			array(
				'post_id'      => (int) get_queried_object_id(),
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => 'unknown',
				'bytes_served' => 0,
				'cache_hit'    => false,
			)
		);
	}

	/**
	 * Logs a known crawler that is about to receive the normal HTML page.
	 *
	 * This is the "does GPTBot even reach me?" signal: a bot that never shows
	 * up here, or only ever as 429s, is being stopped upstream by the host.
	 * Cached HTML never reaches PHP, so the view is a floor, not a total.
	 */
	private static function maybe_log_html_bot_hit(): void {
		if ( ! md4ai_get_setting( 'enable_logging' ) || ! md4ai_get_setting( 'log_all_bot_traffic' ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ! MD4AI_Detector::is_bot_request() ) {
			return;
		}

		MD4AI_Logger::record(
			array(
				'post_id'      => (int) get_queried_object_id(),
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => MD4AI_Detector::bot_name(),
				'bytes_served' => 0,
				'cache_hit'    => false,
				'served'       => MD4AI_Logger::SERVED_HTML,
			)
		);
	}

	/**
	 * Renders a post's content to Markdown with full post context.
	 *
	 * Page builders (Elementor, Divi, WPBakery) hook `the_content` and render
	 * from the current document rather than the string passed in, so calling
	 * the filter outside the loop yields empty output. setup_postdata() gives
	 * them the document they expect.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function render_body( WP_Post $post ): string {
		$previous = $GLOBALS['post'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately setting the global page builders read from `the_content`; restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally calling WordPress core filter
		$html = apply_filters( 'the_content', $post->post_content );

		if ( $previous instanceof WP_Post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the previous post context, not overriding it.
			$GLOBALS['post'] = $previous;
			setup_postdata( $previous );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}

		return MD4AI_Converter::convert( $html );
	}

	/**
	 * Builds Markdown output for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_markdown_for_preview( WP_Post $post ): string {
		$title  = md4ai_plain_text( get_the_title( $post ) );
		$url    = get_permalink( $post );
		$date   = get_the_date( 'c', $post );
		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		$body   = self::render_body( $post );

		if ( md4ai_get_setting( 'include_frontmatter' ) ) {
			$tags       = array_map( fn( $t ) => md4ai_plain_text( $t->name ), wp_get_post_tags( $post->ID ) );
			$categories = array_map( fn( $t ) => md4ai_plain_text( $t->name ), wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) ) );
			$excerpt    = has_excerpt( $post ) ? md4ai_plain_text( get_the_excerpt( $post ) ) : '';
			$md         = "---\n";
			$md        .= 'title: ' . md4ai_yaml_quote( $title ) . "\n";
			$md        .= "url: {$url}\n";
			$md        .= 'author: ' . md4ai_yaml_quote( $author ) . "\n";
			$md        .= "date: {$date}\n";
			$md        .= 'categories: [' . implode( ', ', array_map( 'md4ai_yaml_quote', $categories ) ) . "]\n";
			$md        .= 'tags: [' . implode( ', ', array_map( 'md4ai_yaml_quote', $tags ) ) . "]\n";
			if ( '' !== $excerpt ) {
				$md .= 'excerpt: ' . md4ai_yaml_quote( $excerpt ) . "\n";
			}
			$md .= "---\n\n";
			$md .= "# {$title}\n\n";
		} else {
			$md  = "# {$title}\n\n";
			$md .= "> Source: {$url}  \n";
			$md .= "> Author: {$author}  \n";
			$md .= "> Date: {$date}\n\n";
		}

		$md .= $body;

		if ( md4ai_get_setting( 'include_schema' ) ) {
			$md .= "\n\n" . self::schema_block( $post, $title, $url, $author, $date );
		}

		return apply_filters( 'md4ai_markdown_output', $md, $post );
	}

	/**
	 * Builds a schema.org Article JSON-LD block from data we already have.
	 *
	 * @param WP_Post $post   Post object.
	 * @param string  $title  Post title.
	 * @param string  $url    Permalink.
	 * @param string  $author Author display name.
	 * @param string  $date   Publish date (ISO 8601).
	 * @return string
	 */
	private static function schema_block( WP_Post $post, string $title, string $url, string $author, string $date ): string {
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => $title,
			'url'           => $url,
			'datePublished' => $date,
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => $author,
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => md4ai_site_name(),
				'url'   => home_url( '/' ),
			),
		);

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( $image ) {
			$schema['image'] = $image;
		}

		return "```json\n" . wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n```\n";
	}

	/**
	 * Clears cached Markdown when a post is saved.
	 *
	 * Revisions and autosaves never change what any endpoint serves, and the
	 * OKF clear below is a full-bundle wipe — without this guard every editor
	 * heartbeat would trigger it and keep the O(N) slug map perpetually cold.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_save_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::clear_post_caches( $post_id );
	}

	/**
	 * Clears cached documents when a post is permanently deleted.
	 *
	 * Trashing goes through wp_update_post() and reaches on_save_post(). A
	 * direct delete — WP-CLI, EMPTY_TRASH_DAYS = 0, a post type without
	 * trash — fires nothing on the save side, and would leave the post's
	 * content in llms-full.txt and its OKF concept until they expire.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    The post as it was before deletion. Core
	 *                              always passes it; a third-party re-fire of
	 *                              the hook may not, and then we clear anyway.
	 */
	public static function on_deleted_post( int $post_id, ?WP_Post $post = null ): void {
		if ( $post instanceof WP_Post && ! self::serves_type( $post->post_type ) ) {
			return;
		}

		self::clear_post_caches( $post_id );
	}

	/**
	 * Clears cached documents when a noindex flag changes without a post save.
	 *
	 * SEO plugins' bulk editors and WP-CLI write these flags with
	 * update_post_meta() alone, and is_post_servable() reads them, so a
	 * post's eligibility can flip with no save_post to notice it.
	 *
	 * @param int|int[] $meta_id  Meta row ID(s); unused.
	 * @param int       $post_id  Post the meta belongs to.
	 * @param string    $meta_key Meta key.
	 */
	public static function on_post_meta_change( $meta_id, int $post_id, string $meta_key ): void {
		if ( ! in_array( $meta_key, MD4AI_Detector::NOINDEX_META_KEYS, true ) ) {
			return;
		}

		// Attachments and other unserved types carry these flags too; a bulk
		// edit over them must not wipe the bundle once per row.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! self::serves_type( $post->post_type ) ) {
			return;
		}

		self::clear_post_caches( $post_id );
	}

	/**
	 * Whether posts of a type can appear in any document this plugin serves.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function serves_type( string $post_type ): bool {
		return in_array( $post_type, md4ai_enabled_post_types(), true );
	}

	/**
	 * Drops every cached document a change to one post can invalidate.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function clear_post_caches( int $post_id ): void {
		MD4AI_Cache::delete( $post_id );
		MD4AI_Sitemap::clear_cache();
		MD4AI_LLMsTxt::clear_cache();
		MD4AI_OKF::clear_cache( $post_id );
	}

	/**
	 * Delegates llms.txt handling.
	 */
	public static function maybe_serve_llms_txt(): void {
		MD4AI_LLMsTxt::maybe_serve();
	}
}

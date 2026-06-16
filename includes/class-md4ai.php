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
		add_action( 'init', array( __CLASS__, 'maybe_serve_llms_txt' ) );
	}

	/**
	 * Serves Markdown for eligible singular requests.
	 */
	public static function maybe_serve_markdown(): void {
		$should_serve = apply_filters( 'md4ai_should_serve_markdown', MD4AI_Detector::should_serve() );
		if ( ! $should_serve ) {
			self::maybe_log_unknown_ua();
			return;
		}

		$enabled    = md4ai_get_setting( 'enabled_post_types' );
		$all        = md4ai_supported_post_types();
		$base_types = ( ! empty( $enabled ) && is_array( $enabled ) ) ? array_intersect( $all, $enabled ) : $all;
		$post_types = apply_filters( 'md4ai_supported_post_types', array_values( $base_types ) );
		if ( ! is_singular( $post_types ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! MD4AI_Detector::is_post_servable( $post ) ) {
			return;
		}

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
				'url'          => self::current_url(),
				'user_agent'   => self::current_ua(),
				'bot_name'     => self::resolve_bot_name(),
				'bytes_served' => strlen( $markdown ),
				'cache_hit'    => $cache_hit,
			)
		);

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
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
				'url'          => self::current_url(),
				'user_agent'   => self::current_ua(),
				'bot_name'     => 'unknown',
				'bytes_served' => 0,
				'cache_hit'    => false,
			)
		);
	}

	/**
	 * Resolves the bot identifier for the current request.
	 *
	 * @return string Bot UA substring, "manual", or "" if unknown.
	 */
	private static function resolve_bot_name(): string {
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$param = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
		if ( 'md' === $param ) {
			return 'manual';
		}
		$ua = self::current_ua();
		if ( '' === $ua ) {
			return '';
		}
		foreach ( MD4AI_Detector::get_bot_list() as $bot ) {
			if ( false !== stripos( $ua, (string) $bot ) ) {
				return (string) $bot;
			}
		}
		return '';
	}

	/**
	 * Returns the current request URL.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * Returns the current request User-Agent string.
	 *
	 * @return string
	 */
	private static function current_ua(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	}

	/**
	 * Builds Markdown output for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_markdown_for_preview( WP_Post $post ): string {
		$title  = get_the_title( $post );
		$url    = get_permalink( $post );
		$date   = get_the_date( 'c', $post );
		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally calling WordPress core filter
		$html = apply_filters( 'the_content', $post->post_content );
		$body = MD4AI_Converter::convert( $html );

		if ( md4ai_get_setting( 'include_frontmatter' ) ) {
			$tags       = array_map( fn( $t ) => $t->name, wp_get_post_tags( $post->ID ) );
			$categories = array_map( fn( $t ) => $t->name, wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) ) );
			$excerpt    = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
			$md         = "---\n";
			$md        .= 'title: "' . str_replace( '"', '\\"', $title ) . "\"\n";
			$md        .= "url: {$url}\n";
			$md        .= "author: {$author}\n";
			$md        .= "date: {$date}\n";
			$md        .= 'categories: [' . implode( ', ', $categories ) . "]\n";
			$md        .= 'tags: [' . implode( ', ', $tags ) . "]\n";
			if ( '' !== $excerpt ) {
				$md .= 'excerpt: "' . str_replace( '"', '\\"', $excerpt ) . "\"\n";
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
				'name'  => get_bloginfo( 'name' ),
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
	 * @param int $post_id Post ID.
	 */
	public static function on_save_post( int $post_id ): void {
		MD4AI_Cache::delete( $post_id );
		MD4AI_Sitemap::clear_cache();
		MD4AI_LLMsTxt::clear_cache();
	}

	/**
	 * Delegates llms.txt handling.
	 */
	public static function maybe_serve_llms_txt(): void {
		MD4AI_LLMsTxt::maybe_serve();
	}
}

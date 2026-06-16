<?php
/**
 * LLMs.txt endpoint.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the llms.txt endpoint.
 */
class MD4AI_LLMsTxt {

	private const TRANSIENT_KEY      = 'md4ai_llms_txt';
	private const FULL_TRANSIENT_KEY = 'md4ai_llms_full_txt';

	/**
	 * Serves the llms.txt / llms-full.txt response when requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'llmstxt_enabled' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

		if ( 'llms.txt' === $path ) {
			self::serve( self::TRANSIENT_KEY, self::build( ... ), HOUR_IN_SECONDS );
		} elseif ( 'llms-full.txt' === $path ) {
			self::serve( self::FULL_TRANSIENT_KEY, self::build_full( ... ), DAY_IN_SECONDS );
		}
	}

	/**
	 * Serves a cached plain-text document, building it on cache miss.
	 *
	 * @param string   $key         Transient key.
	 * @param callable $builder     Builds the document on cache miss.
	 * @param int      $default_ttl Default cache TTL in seconds.
	 */
	private static function serve( string $key, callable $builder, int $default_ttl ): void {
		$cached = get_transient( $key );
		if ( false === $cached ) {
			$cached = $builder();
			$ttl    = (int) apply_filters( 'md4ai_cache_ttl', $default_ttl );
			set_transient( $key, $cached, $ttl );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text response, not HTML; entity-encoding would corrupt the output.
		echo $cached;
		exit;
	}

	/**
	 * Builds the llms.txt content.
	 *
	 * @return string
	 */
	private static function build(): string {
		$site = get_bloginfo( 'name' );
		$desc = get_bloginfo( 'description' );
		$out  = "# {$site}\n\n> {$desc}\n\n## Posts\n\n";

		foreach ( self::get_listed_posts() as $post ) {
			$excerpt = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$out    .= sprintf(
				"- [%s](%s): %s\n",
				get_the_title( $post ),
				get_permalink( $post ),
				wp_trim_words( $excerpt, 20 )
			);
		}

		return $out;
	}

	/**
	 * Builds the llms-full.txt content: full Markdown of the listed posts,
	 * concatenated up to a size cap.
	 *
	 * @return string
	 */
	private static function build_full(): string {
		$site      = get_bloginfo( 'name' );
		$out       = "# {$site} — full content\n\n";
		$max_bytes = (int) apply_filters( 'md4ai_llmsfull_max_bytes', 500 * KB_IN_BYTES );

		foreach ( self::get_listed_posts() as $post ) {
			$markdown = MD4AI_Cache::get( $post->ID );
			if ( false === $markdown ) {
				$markdown = MD4AI::build_markdown_for_preview( $post );
				MD4AI_Cache::set( $post->ID, $markdown );
			}
			$section = $markdown . "\n\n---\n\n";
			if ( strlen( $out ) + strlen( $section ) > $max_bytes ) {
				break;
			}
			$out .= $section;
		}

		return $out;
	}

	/**
	 * Returns the servable posts both endpoints list.
	 *
	 * @return WP_Post[]
	 */
	private static function get_listed_posts(): array {
		$post_types = apply_filters( 'md4ai_supported_post_types', md4ai_supported_post_types() );
		$max_posts  = (int) apply_filters( 'md4ai_llmstxt_max_posts', md4ai_get_setting( 'llmstxt_max_posts' ) );
		$posts      = get_posts(
			array(
				'numberposts' => $max_posts,
				'post_status' => 'publish',
				'post_type'   => $post_types,
			)
		);

		return array_values( array_filter( $posts, array( MD4AI_Detector::class, 'is_post_servable' ) ) );
	}

	/**
	 * Clears the cached llms.txt and llms-full.txt output.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
		delete_transient( self::FULL_TRANSIENT_KEY );
	}
}

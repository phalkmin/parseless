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

		$path = md4ai_request_path();

		if ( 'llms.txt' === $path ) {
			self::serve( self::TRANSIENT_KEY, self::build( ... ) );
		} elseif ( 'llms-full.txt' === $path ) {
			MD4AI_Headers::enforce_access();
			// Access checks make this vary by User-Agent, so a shared cache must not store it.
			header( 'Cache-Control: private' );
			self::serve( self::FULL_TRANSIENT_KEY, self::build_full( ... ) );
		}
	}

	/**
	 * Serves a cached plain-text document, building it on cache miss.
	 *
	 * @param string   $key     Transient key.
	 * @param callable $builder Returns the document and its final cache lifetime.
	 */
	private static function serve( string $key, callable $builder ): void {
		$cache_hit = true;
		$cached    = get_transient( $key );
		if ( false === $cached ) {
			list( $cached, $ttl ) = $builder();

			$cache_hit = false;
			if ( ! MD4AI_Cache::bypass() ) {
				set_transient( $key, $cached, $ttl );
			}
		}

		MD4AI_Logger::record(
			array(
				'post_id'      => 0,
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => MD4AI_Detector::bot_name(),
				'bytes_served' => strlen( $cached ),
				'cache_hit'    => $cache_hit,
				'served'       => self::TRANSIENT_KEY === $key ? MD4AI_Logger::SERVED_INDEX : MD4AI_Logger::SERVED_MARKDOWN,
			)
		);

		header( 'Content-Type: text/plain; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text llms.txt; must not be entity-encoded.
		echo $cached;
		exit;
	}

	/**
	 * Builds the llms.txt content.
	 *
	 * @return array{0: string, 1: int} Document and cache lifetime in seconds.
	 */
	private static function build(): array {
		$site = md4ai_site_name();
		$desc = md4ai_site_description();
		$out  = "# {$site}\n\n> {$desc}\n\n";

		if ( md4ai_get_setting( 'okf_enabled' ) ) {
			$out .= "## Optional\n\n- [OKF bundle](" . MD4AI_OKF::bundle_url() . '): Open Knowledge Format v' . MD4AI_OKF::version() . " bundle of this site's content for AI agents.\n\n";
		}

		$out .= "## Posts\n\n";

		foreach ( self::get_listed_posts() as $post ) {
			$excerpt = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$out    .= sprintf(
				"- [%s](%s): %s\n",
				md4ai_plain_text( get_the_title( $post ) ),
				get_permalink( $post ),
				md4ai_plain_text( wp_trim_words( $excerpt, 20 ) )
			);
		}

		return array( $out, (int) apply_filters( 'md4ai_cache_ttl', HOUR_IN_SECONDS, 0 ) );
	}

	/**
	 * Builds the llms-full.txt content up to a size cap.
	 *
	 * @return array{0: string, 1: int} Document and cache lifetime in seconds.
	 */
	private static function build_full(): array {
		$site      = md4ai_site_name();
		$out       = "# {$site} — full content\n\n";
		$max_bytes = (int) apply_filters( 'md4ai_llmsfull_max_bytes', 500 * KB_IN_BYTES );
		$ttl       = (int) apply_filters( 'md4ai_cache_ttl', DAY_IN_SECONDS, 0 );

		foreach ( self::get_listed_posts() as $post ) {
			// Read-only: writing here would let an llms-full.txt build decide what the single-post endpoint serves.
			$markdown = MD4AI_Cache::get( $post->ID );
			if ( false === $markdown ) {
				$markdown = MD4AI::build_markdown_for_preview( $post );
			}
			$section = $markdown . "\n\n---\n\n";
			if ( strlen( $out ) + strlen( $section ) > $max_bytes ) {
				continue;
			}
			$out .= $section;

			$post_ttl = MD4AI_Cache::ttl( $post->ID );
			if ( $post_ttl > 0 ) {
				$ttl = min( $ttl, $post_ttl );
			}
		}

		return array( $out, $ttl );
	}

	/**
	 * Returns the servable posts both endpoints list.
	 *
	 * @return WP_Post[]
	 */
	public static function get_listed_posts(): array {
		$post_types = md4ai_enabled_post_types();
		if ( empty( $post_types ) ) {
			return array();
		}
		$max_posts = (int) apply_filters( 'md4ai_llmstxt_max_posts', md4ai_get_setting( 'llmstxt_max_posts' ) );
		$posts     = get_posts(
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

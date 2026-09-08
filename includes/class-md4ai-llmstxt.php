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
			self::serve( self::TRANSIENT_KEY, self::build( ... ), HOUR_IN_SECONDS );
		} elseif ( 'llms-full.txt' === $path ) {
			// Full content, so the Blocked Bots list applies as it does on the
			// Markdown endpoint; the llms.txt index stays open to everyone.
			if ( MD4AI_Detector::is_blocked_request() ) {
				MD4AI_Headers::send_forbidden();
			}
			// The block makes this URL vary by User-Agent, so a shared cache
			// keyed on URL alone must not store it (llms.txt stays cacheable).
			header( 'Cache-Control: private' );
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

		return $out;
	}

	/**
	 * Builds the llms-full.txt content: full Markdown of the listed posts,
	 * concatenated up to a size cap.
	 *
	 * @return string
	 */
	private static function build_full(): string {
		$site      = md4ai_site_name();
		$out       = "# {$site} — full content\n\n";
		$max_bytes = (int) apply_filters( 'md4ai_llmsfull_max_bytes', 500 * KB_IN_BYTES );

		foreach ( self::get_listed_posts() as $post ) {
			// Read the shared per-post cache but never write it: this document
			// has its own transient, and writing here would let a llms-full.txt
			// build decide what the single-post endpoint serves.
			$markdown = MD4AI_Cache::get( $post->ID );
			if ( false === $markdown ) {
				$markdown = MD4AI::build_markdown_for_preview( $post );
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

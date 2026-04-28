<?php
/**
 * LLMs.txt endpoint.
 *
 * @package WP_Botfood
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the llms.txt endpoint.
 */
class MD4AI_LLMsTxt {

	private const TRANSIENT_KEY = 'md4ai_llms_txt';

	/**
	 * Serves the llms.txt response when requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'llmstxt_enabled' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		if ( 'llms.txt' !== $path ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false === $cached ) {
			$cached = self::build();
			$ttl    = (int) apply_filters( 'md4ai_cache_ttl', HOUR_IN_SECONDS );
			set_transient( self::TRANSIENT_KEY, $cached, $ttl );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $cached );
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

		$post_types = apply_filters( 'md4ai_supported_post_types', md4ai_supported_post_types() );
		$max_posts  = (int) apply_filters( 'md4ai_llmstxt_max_posts', md4ai_get_setting( 'llmstxt_max_posts' ) );
		$posts      = get_posts(
			array(
				'numberposts' => $max_posts,
				'post_status' => 'publish',
				'post_type'   => $post_types,
			)
		);

		foreach ( $posts as $post ) {
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
}

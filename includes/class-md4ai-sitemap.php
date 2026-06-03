<?php
/**
 * AI sitemap endpoint.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves /botfood-sitemap.xml pointing at the Markdown versions of posts.
 */
class MD4AI_Sitemap {

	private const TRANSIENT_KEY = 'md4ai_sitemap';

	/**
	 * Request path that triggers the sitemap (relative to site root).
	 */
	public const PATH = 'botfood-sitemap.xml';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );
	}

	/**
	 * Serves the sitemap when its path is requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'sitemap_enabled' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		if ( self::PATH !== $path ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false === $cached ) {
			$cached = self::build();
			$ttl    = (int) apply_filters( 'md4ai_cache_ttl', DAY_IN_SECONDS );
			set_transient( self::TRANSIENT_KEY, $cached, $ttl );
		}

		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URLs escaped with esc_url() during build(); scaffolding is static.
		echo $cached;
		exit;
	}

	/**
	 * Builds the sitemap XML.
	 *
	 * @return string
	 */
	private static function build(): string {
		$post_types = apply_filters( 'md4ai_supported_post_types', md4ai_supported_post_types() );
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		$max_posts  = (int) apply_filters( 'md4ai_sitemap_max_posts', 1000 );
		$posts      = get_posts(
			array(
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A sitemap needs every public post; bounded and filterable via md4ai_sitemap_max_posts.
				'numberposts' => $max_posts,
				'post_status' => 'publish',
				'post_type'   => $post_types,
			)
		);

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $posts as $post ) {
			if ( ! MD4AI_Detector::is_post_servable( $post ) ) {
				continue;
			}
			$loc     = add_query_arg( $param_name, 'md', get_permalink( $post ) );
			$lastmod = get_post_modified_time( 'c', true, $post );

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
			if ( $lastmod ) {
				$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
			}
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/**
	 * Advertises the Markdown endpoints in robots.txt.
	 *
	 * @param string $output    The robots.txt content.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public static function robots_txt( string $output, bool $is_public ): string {
		if ( ! $is_public || ! md4ai_get_setting( 'sitemap_enabled' ) ) {
			return $output;
		}
		$sitemap_url = home_url( '/' . self::PATH );
		$output     .= "\n# ParseLess: Markdown versions of content for AI crawlers\n";
		$output     .= 'Sitemap: ' . esc_url_raw( $sitemap_url ) . "\n";
		return $output;
	}

	/**
	 * Clears the cached sitemap.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}

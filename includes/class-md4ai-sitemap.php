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
	}

	/**
	 * Serves the sitemap when its path is requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'sitemap_enabled' ) ) {
			return;
		}

		if ( self::PATH !== md4ai_request_path() ) {
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
		$post_types = md4ai_enabled_post_types();
		$max_posts  = (int) apply_filters( 'md4ai_sitemap_max_posts', 1000 );
		$posts      = empty( $post_types ) ? array() : get_posts(
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
			$loc = MD4AI_Headers::markdown_url_for( $post );
			if ( '' === $loc ) {
				continue;
			}
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
	 * Clears the cached sitemap.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}

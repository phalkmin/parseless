<?php
/**
 * Request and post eligibility detection.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects Markdown requests and servable posts.
 */
class MD4AI_Detector {

	/**
	 * Default bot User-Agent substrings.
	 *
	 * @var string[]
	 */
	private static array $default_bot_list = array(
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-User',
		'anthropic-ai',
		'PerplexityBot',
		'Perplexity-User',
		'CCBot',
		'Google-Extended',
		'Applebot-Extended',
		'Bytespider',
		'Meta-ExternalAgent',
		'cohere-ai',
	);

	/**
	 * Determines whether the current request should receive Markdown.
	 *
	 * @return bool
	 */
	public static function should_serve(): bool {
		$detection_mode = md4ai_get_setting( 'detection_mode' );

		// If mode is 'ua', skip query param check entirely.
		if ( 'ua' === $detection_mode ) {
			return self::is_bot_request();
		}

		// Check query parameter.
		$param_name  = md4ai_get_setting( 'query_param_name' );
		$param_value = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection, no state change
		if ( 'md' === $param_value ) {
			return true;
		}

		// If mode is 'query_param', skip the UA check entirely.
		if ( 'query_param' === $detection_mode ) {
			return false;
		}

		// Otherwise mode is 'both' — check both.
		return self::is_bot_request();
	}

	/**
	 * Determines whether the current request User-Agent matches a configured bot.
	 *
	 * @return bool
	 */
	public static function is_bot_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UA used only for string matching, never in output
		$ua = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $ua ) {
			return false;
		}

		// Read bot list from settings if non-empty, otherwise use defaults.
		$saved_list = md4ai_get_setting( 'bot_list' );
		if ( is_string( $saved_list ) && '' !== trim( $saved_list ) ) {
			$lines = explode( "\n", $saved_list );
			$bots  = array_filter(
				array_map( 'trim', $lines ),
				static function ( $line ) {
					return '' !== $line;
				}
			);
		} else {
			$bots = self::$default_bot_list;
		}

		// Apply filter to allow customization.
		$bots = apply_filters( 'md4ai_bot_list', $bots );

		foreach ( $bots as $bot ) {
			if ( false !== stripos( $ua, $bot ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines whether a post can be served as Markdown.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_post_servable( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		if ( self::is_noindex( $post->ID ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Checks common SEO plugin noindex flags.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_noindex( int $post_id ): bool {
		// Yoast SEO.
		if ( class_exists( 'WPSEO_Meta' ) ) {
			$value = WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
			if ( '1' === (string) $value ) {
				return true;
			}
		}
		// Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
				return true;
			}
		}
		// Genesis Framework.
		if ( '1' === (string) get_post_meta( $post_id, '_genesis_noindex', true ) ) {
			return true;
		}
		return false;
	}
}

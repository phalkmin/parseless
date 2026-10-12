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
		'Claude-SearchBot',
		'anthropic-ai',
		'PerplexityBot',
		'Perplexity-User',
		'CCBot',
		'Google-Extended',
		'Applebot-Extended',
		'Bytespider',
		'Meta-ExternalAgent',
		'Meta-ExternalFetcher',
		'cohere-ai',
		'Amazonbot',
		'DuckAssistBot',
		'MistralAI-User',
	);

	/**
	 * Post meta keys whose value can flip is_post_servable().
	 *
	 * The invalidation-side counterpart of is_noindex(): a write to one of
	 * these without a post save must still clear the caches.
	 *
	 * @var string[]
	 */
	public const NOINDEX_META_KEYS = array(
		'_yoast_wpseo_meta-robots-noindex',
		'rank_math_robots',
		'_genesis_noindex',
		self::EXCLUDE_META_KEY,
	);

	/**
	 * Per-post "Exclude from AI" flag, set from the ParseLess metabox.
	 */
	public const EXCLUDE_META_KEY = '_md4ai_exclude';

	/**
	 * Set by MD4AI_Headers::maybe_strip_md_suffix() when the request URI ended
	 * in ".md" and the suffix was stripped before routing.
	 *
	 * @var bool
	 */
	private static bool $md_suffix = false;

	/**
	 * Records that this request arrived as a ".md" URL.
	 */
	public static function flag_md_suffix(): void {
		self::$md_suffix = true;
	}

	/**
	 * Whether this request arrived as a ".md" URL.
	 *
	 * @return bool
	 */
	public static function md_suffix_requested(): bool {
		return self::$md_suffix;
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

		foreach ( self::get_bot_list() as $bot ) {
			if ( false !== stripos( $ua, $bot ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines whether the request's Accept header prefers Markdown.
	 *
	 * Serves Markdown only when text/markdown is acceptable and at least as
	 * preferred as text/html. A wildcard "* / *" — curl's default — never
	 * qualifies, and a malformed header falls through to HTML.
	 *
	 * @return bool
	 */
	public static function accepts_markdown(): bool {
		if ( ! md4ai_get_setting( 'accept_negotiation' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed into floats below, never output.
		$accept = (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' );
		if ( '' === $accept ) {
			return false;
		}

		$q = self::parse_accept( $accept );
		if ( null === $q || ! isset( $q['text/markdown'] ) || $q['text/markdown'] <= 0.0 ) {
			return false;
		}

		$html = $q['text/html'] ?? 0.0;
		return $q['text/markdown'] >= $html;
	}

	/**
	 * Parses an Accept header into explicit media type => q-value pairs.
	 *
	 * Wildcards are deliberately not expanded: only types the client named
	 * outright count. Any unparseable q value makes the whole header
	 * untrusted (null) — dropping just that one type could remove text/html
	 * from the comparison and flip the decision toward Markdown, the
	 * opposite of "never serve Markdown when unsure".
	 *
	 * @param string $accept Raw Accept header value.
	 * @return array<string, float>|null Null when the header is malformed.
	 */
	private static function parse_accept( string $accept ): ?array {
		$out = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$params = explode( ';', $part );
			$type   = strtolower( trim( (string) array_shift( $params ) ) );
			if ( '' === $type || false !== strpos( $type, '*' ) ) {
				continue;
			}

			$quality = 1.0;
			foreach ( $params as $param ) {
				$pair = explode( '=', $param, 2 );
				if ( 2 !== count( $pair ) || 'q' !== strtolower( trim( $pair[0] ) ) ) {
					continue;
				}
				if ( ! is_numeric( trim( $pair[1] ) ) ) {
					return null;
				}
				$quality = (float) trim( $pair[1] );
			}

			$out[ $type ] = $quality;
		}

		return $out;
	}

	/**
	 * Returns why the current request should receive Markdown, if it should.
	 *
	 * Precedence is deliberate: explicit URL forms first, then the Accept
	 * header, then UA sniffing — most explicit signal wins.
	 *
	 * @return string One of 'query', 'suffix', 'accept', 'ua', or '' for none.
	 */
	public static function trigger(): string {
		$detection_mode = md4ai_get_setting( 'detection_mode' );

		if ( 'ua' !== $detection_mode ) {
			$param_name = (string) md4ai_get_setting( 'query_param_name' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection, no state change.
			$param_value = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
			if ( 'md' === $param_value ) {
				return 'query';
			}
		}

		if ( self::md_suffix_requested() ) {
			return 'suffix';
		}

		if ( self::accepts_markdown() ) {
			return 'accept';
		}

		if ( 'query_param' === $detection_mode ) {
			return '';
		}

		return self::is_bot_request() ? 'ua' : '';
	}

	/**
	 * Returns the bot identifier to log for the current request.
	 *
	 * A known crawler is identified by its User-Agent whichever URL form it
	 * used: GPTBot following the advertised ".md" link is still GPTBot, not
	 * manual traffic. Only an unrecognised UA on an explicit URL is "manual".
	 *
	 * @return string Bot UA substring, "manual" for an explicit request, or "".
	 */
	public static function bot_name(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UA used only for string matching, never in output.
		$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' !== $ua ) {
			foreach ( self::get_bot_list() as $bot ) {
				if ( false !== stripos( $ua, (string) $bot ) ) {
					return (string) $bot;
				}
			}
		}

		$trigger = self::trigger();

		return ( 'query' === $trigger || 'suffix' === $trigger ) ? 'manual' : '';
	}

	/**
	 * Determines whether the current request should receive Markdown.
	 *
	 * @return bool
	 */
	public static function should_serve(): bool {
		return '' !== self::trigger();
	}

	/**
	 * Returns the built-in default bot UA substrings, unfiltered.
	 *
	 * @return string[]
	 */
	public static function default_bot_list(): array {
		return self::$default_bot_list;
	}

	/**
	 * Returns the effective bot UA substring list: saved setting if non-empty,
	 * otherwise the defaults, with the md4ai_bot_list filter applied.
	 *
	 * @return string[]
	 */
	public static function get_bot_list(): array {
		$saved_list = md4ai_get_setting( 'bot_list' );
		if ( is_string( $saved_list ) && '' !== trim( $saved_list ) ) {
			$bots = array_filter(
				array_map( 'trim', explode( "\n", $saved_list ) ),
				static function ( $line ) {
					return '' !== $line;
				}
			);
		} else {
			$bots = self::$default_bot_list;
		}

		return array_values( apply_filters( 'md4ai_bot_list', $bots ) );
	}

	/**
	 * Returns the configured blocked-bot UA substring list. Unlike the bot
	 * list, this has no built-in defaults: an empty list blocks nothing.
	 *
	 * @return string[]
	 */
	public static function get_blocked_bot_list(): array {
		$saved = md4ai_get_setting( 'blocked_bots' );
		if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
			return array();
		}
		$bots = array_filter(
			array_map( 'trim', explode( "\n", $saved ) ),
			static function ( $line ) {
				return '' !== $line;
			}
		);

		return array_values( apply_filters( 'md4ai_blocked_bots', $bots ) );
	}

	/**
	 * Determines whether the current request's User-Agent is on the blocked list.
	 *
	 * @return bool
	 */
	public static function is_blocked_request(): bool {
		$blocked = self::get_blocked_bot_list();
		if ( empty( $blocked ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UA used only for string matching, never in output
		$ua = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $ua ) {
			return false;
		}

		foreach ( $blocked as $bot ) {
			if ( false !== stripos( $ua, (string) $bot ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the matched heuristic token if the UA looks bot-like but is not on the configured list.
	 *
	 * @return string Empty string if no heuristic match.
	 */
	public static function unknown_ua_match(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ua = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $ua ) {
			return '';
		}

		if ( self::is_bot_request() ) {
			return '';
		}

		$tokens = apply_filters(
			'md4ai_unknown_ua_tokens',
			array( 'bot', 'crawler', 'spider', 'gpt', 'claude', 'anthropic', 'fetch', 'ai/' )
		);
		foreach ( $tokens as $token ) {
			if ( false !== stripos( $ua, (string) $token ) ) {
				return (string) $token;
			}
		}
		return '';
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
		$excluded = (array) apply_filters( 'md4ai_excluded_post_ids', array() );
		if ( in_array( (int) $post->ID, array_map( 'absint', $excluded ), true ) ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		if ( self::is_noindex( $post->ID ) ) {
			return false;
		}
		if ( self::is_excluded( $post->ID ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the owner ticked "Exclude from AI" on this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::EXCLUDE_META_KEY, true );
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

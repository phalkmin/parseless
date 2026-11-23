<?php
/**
 * Response headers and Markdown discovery.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns everything ParseLess puts in a response head, plus ".md" URL routing.
 */
class MD4AI_Headers {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_strip_md_suffix' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'send_vary_on_html' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'send_link_header' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'render_link_tag' ), 5 );
	}

	/**
	 * Sends every header a Markdown response needs.
	 *
	 * @param string $markdown The response body, for the token estimate.
	 */
	public static function send_markdown( string $markdown ): void {
		$trigger = MD4AI_Detector::trigger();

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: accept', false );
		header( 'X-Markdown-Tokens: ' . self::estimate_tokens( $markdown ) );

		$robots = self::robots_value( $trigger );
		if ( '' !== $robots ) {
			header( 'X-Robots-Tag: ' . $robots );
		}

		// A shared cache would replay canonical-URL Markdown to browsers, or a 200 past the block/rate-limit check.
		$duplicate_url = 'query' === $trigger || 'suffix' === $trigger;
		if ( ! $duplicate_url || ! empty( MD4AI_Detector::get_blocked_bot_list() ) || md4ai_get_setting( 'rate_limit_enabled' ) ) {
			header( 'Cache-Control: private' );
		}
	}

	/**
	 * Sends a 403 for a blocked crawler and ends the request.
	 *
	 * @param int $post_id Post the request targeted, 0 for endpoint fetches.
	 */
	public static function send_forbidden( int $post_id = 0 ): void {
		MD4AI_Logger::record(
			array(
				'post_id'      => $post_id,
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => MD4AI_Detector::bot_name(),
				'bytes_served' => 0,
				'cache_hit'    => false,
				'served'       => MD4AI_Logger::SERVED_BLOCKED,
			)
		);

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: private' );
		echo "403 Forbidden\n";
		exit;
	}

	/**
	 * Sends a 429 for a throttled crawler and ends the request.
	 *
	 * @param int $post_id Post the request targeted, 0 for endpoint fetches.
	 */
	public static function send_too_many_requests( int $post_id = 0 ): void {
		MD4AI_Logger::record(
			array(
				'post_id'      => $post_id,
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => MD4AI_Detector::bot_name(),
				'bytes_served' => 0,
				'cache_hit'    => false,
				'served'       => MD4AI_Logger::SERVED_THROTTLED,
			)
		);

		status_header( 429 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Retry-After: ' . MD4AI_Rate_Limiter::retry_after() );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: private' );
		echo "429 Too Many Requests\n";
		exit;
	}

	/**
	 * Ends the request with a 403 or 429 when the crawler is blocked or throttled.
	 *
	 * @param int $post_id Post the request targeted, 0 for endpoint fetches.
	 */
	public static function enforce_access( int $post_id = 0 ): void {
		if ( MD4AI_Detector::is_blocked_request() ) {
			self::send_forbidden( $post_id );
		}
		if ( MD4AI_Rate_Limiter::is_throttled() ) {
			self::send_too_many_requests( $post_id );
		}
	}

	/**
	 * Returns the X-Robots-Tag value for a Markdown response, or "" to omit it.
	 *
	 * @param string $trigger Value from MD4AI_Detector::trigger().
	 * @return string
	 */
	public static function robots_value( string $trigger ): string {
		if ( 'query' === $trigger || 'suffix' === $trigger ) {
			return 'noindex';
		}
		return '';
	}

	/**
	 * Estimates the token count of a Markdown body at four bytes per token.
	 *
	 * @param string $markdown Response body.
	 * @return int
	 */
	public static function estimate_tokens( string $markdown ): int {
		return (int) ceil( strlen( $markdown ) / 4 );
	}

	/**
	 * Sends Vary: accept on the HTML branch.
	 */
	public static function send_vary_on_html(): void {
		if ( headers_sent() ) {
			return;
		}
		if ( ! md4ai_get_setting( 'accept_negotiation' ) ) {
			return;
		}
		$post_types = md4ai_enabled_post_types();
		if ( empty( $post_types ) || ! is_singular( $post_types ) ) {
			return;
		}
		// Not User-Agent: it splinters Varnish/Fastly page caches, and UA-served Markdown is already private.
		header( 'Vary: accept', false );
	}

	/**
	 * Returns the Markdown URL for the queried post, or "" when it is not servable.
	 *
	 * @return string
	 */
	public static function markdown_url_for_queried_post(): string {
		$post_types = md4ai_enabled_post_types();
		if ( empty( $post_types ) || ! is_singular( $post_types ) ) {
			return '';
		}

		$post = get_post( get_queried_object_id() );
		if ( ! $post instanceof WP_Post || ! MD4AI_Detector::is_post_servable( $post ) ) {
			return '';
		}

		return self::markdown_url_for( $post );
	}

	/**
	 * Returns a working Markdown URL for a post, or "" when none exists.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function markdown_url_for( WP_Post $post ): string {
		if ( 'page' === $post->post_type && (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
			return '';
		}

		$permalink = (string) get_permalink( $post );
		if ( '' === $permalink ) {
			return '';
		}

		$is_plain  = '' !== (string) wp_parse_url( $permalink, PHP_URL_QUERY );
		$path      = trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( md4ai_get_setting( 'md_suffix_enabled' ) && ! $is_plain && $path !== $home_path ) {
			return rtrim( $permalink, '/' ) . '.md';
		}

		if ( 'ua' !== md4ai_get_setting( 'detection_mode' ) ) {
			return add_query_arg( (string) md4ai_get_setting( 'query_param_name' ), 'md', $permalink );
		}

		return '';
	}

	/**
	 * Advertises the Markdown alternate and RSL licence in a Link header.
	 */
	public static function send_link_header(): void {
		if ( headers_sent() ) {
			return;
		}

		$links = array();

		if ( md4ai_get_setting( 'link_header_enabled' ) ) {
			$md_url = self::markdown_url_for_queried_post();
			if ( '' !== $md_url ) {
				$links[] = '<' . esc_url_raw( $md_url ) . '>; rel="alternate"; type="text/markdown"';
			}
		}

		if ( md4ai_get_setting( 'rsl_enabled' ) ) {
			$links[] = '<' . esc_url_raw( MD4AI_RSL::license_url() ) . '>; rel="license"; type="application/rsl+xml"';
		}

		if ( empty( $links ) ) {
			return;
		}

		header( 'Link: ' . implode( ', ', $links ), false );
	}

	/**
	 * Prints <link rel="alternate" type="text/markdown"> in the document head.
	 */
	public static function render_link_tag(): void {
		if ( ! md4ai_get_setting( 'html_link_tag_enabled' ) ) {
			return;
		}

		$md_url = self::markdown_url_for_queried_post();
		if ( '' === $md_url ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s">' . "\n",
			esc_url( $md_url )
		);
	}

	/**
	 * Strips a trailing ".md" from the request path and flags a Markdown request.
	 */
	public static function maybe_strip_md_suffix(): void {
		if ( ! md4ai_get_setting( 'md_suffix_enabled' ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizing would corrupt percent-encoding; never output.
		$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '.md' !== substr( $path, -3 ) ) {
			return;
		}

		$stripped = substr( $path, 0, -3 );
		if ( '' === trim( $stripped, '/' ) ) {
			return;
		}
		if ( self::reserved_path( md4ai_request_path() ) ) {
			return;
		}

		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = $stripped . ( '' !== $query ? '?' . $query : '' );
		MD4AI_Detector::flag_md_suffix();
	}

	/**
	 * Whether a ".md" path belongs to the live OKF bundle.
	 *
	 * @param string $path Request path relative to home, slashes trimmed.
	 * @return bool
	 */
	private static function reserved_path( string $path ): bool {
		if ( ! md4ai_get_setting( 'okf_enabled' ) ) {
			return false;
		}

		$endpoint = MD4AI_OKF::endpoint();
		$bare     = substr( $path, 0, -3 );

		return $endpoint === $bare || str_starts_with( $path, $endpoint . '/' );
	}
}

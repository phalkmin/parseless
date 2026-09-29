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

		// Anything that isn't a distinct duplicate URL (?format=md, .md) is
		// Markdown on the canonical URL — accept, ua, or a serve forced via
		// the md4ai_should_serve_markdown filter — and a shared cache keyed
		// on URL alone would hand it to browsers. The duplicate URLs vary by
		// User-Agent too once any crawler is blocked: a stored 200 would be
		// replayed to a crawler the 403 check never got to see.
		$duplicate_url = 'query' === $trigger || 'suffix' === $trigger;
		if ( ! $duplicate_url || ! empty( MD4AI_Detector::get_blocked_bot_list() ) ) {
			header( 'Cache-Control: private' );
		}
	}

	/**
	 * Sends a 403 for a blocked crawler and ends the request.
	 */
	public static function send_forbidden(): void {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		// The block is per-User-Agent: a shared cache keyed on URL alone would
		// serve this 403 to every legitimate agent that follows.
		header( 'Cache-Control: private' );
		echo "403 Forbidden\n";
		exit;
	}

	/**
	 * Returns the X-Robots-Tag value for a Markdown response, or "" to omit it.
	 *
	 * The noindex tag belongs only on the URL forms that duplicate a canonical
	 * page. Accept- and UA-triggered responses ARE the canonical URL, so
	 * telling crawlers not to index them would suppress the content we
	 * optimised.
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
	 * Estimates the token count of a Markdown body.
	 *
	 * Four bytes per token, the same rough heuristic Cloudflare uses. Documented
	 * as an estimate wherever it is surfaced.
	 *
	 * @param string $markdown Response body.
	 * @return int
	 */
	public static function estimate_tokens( string $markdown ): int {
		return (int) ceil( strlen( $markdown ) / 4 );
	}

	/**
	 * Sends Vary: accept on the HTML branch.
	 *
	 * Runs at template_redirect priority 2, after MD4AI::maybe_serve_markdown()
	 * has either exited or declined. Without this, a shared cache that stored
	 * an HTML response would serve it to agents asking for Markdown, and vice
	 * versa.
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
		header( 'Vary: accept', false );
	}

	/**
	 * Returns the Markdown URL for the currently queried post, or "" when the
	 * request is not a servable singular view.
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
	 * Returns a Markdown URL for a post that is guaranteed to actually serve
	 * Markdown under the current settings, or "" when no such URL form exists.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function markdown_url_for( WP_Post $post ): string {
		// The posts page routes to the blog archive (is_home), never to a
		// singular view, so no URL form of it can serve Markdown.
		if ( 'page' === $post->post_type && (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
			return '';
		}

		$permalink = (string) get_permalink( $post );
		if ( '' === $permalink ) {
			return '';
		}

		// A plain permalink is already a query string ("/?p=123"), where
		// appending ".md" would corrupt it, and the site-root permalink of a
		// static front page would become "example.com.md" — a different
		// domain. Both fall through to the query-parameter form.
		$is_plain  = '' !== (string) wp_parse_url( $permalink, PHP_URL_QUERY );
		$path      = trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( md4ai_get_setting( 'md_suffix_enabled' ) && ! $is_plain && $path !== $home_path ) {
			return rtrim( $permalink, '/' ) . '.md';
		}

		// In UA-only detection mode the query trigger is off, so advertising
		// "?format=md" would be a link that serves HTML.
		if ( 'ua' !== md4ai_get_setting( 'detection_mode' ) ) {
			return add_query_arg( (string) md4ai_get_setting( 'query_param_name' ), 'md', $permalink );
		}

		return '';
	}

	/**
	 * Advertises the Markdown alternate — and the RSL licence, when published —
	 * in a Link header.
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
	 * Strips a trailing ".md" from the request path so WordPress routes the
	 * clean permalink, and flags the request as a Markdown one.
	 *
	 * Runs at priority 0 — ahead of the llms.txt, sitemap, and OKF handlers,
	 * which read REQUEST_URI directly and would not recognise a stripped path.
	 */
	public static function maybe_strip_md_suffix(): void {
		if ( ! md4ai_get_setting( 'md_suffix_enabled' ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the value written back is a strict substring of the existing superglobal (minus the ".md" suffix); sanitize_text_field() would strip percent-encoding and corrupt the path, and this value is never output.
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
	 * Whether a ".md" path belongs to the OKF bundle, which matches on the
	 * unstripped path and must be left alone. Only the live bundle is
	 * reserved: with OKF off, a real page slugged like the endpoint keeps its
	 * working ".md" URL.
	 *
	 * @param string $path Request path relative to home, slashes trimmed.
	 * @return bool
	 */
	private static function reserved_path( string $path ): bool {
		if ( ! md4ai_get_setting( 'okf_enabled' ) ) {
			return false;
		}

		// Callers only reach here when $path ends in ".md"; $bare is what
		// OKF's own path-matching would see once the suffix is stripped and
		// the request re-routed.
		$endpoint = MD4AI_OKF::endpoint();
		$bare     = substr( $path, 0, -3 );

		return $endpoint === $bare || str_starts_with( $path, $endpoint . '/' );
	}
}

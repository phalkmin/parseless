<?php
/**
 * Open Knowledge Format (OKF) bundle endpoint.
 *
 * Serves an OKF v0.1 bundle of the site's content for AI agents: an index.md
 * entry point, a log.md changelog, and one Markdown concept file per post,
 * cross-linked to each other. Detected off the request path like llms.txt
 * (no rewrite rules), with a ?format=okf query fallback for sites without
 * pretty permalinks.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the /okf/ bundle.
 */
class MD4AI_OKF {

	private const INDEX_TRANSIENT = 'md4ai_okf_index';
	private const LOG_TRANSIENT   = 'md4ai_okf_log';
	private const CONCEPT_PREFIX  = 'md4ai_okf_c_';
	private const OKF_VERSION     = '0.1';
	private const DEFAULT_PATH    = 'okf';

	/**
	 * Returns the configured bundle base path (the segment before /index.md),
	 * sanitised and defaulted. Filterable via md4ai_okf_endpoint.
	 *
	 * @return string
	 */
	private static function endpoint(): string {
		$endpoint = sanitize_title( (string) md4ai_get_setting( 'okf_endpoint' ) );
		if ( '' === $endpoint ) {
			$endpoint = self::DEFAULT_PATH;
		}
		return (string) apply_filters( 'md4ai_okf_endpoint', $endpoint );
	}

	/**
	 * Serves an OKF bundle file when one is requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'okf_enabled' ) ) {
			return;
		}

		$file = self::requested_file();
		if ( null === $file ) {
			return;
		}

		if ( 'index.md' === $file ) {
			self::serve_cached( self::INDEX_TRANSIENT, array( __CLASS__, 'build_index' ), 0 );
		} elseif ( 'log.md' === $file ) {
			self::serve_cached( self::LOG_TRANSIENT, array( __CLASS__, 'build_log' ), 0 );
		} else {
			self::serve_concept( $file );
		}
	}

	/**
	 * Resolves which bundle file the current request is asking for.
	 *
	 * Recognises the path forms /<endpoint>/, /<endpoint>/index.md,
	 * /<endpoint>/log.md and /<endpoint>/<slug>.md, plus the ?format=okf
	 * (optionally &okf=<file>) fallback.
	 *
	 * @return string|null Bundle filename, or null when this is not an OKF request.
	 */
	private static function requested_file(): ?string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$endpoint    = self::endpoint();

		if ( $endpoint === $path ) {
			return 'index.md';
		}

		if ( 0 === strpos( $path, $endpoint . '/' ) ) {
			$file = basename( substr( $path, strlen( $endpoint ) + 1 ) );
			return '' === $file ? 'index.md' : $file;
		}

		// Query fallback: ?<param>=okf, with an optional &okf=<file>.
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint, no state change.
		$param = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
		if ( 'okf' === $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint, no state change.
			$file = isset( $_GET['okf'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['okf'] ) ) ) : '';
			return '' === $file ? 'index.md' : $file;
		}

		return null;
	}

	/**
	 * Serves a cached bundle document, building it on cache miss.
	 *
	 * @param string   $key      Transient key.
	 * @param callable $builder  Builds the document on miss.
	 * @param int      $post_id  Post ID to attribute the fetch to (0 for index/log).
	 */
	private static function serve_cached( string $key, callable $builder, int $post_id ): void {
		$cache_hit = true;
		$cached    = get_transient( $key );
		if ( false === $cached ) {
			$cached    = $builder();
			$cache_hit = false;
			set_transient( $key, $cached, self::ttl() );
		}
		self::output( $cached, $post_id, $cache_hit );
	}

	/**
	 * Serves a single concept file, or 404 if its slug is not in the bundle.
	 *
	 * @param string $file Requested filename, e.g. "my-post.md".
	 */
	private static function serve_concept( string $file ): void {
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.md$/', $file ) ) {
			self::not_found( $file );
		}

		$slug    = substr( $file, 0, -3 );
		$map     = self::slug_map();
		$post_id = $map['by_slug'][ $slug ] ?? 0;
		if ( ! $post_id ) {
			self::not_found( $file );
		}

		$cache_hit = true;
		$cache_key = self::CONCEPT_PREFIX . $post_id;
		$cached    = get_transient( $cache_key );
		if ( false === $cached ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				self::not_found( $file );
			}
			$cached    = self::build_concept( $post, $map );
			$cache_hit = false;
			set_transient( $cache_key, $cached, self::ttl() );
		}

		self::output( $cached, $post_id, $cache_hit );
	}

	/**
	 * Emits a Markdown response and logs the fetch.
	 *
	 * @param string $body      Response body.
	 * @param int    $post_id   Post ID to attribute the fetch to.
	 * @param bool   $cache_hit Whether the body came from cache.
	 */
	private static function output( string $body, int $post_id, bool $cache_hit ): void {
		MD4AI_Logger::record(
			array(
				'post_id'      => $post_id,
				'url'          => self::current_url(),
				'user_agent'   => self::current_ua(),
				'bot_name'     => self::resolve_bot_name(),
				'bytes_served' => strlen( $body ),
				'cache_hit'    => $cache_hit,
			)
		);

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: all', true );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text Markdown response, not HTML; entity-encoding would corrupt the output.
		echo $body;
		exit;
	}

	/**
	 * Sends a 404 for an unknown bundle file.
	 *
	 * @param string $file Requested filename.
	 */
	private static function not_found( string $file ): void {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo 'Not found in OKF bundle: ' . esc_html( $file ) . "\n";
		exit;
	}

	/**
	 * Builds the bundle root index.md: every concept, alphabetised by slug.
	 *
	 * @return string
	 */
	public static function build_index(): string {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$desc = get_bloginfo( 'description' );

		$lines   = array( '---', 'okf_version: "' . self::OKF_VERSION . '"', '---', '' );
		$name    = get_bloginfo( 'name' );
		$lines[] = '# ' . ( '' !== $name ? $name : $site );
		$lines[] = '';
		$lines[] = '' !== $desc ? $desc : ( 'An OKF v' . self::OKF_VERSION . ' bundle generated from ' . $site . '.' );
		$lines[] = '';
		$lines[] = '# Concepts';
		$lines[] = '';

		$map      = self::slug_map();
		$concepts = array();
		foreach ( $map['by_slug'] as $slug => $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$concepts[ $slug ] = array(
				'title' => get_the_title( $post ),
				'desc'  => self::description( $post ),
			);
		}
		ksort( $concepts );

		foreach ( $concepts as $slug => $c ) {
			$line = '* [' . $c['title'] . '](' . $slug . '.md)';
			if ( '' !== $c['desc'] ) {
				$line .= ' - ' . $c['desc'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Builds log.md: concepts grouped by modified date, newest first.
	 *
	 * @return string
	 */
	public static function build_log(): string {
		$map     = self::slug_map();
		$by_date = array();
		foreach ( $map['by_slug'] as $slug => $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$date = substr( (string) get_post_modified_time( 'c', true, $post ), 0, 10 );
			if ( '' === $date ) {
				continue;
			}
			$by_date[ $date ][] = array(
				'slug'  => $slug,
				'title' => get_the_title( $post ),
			);
		}

		if ( empty( $by_date ) ) {
			return "# Update Log\n";
		}

		krsort( $by_date );
		$lines = array( '# Update Log', '' );
		foreach ( $by_date as $date => $items ) {
			$lines[] = '## ' . $date;
			foreach ( $items as $item ) {
				$lines[] = '* **Update**: [' . $item['title'] . '](' . $item['slug'] . '.md)';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Builds a single concept file: OKF frontmatter plus Markdown body with
	 * internal links rewritten to their concept filenames.
	 *
	 * @param WP_Post              $post Post object.
	 * @param array<string, array> $map  Slug map from {@see self::slug_map()}.
	 * @return string
	 */
	private static function build_concept( WP_Post $post, array $map ): string {
		$title = get_the_title( $post );
		$type  = 'page' === $post->post_type ? 'Page' : 'Article';
		$desc  = self::description( $post );
		$url   = get_permalink( $post );
		$ts    = get_post_modified_time( 'c', true, $post );
		$tags  = self::tags( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally applying WordPress core's the_content filter.
		$html = apply_filters( 'the_content', $post->post_content );
		$body = MD4AI_Converter::convert( $html );
		$body = self::rewrite_links( $body, (int) $post->ID, $map['by_postid'] );

		$fm      = self::frontmatter( $type, $title, $desc, (string) $url, $tags, (string) $ts );
		$heading = preg_match( '/^\s*#{1,6}\s/', $body ) ? '' : ( '# ' . $title . "\n\n" );

		$md = $fm . "\n\n" . $heading . $body . "\n";

		return apply_filters( 'md4ai_okf_concept', $md, $post );
	}

	/**
	 * Assembles a concept's YAML frontmatter block.
	 *
	 * @param string   $type  OKF concept type.
	 * @param string   $title Post title.
	 * @param string   $desc  Description (may be empty).
	 * @param string   $url   Canonical permalink.
	 * @param string[] $tags  Tag/category names.
	 * @param string   $ts    ISO 8601 modified timestamp (may be empty).
	 * @return string
	 */
	private static function frontmatter( string $type, string $title, string $desc, string $url, array $tags, string $ts ): string {
		$lines = array( '---', 'type: ' . self::yaml( $type ), 'title: ' . self::yaml( $title ) );
		if ( '' !== $desc ) {
			$lines[] = 'description: ' . self::yaml( $desc );
		}
		$lines[] = 'resource: ' . self::yaml( $url );
		if ( ! empty( $tags ) ) {
			$lines[] = 'tags: [' . implode( ', ', array_map( array( __CLASS__, 'yaml' ), $tags ) ) . ']';
		}
		if ( '' !== $ts ) {
			$lines[] = 'timestamp: ' . $ts;
		}
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * Double-quotes and escapes a string for YAML.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function yaml( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Rewrites Markdown links that point at other bundle concepts to their
	 * "<slug>.md" filenames, so an agent can follow them within the bundle.
	 *
	 * @param string            $body         Concept Markdown body.
	 * @param int               $source_id    The post this body belongs to.
	 * @param array<int,string> $slug_by_id Post ID => concept slug map.
	 * @return string
	 */
	private static function rewrite_links( string $body, int $source_id, array $slug_by_id ): string {
		return (string) preg_replace_callback(
			'/\]\((https?:\/\/[^)\s]+|\/[^)\s]+)\)/',
			static function ( $m ) use ( $source_id, $slug_by_id ) {
				$target_id = url_to_postid( $m[1] );
				if ( $target_id && $target_id !== $source_id && isset( $slug_by_id[ $target_id ] ) ) {
					return '](' . $slug_by_id[ $target_id ] . '.md)';
				}
				return $m[0];
			},
			$body
		);
	}

	/**
	 * Builds the bundle's post set and the slug<->ID maps, reserving the
	 * index/log filenames and de-duplicating slugs.
	 *
	 * @return array{by_slug: array<string,int>, by_postid: array<int,string>}
	 */
	private static function slug_map(): array {
		$post_types = apply_filters( 'md4ai_supported_post_types', md4ai_supported_post_types() );
		$max_posts  = (int) apply_filters( 'md4ai_okf_max_posts', md4ai_get_setting( 'okf_max_posts' ) );
		$posts      = get_posts(
			array(
				'numberposts' => $max_posts,
				'post_status' => 'publish',
				'post_type'   => $post_types,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$used      = array(
			'index' => true,
			'log'   => true,
		);
		$by_slug   = array();
		$by_postid = array();
		foreach ( $posts as $post ) {
			if ( ! MD4AI_Detector::is_post_servable( $post ) ) {
				continue;
			}
			$slug = self::safe_slug( $post->post_name ? $post->post_name : ( 'post-' . $post->ID ), $used );

			$used[ $slug ]          = true;
			$by_slug[ $slug ]       = (int) $post->ID;
			$by_postid[ $post->ID ] = $slug;
		}

		return array(
			'by_slug'   => $by_slug,
			'by_postid' => $by_postid,
		);
	}

	/**
	 * Returns a unique, filesystem-safe concept slug.
	 *
	 * @param string              $raw  Raw slug source.
	 * @param array<string, bool> $used Slugs already taken.
	 * @return string
	 */
	private static function safe_slug( string $raw, array $used ): string {
		$slug = sanitize_title( $raw );
		if ( '' === $slug ) {
			$slug = 'page';
		}
		$base = $slug;
		$n    = 2;
		while ( isset( $used[ $slug ] ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}
		return $slug;
	}

	/**
	 * Resolves a concept description: Rank Math, then Yoast, then the excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function description( WP_Post $post ): string {
		$desc = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		if ( '' === $desc ) {
			$desc = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		}
		if ( '' === $desc ) {
			$desc = get_the_excerpt( $post );
		}
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $desc ) ) );
	}

	/**
	 * Returns a post's tags plus categories, deduped and capped at 12.
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private static function tags( WP_Post $post ): array {
		$names = array();
		foreach ( (array) get_the_tags( $post->ID ) as $term ) {
			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}
		foreach ( (array) get_the_category( $post->ID ) as $cat ) {
			if ( $cat instanceof WP_Term && 'Uncategorized' !== $cat->name ) {
				$names[] = $cat->name;
			}
		}
		$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
		return array_slice( $names, 0, 12 );
	}

	/**
	 * Resolves the bot identifier for the current request.
	 *
	 * @return string Bot UA substring, "manual", or "" if unknown.
	 */
	private static function resolve_bot_name(): string {
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint.
		$param = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
		if ( 'okf' === $param ) {
			return 'manual';
		}
		$ua = self::current_ua();
		if ( '' === $ua ) {
			return '';
		}
		foreach ( MD4AI_Detector::get_bot_list() as $bot ) {
			if ( false !== stripos( $ua, (string) $bot ) ) {
				return (string) $bot;
			}
		}
		return '';
	}

	/**
	 * Returns the current request URL.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * Returns the current request User-Agent string.
	 *
	 * @return string
	 */
	private static function current_ua(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	}

	/**
	 * Returns the cache TTL for bundle documents.
	 *
	 * @return int
	 */
	private static function ttl(): int {
		return (int) apply_filters( 'md4ai_cache_ttl', (int) md4ai_get_setting( 'cache_ttl' ) );
	}

	/**
	 * Returns the public URL of the bundle entry point (the index), reflecting
	 * the configured endpoint. Used by the settings page and llms.txt.
	 *
	 * @return string
	 */
	public static function bundle_url(): string {
		return home_url( '/' . self::endpoint() . '/' );
	}

	/**
	 * Clears the cached index.md and log.md. Concept files expire by TTL and
	 * are cleared individually via {@see self::clear_post()}.
	 */
	public static function clear_cache(): void {
		delete_transient( self::INDEX_TRANSIENT );
		delete_transient( self::LOG_TRANSIENT );
	}

	/**
	 * Clears the cached concept file for a single post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function clear_post( int $post_id ): void {
		delete_transient( self::CONCEPT_PREFIX . $post_id );
	}
}

<?php
/**
 * Open Knowledge Format (OKF) bundle endpoint.
 *
 * Serves an OKF bundle of the site's content for AI agents: an index.md
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
	private const MAP_TRANSIENT   = 'md4ai_okf_map';
	private const CONCEPT_PREFIX  = 'md4ai_okf_c_';
	private const OKF_VERSION     = '0.2';
	private const DEFAULT_PATH    = 'okf';

	/**
	 * Returns the configured bundle base path (the segment before /index.md),
	 * sanitised and defaulted. Filterable via md4ai_okf_endpoint.
	 *
	 * Public because MD4AI_Headers::reserved_path() needs the same answer to
	 * guard the bundle from the ".md" suffix stripper.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
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

		// The bundle serves the same full content the Markdown endpoint 403s
		// blocked crawlers from, so the block list and rate limit apply too.
		MD4AI_Headers::enforce_access();

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
	 * (optionally &okf=<file>) fallback at the site root.
	 *
	 * @return string|null Bundle filename, or null when this is not an OKF request.
	 */
	private static function requested_file(): ?string {
		$path     = md4ai_request_path();
		$endpoint = self::endpoint();

		if ( $endpoint === $path ) {
			return 'index.md';
		}

		if ( str_starts_with( $path, $endpoint . '/' ) ) {
			$file = substr( $path, strlen( $endpoint ) + 1 );
			if ( '' === $file ) {
				return 'index.md';
			}
			// Nested paths are not bundle files; letting WordPress 404 them
			// stops every concept being reachable at unlimited alias URLs.
			if ( str_contains( $file, '/' ) ) {
				return null;
			}
			return $file;
		}

		// Query fallback for plain-permalink sites, root only — ?format=okf on
		// an arbitrary URL must not hijack that page.
		if ( '' !== $path && 'index.php' !== $path ) {
			return null;
		}
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint, no state change.
		$param = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
		if ( 'okf' === $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only public endpoint; reduced to a strict filename charset below because sanitize_file_name() strips the "%" octets that percent-encoded (non-ASCII) slugs contain.
			$raw = isset( $_GET['okf'] ) ? (string) wp_unslash( $_GET['okf'] ) : '';
			// Same charset serve_concept() enforces; anything else 404s there.
			$file = (string) preg_replace( '/[^A-Za-z0-9%._-]/', '', $raw );
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
			if ( ! MD4AI_Cache::bypass() ) {
				set_transient( $key, $cached, MD4AI_Cache::ttl() );
			}
		}
		self::output( $cached, $post_id, $cache_hit );
	}

	/**
	 * Serves a single concept file, or 404 if its slug is not in the bundle.
	 *
	 * @param string $file Requested filename, e.g. "my-post.md".
	 */
	private static function serve_concept( string $file ): void {
		// "%" is allowed because non-ASCII post slugs are stored percent-encoded.
		if ( ! preg_match( '/^[A-Za-z0-9%._-]+\.md$/', $file ) ) {
			self::not_found( $file );
		}

		$slug    = strtolower( substr( $file, 0, -3 ) );
		$map     = self::slug_map();
		$post_id = (int) ( $map['by_slug'][ $slug ] ?? 0 );
		$post    = $post_id > 0 ? self::servable_post( $post_id ) : null;
		if ( ! $post ) {
			self::not_found( $file );
		}

		$cache_hit = true;
		$cache_key = self::CONCEPT_PREFIX . $post_id;
		$cached    = get_transient( $cache_key );
		if ( false === $cached ) {
			$cached    = self::build_concept( $post, $map );
			$cache_hit = false;
			if ( ! MD4AI_Cache::bypass() ) {
				set_transient( $cache_key, $cached, MD4AI_Cache::ttl( $post_id ) );
			}
		}

		self::output( $cached, $post_id, $cache_hit );
	}

	/**
	 * Returns a post the slug map admitted, if it is still servable right now.
	 *
	 * The map and the concept caches can outlive a direct delete or a
	 * metadata-only noindex change that never reached save_post, so
	 * membership is rechecked on every fetch — cache hit or not — rather
	 * than trusted from the map.
	 *
	 * @param int $post_id Post ID from the slug map.
	 * @return WP_Post|null Null when the post is gone or no longer eligible.
	 */
	private static function servable_post( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( ! in_array( $post->post_type, md4ai_enabled_post_types(), true ) ) {
			return null;
		}

		return MD4AI_Detector::is_post_servable( $post ) ? $post : null;
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
				'url'          => md4ai_current_url(),
				'user_agent'   => md4ai_current_ua(),
				'bot_name'     => self::bot_name(),
				'bytes_served' => strlen( $body ),
				'cache_hit'    => $cache_hit,
			)
		);

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		// Bundle files duplicate canonical post URLs, so they get the same
		// noindex treatment as the ".md" and "?format=md" forms.
		header( 'X-Robots-Tag: noindex', true );
		// The Blocked Bots check makes every bundle URL vary by User-Agent, so
		// a shared cache keyed on URL alone must not store the response.
		header( 'Cache-Control: private' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text Markdown response, not HTML; entity-encoding would corrupt the output.
		echo $body;
		exit;
	}

	/**
	 * Returns the bot identifier to log for a bundle fetch: the crawler when
	 * the UA names one, otherwise "manual" for the ?format=okf fallback.
	 *
	 * @return string
	 */
	private static function bot_name(): string {
		$bot_name = MD4AI_Detector::bot_name();
		if ( '' === $bot_name && self::is_query_request() ) {
			return 'manual';
		}

		return $bot_name;
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
		$desc = md4ai_site_description();

		$lines   = array( '---', 'okf_version: "' . self::OKF_VERSION . '"', '---', '' );
		$name    = md4ai_site_name();
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
				'title' => md4ai_plain_text( get_the_title( $post ) ),
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
				'title' => md4ai_plain_text( get_the_title( $post ) ),
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
		$title = md4ai_plain_text( get_the_title( $post ) );
		$type  = MD4AI_WooCommerce::okf_type( $post, 'page' === $post->post_type ? 'Page' : 'Article' );
		$desc  = self::description( $post );
		$url   = get_permalink( $post );
		$ts    = get_post_modified_time( 'c', true, $post );
		$tags  = self::tags( $post );

		$body = MD4AI::render_body( $post );
		$body = self::rewrite_links( $body, (int) $post->ID, $map['by_postid'] );

		$schema = MD4AI_WooCommerce::okf_schema_section( $post );
		if ( '' !== $schema ) {
			$body = trim( $body . "\n\n" . $schema );
		}

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
		$lines = array( '---', 'type: ' . md4ai_yaml_quote( $type ), 'title: ' . md4ai_yaml_quote( $title ) );
		if ( '' !== $desc ) {
			$lines[] = 'description: ' . md4ai_yaml_quote( $desc );
		}
		$lines[] = 'resource: ' . md4ai_yaml_quote( $url );
		if ( ! empty( $tags ) ) {
			$lines[] = 'tags: [' . implode( ', ', array_map( 'md4ai_yaml_quote', $tags ) ) . ']';
		}
		$lines[] = 'generated:';
		$lines[] = '  by: ' . md4ai_yaml_quote( 'parseless/' . MD4AI_VERSION );
		if ( '' !== $ts ) {
			$lines[] = '  at: ' . $ts;
		}
		$lines[] = '---';

		return implode( "\n", $lines );
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
		// The map build renders every bundle post (has_body), so it must not
		// run per request: a cache miss here is the documented O(N) cold build.
		// A logged-in requester never stores it (has_body varies by session,
		// see MD4AI_Cache::bypass()), so they pay the build until the first
		// anonymous fetch — a crawler's, in practice — warms it.
		$cached = get_transient( self::MAP_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['by_slug'], $cached['by_postid'] ) ) {
			return $cached;
		}

		$post_types = md4ai_enabled_post_types();
		$max_posts  = (int) apply_filters( 'md4ai_okf_max_posts', md4ai_get_setting( 'okf_max_posts' ) );
		$posts      = empty( $post_types ) ? array() : get_posts(
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
			if ( ! self::has_body( $post ) ) {
				continue;
			}
			$slug = self::safe_slug( $post->post_name ? $post->post_name : ( 'post-' . $post->ID ), $used );

			$used[ $slug ]          = true;
			$by_slug[ $slug ]       = (int) $post->ID;
			$by_postid[ $post->ID ] = $slug;
		}

		$map = array(
			'by_slug'   => $by_slug,
			'by_postid' => $by_postid,
		);
		if ( ! MD4AI_Cache::bypass() ) {
			set_transient( self::MAP_TRANSIENT, $map, MD4AI_Cache::ttl() );
		}

		return $map;
	}

	/**
	 * Determines whether a post yields a concept with content in it.
	 *
	 * A concept file with frontmatter and no content wastes an agent's fetch
	 * and makes the bundle look broken, so such posts are left out. A product
	 * with no long description still gets its schema section — the price,
	 * stock and SKU an agent actually wants — so it counts, and the cheap
	 * check runs first to spare the render.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private static function has_body( WP_Post $post ): bool {
		return MD4AI_WooCommerce::has_details( $post ) || '' !== trim( MD4AI::render_body( $post ) );
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
		return md4ai_plain_text( $desc );
	}

	/**
	 * Returns a concept's tags, drawn from every public taxonomy registered
	 * for its post type, deduped and capped at 12.
	 *
	 * Reading post_tag and category directly would miss product categories,
	 * product attributes, and any custom post type's own taxonomies.
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private static function tags( WP_Post $post ): array {
		$names = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( 'Uncategorized' !== $term->name ) {
					$names[] = $term->name;
				}
			}
		}
		$names = array_values( array_unique( array_filter( array_map( 'md4ai_plain_text', $names ) ) ) );
		return array_slice( $names, 0, 12 );
	}

	/**
	 * Whether this bundle fetch came from the ?format=okf query fallback.
	 *
	 * @return bool
	 */
	private static function is_query_request(): bool {
		$param_name = (string) md4ai_get_setting( 'query_param_name' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint.
		$param = isset( $_GET[ $param_name ] ) ? sanitize_key( wp_unslash( $_GET[ $param_name ] ) ) : '';
		return 'okf' === $param;
	}

	/**
	 * Returns the OKF specification version this bundle targets.
	 *
	 * @return string
	 */
	public static function version(): string {
		return self::OKF_VERSION;
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
	 * Clears every cached bundle document: index, log, slug map, and all
	 * concept files.
	 *
	 * Concepts embed cross-links to other concepts' slugs, so a single post
	 * save (a slug can change) invalidates all of them. The prefix sweep is a
	 * LIKE delete over wp_options, which persistent object caches don't see —
	 * there, other posts' stale concepts simply expire by TTL, but the saved
	 * post's own concept (the one guaranteed-stale document) is also deleted
	 * by key so its edit shows up immediately on every cache backend.
	 *
	 * @param int $post_id Post whose save triggered the clear (0 when none).
	 */
	public static function clear_cache( int $post_id = 0 ): void {
		delete_transient( self::INDEX_TRANSIENT );
		delete_transient( self::LOG_TRANSIENT );
		delete_transient( self::MAP_TRANSIENT );

		if ( $post_id > 0 ) {
			delete_transient( self::CONCEPT_PREFIX . $post_id );
		}

		MD4AI_Cache::purge_prefix( self::CONCEPT_PREFIX );
	}

	/**
	 * Clears the whole bundle on every cache backend, concept files included.
	 *
	 * For settings saves, where the output of every concept changes at once
	 * and waiting out the TTL on a persistent object cache is not acceptable.
	 */
	public static function purge_all(): void {
		self::clear_cache();
		MD4AI_Cache::purge_object_cache( self::CONCEPT_PREFIX );
	}
}

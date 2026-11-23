<?php
/**
 * WordPress Abilities API registrations.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the Markdown output to MCP clients and AI agents via the Abilities API.
 */
class MD4AI_Abilities {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the three abilities.
	 */
	public static function register(): void {
		wp_register_ability(
			'parseless/get-markdown',
			array(
				'label'               => __( 'Get post as Markdown', 'parseless' ),
				'description'         => __( 'Returns the clean Markdown version of a published post or page, as ParseLess serves it to AI crawlers.', 'parseless' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID. Either post_id or url is required.', 'parseless' ),
						),
						'url'     => array(
							'type'        => 'string',
							'description' => __( 'Post URL on this site. Either post_id or url is required.', 'parseless' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'url'      => array( 'type' => 'string' ),
						'title'    => array( 'type' => 'string' ),
						'markdown' => array( 'type' => 'string' ),
						'tokens'   => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_markdown' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'parseless/list-markdown-urls',
			array(
				'label'               => __( 'List Markdown URLs', 'parseless' ),
				'description'         => __( 'Lists the posts available as Markdown, newest first, with their Markdown URLs.', 'parseless' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Maximum number of posts; capped by the llms.txt max-posts setting.', 'parseless' ),
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'      => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
							'markdown_url' => array( 'type' => 'string' ),
							'modified'     => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'list_markdown_urls' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'parseless/get-stats',
			array(
				'label'               => __( 'Get AI crawler stats', 'parseless' ),
				'description'         => __( 'Returns ParseLess request totals and the top AI crawlers over a window of days. Requires logging to be enabled.', 'parseless' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'days' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 365,
							'default' => 30,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'days'              => array( 'type' => 'integer' ),
						'logging_enabled'   => array( 'type' => 'boolean' ),
						'total_requests'    => array( 'type' => 'integer' ),
						'cache_hit_rate'    => array( 'type' => array( 'number', 'null' ) ),
						'chat_impressions'  => array( 'type' => 'integer' ),
						'distinct_crawlers' => array( 'type' => 'integer' ),
						'top_bots'          => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_stats' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Resolves a post by ID or URL and returns its Markdown.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_markdown( array $input ): array|WP_Error {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( 0 === $post_id && ! empty( $input['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( (string) $input['url'] ) );
		}
		if ( 0 === $post_id ) {
			return new WP_Error( 'md4ai_missing_post', __( 'Provide a post_id or a url.', 'parseless' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! MD4AI_Detector::is_post_servable( $post ) || ! in_array( $post->post_type, md4ai_enabled_post_types(), true ) ) {
			return new WP_Error( 'md4ai_not_servable', __( 'This post is not available as Markdown.', 'parseless' ) );
		}

		$markdown = MD4AI_Cache::get( $post->ID );
		if ( false === $markdown ) {
			$markdown = MD4AI::build_markdown_for_preview( $post );
			MD4AI_Cache::set( $post->ID, $markdown );
		}

		return array(
			'post_id'  => $post->ID,
			'url'      => get_permalink( $post ),
			'title'    => md4ai_plain_text( get_the_title( $post ) ),
			'markdown' => $markdown,
			'tokens'   => MD4AI_Headers::estimate_tokens( $markdown ),
		);
	}

	/**
	 * Lists servable posts with their Markdown URLs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_markdown_urls( array $input ): array {
		$cap   = (int) md4ai_get_setting( 'llmstxt_max_posts' );
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : $cap;
		$posts = array_slice( MD4AI_LLMsTxt::get_listed_posts(), 0, max( 1, min( $cap, $limit ) ) );

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'post_id'      => $post->ID,
				'title'        => md4ai_plain_text( get_the_title( $post ) ),
				'url'          => get_permalink( $post ),
				'markdown_url' => MD4AI_Headers::markdown_url_for( $post ),
				'modified'     => get_post_modified_time( 'c', true, $post ),
			);
		}
		return $out;
	}

	/**
	 * Returns aggregated crawler stats for administrators.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_stats( array $input ): array {
		$days    = isset( $input['days'] ) ? max( 1, min( 365, absint( $input['days'] ) ) ) : 30;
		$enabled = (bool) md4ai_get_setting( 'enable_logging' );
		if ( ! $enabled ) {
			return array(
				'days'              => $days,
				'logging_enabled'   => false,
				'total_requests'    => 0,
				'cache_hit_rate'    => null,
				'chat_impressions'  => 0,
				'distinct_crawlers' => 0,
				'top_bots'          => array(),
			);
		}

		$bots    = MD4AI_Stats::top_bots( $days, 500 );
		$summary = MD4AI_Bots::summarize( MD4AI_Stats::top_content_bots( $days, 500 ) );

		return array(
			'days'              => $days,
			'logging_enabled'   => true,
			'total_requests'    => MD4AI_Stats::total_requests( $days ),
			'cache_hit_rate'    => MD4AI_Stats::cache_hit_rate( $days ),
			'chat_impressions'  => $summary['chat_impressions'],
			'distinct_crawlers' => $summary['distinct_crawlers'],
			'top_bots'          => array_slice( $bots, 0, 10 ),
		);
	}
}

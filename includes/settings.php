<?php
/**
 * Settings helpers.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets default plugin settings.
 *
 * @return array<string, mixed>
 */
function md4ai_get_default_settings(): array {
	return array(
		'detection_mode'              => 'both',
		'query_param_name'            => 'format',
		'bot_list'                    => '',
		'blocked_bots'                => '',
		'cache_ttl'                   => DAY_IN_SECONDS,
		'enabled_post_types'          => array(),
		'llmstxt_enabled'             => true,
		'llmstxt_max_posts'           => 100,
		'sitemap_enabled'             => true,
		'okf_enabled'                 => false,
		'okf_endpoint'                => 'okf',
		'okf_max_posts'               => 500,
		'include_frontmatter'         => false,
		'include_schema'              => false,
		'enable_logging'              => false,
		'log_retention_days'          => 30,
		'log_unknown_uas'             => true,
		'accept_negotiation'          => true,
		'md_suffix_enabled'           => true,
		'link_header_enabled'         => true,
		'html_link_tag_enabled'       => true,
		'content_signal_search'       => 'omit',
		'content_signal_ai_input'     => 'omit',
		'content_signal_ai_train'     => 'omit',
		'content_signals_boilerplate' => false,
		'robots_ai_rules'             => false,
		'rsl_enabled'                 => false,
		'rsl_permits'                 => 'train-ai',
		'rsl_payment_type'            => 'attribution',
	);
}

/**
 * Gets saved plugin settings merged with defaults.
 *
 * @return array<string, mixed>
 */
function md4ai_get_settings(): array {
	$saved = get_option( 'md4ai_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), md4ai_get_default_settings() );
}

/**
 * Gets one plugin setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function md4ai_get_setting( string $key ): mixed {
	return md4ai_get_settings()[ $key ] ?? md4ai_get_default_settings()[ $key ] ?? null;
}

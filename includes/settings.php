<?php
/**
 * Settings helpers.
 *
 * @package WP_Botfood
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
		'detection_mode'      => 'both',
		'query_param_name'    => 'format',
		'bot_list'            => '',
		'cache_ttl'           => DAY_IN_SECONDS,
		'enabled_post_types'  => array(),
		'llmstxt_enabled'     => true,
		'llmstxt_max_posts'   => 100,
		'include_frontmatter' => false,
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

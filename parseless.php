<?php
/**
 * Plugin Name:       ParseLess
 * Plugin URI:        https://github.com/phalkmin/parseless
 * Description:       Serves WordPress content as Markdown to AI crawlers and on ?format=md requests. Also exposes /llms.txt.
 * Version:           0.9.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Paulo Halkmin
 * Author URI:        https://phalkmin.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       parseless
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MD4AI_VERSION', '0.9.0' );
define( 'MD4AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MD4AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MD4AI_PLUGIN_DIR . 'includes/settings.php';
require_once MD4AI_PLUGIN_DIR . 'includes/functions-text.php';
require_once MD4AI_PLUGIN_DIR . 'includes/functions-url.php';

spl_autoload_register(
	function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'MD4AI' ) ) {
			return;
		}
		$filename = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$dirs     = array(
			MD4AI_PLUGIN_DIR . 'includes/',
			MD4AI_PLUGIN_DIR . 'admin/',
		);
		foreach ( $dirs as $dir ) {
			if ( file_exists( $dir . $filename ) ) {
				require_once $dir . $filename;
				return;
			}
		}
	}
);

/**
 * Returns public post types eligible for markdown serving.
 *
 * Excludes attachments and post types page builders register as public but
 * which hold configuration rather than content — Elementor's "Default Kit"
 * being the canonical example.
 *
 * @return string[]
 */
function md4ai_supported_post_types(): array {
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );

	$excluded = apply_filters(
		'md4ai_excluded_post_types',
		array(
			'elementor_library',
			'e-landing-page',
			'elementor-hf',
			'et_pb_layout',
			'wpbakery',
		)
	);

	return array_values( array_diff( $types, (array) $excluded ) );
}

/**
 * Returns the post types Markdown output is enabled for: the supported set
 * intersected with the owner's Enabled Post Types choice, with the public
 * md4ai_supported_post_types filter applied.
 *
 * May return an empty array (e.g. every saved type has since disappeared);
 * callers must treat that as "serve nothing", never as "serve everything" —
 * is_singular( array() ) and get_posts( post_type => array() ) both silently
 * widen back out.
 *
 * @return string[]
 */
function md4ai_enabled_post_types(): array {
	$all     = md4ai_supported_post_types();
	$enabled = md4ai_get_setting( 'enabled_post_types' );
	$base    = ( ! empty( $enabled ) && is_array( $enabled ) ) ? array_intersect( $all, $enabled ) : $all;

	return (array) apply_filters( 'md4ai_supported_post_types', array_values( $base ) );
}

add_action( 'plugins_loaded', array( 'MD4AI', 'init' ) );
add_action( 'plugins_loaded', array( 'MD4AI_Sitemap', 'init' ) );
add_action( 'plugins_loaded', array( 'MD4AI_Robots', 'init' ) );
add_action( 'plugins_loaded', array( 'MD4AI_RSL', 'init' ) );
add_action( 'plugins_loaded', array( 'MD4AI_WooCommerce', 'init' ) );

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'MD4AI_Settings', 'init' ) );
	add_action( 'plugins_loaded', array( 'MD4AI_Metabox', 'init' ) );
	add_action( 'plugins_loaded', array( 'MD4AI_Dashboard_Widget', 'init' ) );
	add_action( 'plugins_loaded', array( 'MD4AI_Analytics', 'init' ) );
}

register_activation_hook(
	__FILE__,
	static function (): void {
		MD4AI_Logger::install();
		MD4AI_Retention::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		MD4AI_Retention::unschedule();
	}
);

add_action( 'plugins_loaded', array( 'MD4AI_Retention', 'init' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( get_option( 'md4ai_db_version' ) !== '1' ) {
			MD4AI_Logger::install();
		}
	}
);

add_action( 'plugins_loaded', array( 'MD4AI_Privacy', 'init' ) );

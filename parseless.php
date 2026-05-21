<?php
/**
 * Plugin Name:       ParseLess
 * Plugin URI:        https://github.com/phalkmin/parseless
 * Description:       Serves WordPress content as Markdown to AI crawlers and on ?format=md requests. Also exposes /llms.txt.
 * Version:           0.3.0
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

define( 'MD4AI_VERSION', '0.3.0' );
define( 'MD4AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MD4AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MD4AI_PLUGIN_DIR . 'includes/settings.php';

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
 * Returns public post types eligible for markdown serving (excludes attachments).
 *
 * @return string[]
 */
function md4ai_supported_post_types(): array {
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );
	return array_values( $types );
}

add_action( 'plugins_loaded', array( 'MD4AI', 'init' ) );

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'MD4AI_Settings', 'init' ) );
	add_action( 'plugins_loaded', array( 'MD4AI_Metabox', 'init' ) );
}

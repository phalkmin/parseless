<?php
/**
 * Plain-text helpers for Markdown and metadata output.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduces a possibly-HTML string to plain text for Markdown metadata.
 *
 * @param string $value Raw value.
 * @return string
 */
function md4ai_plain_text( string $value ): string {
	// Decode before stripping, or encoded markup survives the strip and is decoded into live tags.
	$text = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	// Shield a bare or unclosed "<" from strip_tags(), which would drop everything after it.
	$text = (string) preg_replace( '/<(?![a-zA-Z\/!?])|<(?=[^>]*$)/', '&lt;', $text );
	$text = wp_strip_all_tags( $text );
	$text = str_replace( '&lt;', '<', $text );
	return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * Double-quotes and escapes a string for a YAML frontmatter value.
 *
 * @param string $value Raw value.
 * @return string
 */
function md4ai_yaml_quote( string $value ): string {
	return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
}

/**
 * Returns the site title as plain text.
 *
 * @return string
 */
function md4ai_site_name(): string {
	return md4ai_plain_text( (string) get_bloginfo( 'name', 'display' ) );
}

/**
 * Returns the site tagline as plain text.
 *
 * @return string
 */
function md4ai_site_description(): string {
	return md4ai_plain_text( (string) get_bloginfo( 'description', 'display' ) );
}

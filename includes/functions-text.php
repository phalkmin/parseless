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
 * Reduces a possibly-HTML, possibly-entity-encoded string to clean plain text
 * suitable for Markdown metadata.
 *
 * Entities are decoded before tags are stripped: stripping first leaves
 * entity-encoded markup ("&amp;lt;script&amp;gt;") untouched, and the decode then
 * materializes it as live markup in the output. Decoding first turns it into
 * a real tag that wp_strip_all_tags() removes, contents included.
 *
 * @param string $value Raw value.
 * @return string
 */
function md4ai_plain_text( string $value ): string {
	$text = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	// A "<" that can't open a tag (as in "3 < 5") or is never closed is
	// content, not markup — but strip_tags() treats it as unclosed markup and
	// discards everything after it. Re-encode those before stripping, restore
	// them after.
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
 * `get_bloginfo( 'name' )` returns the `blogname` option HTML-entity-encoded
 * (e.g. an ampersand typed in Settings > General is stored as `&#038;`);
 * `md4ai_plain_text()` decodes it back to a literal character.
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

<?php
/**
 * Request URL helpers.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the current request path relative to the home URL, trimmed of
 * slashes and with percent-encoding intact.
 *
 * Endpoints match against this instead of the raw REQUEST_URI so they keep
 * working when WordPress lives in a subdirectory, and instead of a
 * sanitize_text_field()'d copy because that strips %xx octets and breaks
 * encoded (non-ASCII) slugs.
 *
 * @return string
 */
function md4ai_request_path(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only for exact path comparison, never output; sanitizing would strip percent-encoding.
	$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

	// The prefix must end at a path-segment boundary: "/blog" and "/blog/x"
	// are inside a "/blog" install, "/blogging" is not.
	if ( '' !== $home && str_starts_with( $path, $home ) ) {
		$rest = substr( $path, strlen( $home ) );
		if ( '' === $rest || str_starts_with( $rest, '/' ) ) {
			$path = $rest;
		}
	}

	return trim( $path, '/' );
}

/**
 * Returns the current request URL for logging.
 *
 * The home path prefix is stripped before rebuilding on home_url(), so
 * subdirectory installs don't log a doubled "/blog/blog/..." path.
 *
 * @return string
 */
function md4ai_current_url(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped with esc_url_raw() below.
	$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

	// Segment boundary check as in md4ai_request_path(), except the raw URI
	// may continue with a query string ("/blog?format=okf") instead of "/".
	if ( '' !== $home && str_starts_with( $uri, $home ) ) {
		$rest = substr( $uri, strlen( $home ) );
		if ( '' === $rest || str_starts_with( $rest, '/' ) || str_starts_with( $rest, '?' ) ) {
			$uri = $rest;
		}
	}

	return esc_url_raw( home_url( $uri ) );
}

/**
 * Returns the current request User-Agent string.
 *
 * @return string
 */
function md4ai_current_ua(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UA is truncated and stored for analytics, escaped on output.
	return (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
}

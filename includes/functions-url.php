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
 * Returns the request path relative to the home URL, percent-encoding intact.
 *
 * @return string
 */
function md4ai_request_path(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only; sanitizing strips percent-encoding.
	$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

	// Strip only at a segment boundary: "/blogging" is not inside a "/blog" install.
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
 * @return string
 */
function md4ai_current_url(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped with esc_url_raw() below.
	$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

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

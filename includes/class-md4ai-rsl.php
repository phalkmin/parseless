<?php
/**
 * RSL (Really Simple Licensing) declaration.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a machine-readable licence document at /license.xml.
 */
class MD4AI_RSL {

	private const TRANSIENT_KEY = 'md4ai_rsl_xml';

	/**
	 * Request path that serves the licence document.
	 */
	public const PATH = 'license.xml';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ) );
	}

	/**
	 * Serves the licence document when its path is requested.
	 */
	public static function maybe_serve(): void {
		if ( ! md4ai_get_setting( 'rsl_enabled' ) ) {
			return;
		}

		if ( self::PATH !== md4ai_request_path() ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false === $cached ) {
			$cached = self::build();
			set_transient( self::TRANSIENT_KEY, $cached, DAY_IN_SECONDS );
		}

		status_header( 200 );
		header( 'Content-Type: application/rsl+xml; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built with esc_url()/esc_xml() below.
		echo $cached;
		exit;
	}

	/**
	 * Builds the RSL XML document.
	 *
	 * @return string
	 */
	public static function build(): string {
		$permits = (string) md4ai_get_setting( 'rsl_permits' );
		$payment = (string) md4ai_get_setting( 'rsl_payment_type' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rsl xmlns="https://rslstandard.org/rsl">' . "\n";
		$xml .= "\t" . '<content url="' . esc_url( home_url( '/' ) ) . '">' . "\n";
		$xml .= "\t\t<license>\n";

		if ( 'none' === $permits ) {
			$xml .= "\t\t\t" . '<prohibits type="usage">all</prohibits>' . "\n";
		} else {
			$xml .= "\t\t\t" . '<permits type="usage">' . esc_xml( $permits ) . '</permits>' . "\n";
		}

		$xml  .= "\t\t\t" . '<payment type="' . esc_xml( $payment ) . '">' . "\n";
		$terms = self::payment_terms( $payment );
		if ( '' !== $terms ) {
			$xml .= "\t\t\t\t<custom>" . esc_url( $terms ) . "</custom>\n";
		}
		$xml .= "\t\t\t</payment>\n";

		$xml .= "\t\t</license>\n";
		$xml .= "\t</content>\n";
		$xml .= '</rsl>' . "\n";

		return apply_filters( 'md4ai_rsl_xml', $xml );
	}

	/**
	 * Returns the terms-negotiation URL for payment types that need one.
	 *
	 * @param string $payment Payment type.
	 * @return string Empty when the terms need no contact point.
	 */
	private static function payment_terms( string $payment ): string {
		if ( ! in_array( $payment, array( 'subscription', 'use', 'training' ), true ) ) {
			return '';
		}
		return (string) apply_filters( 'md4ai_rsl_terms_url', home_url( '/' ) );
	}

	/**
	 * Returns the absolute URL of the licence document.
	 *
	 * @return string
	 */
	public static function license_url(): string {
		return home_url( '/' . self::PATH );
	}

	/**
	 * Clears the cached licence document.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}

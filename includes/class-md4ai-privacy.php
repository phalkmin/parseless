<?php
/**
 * Privacy exporter and eraser registrations.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WordPress privacy hooks for log data.
 */
class MD4AI_Privacy {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Registers the exporter callback.
	 *
	 * @param array<string, mixed> $exporters Existing exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['parseless'] = array(
			'exporter_friendly_name' => __( 'ParseLess request logs', 'parseless' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser callback.
	 *
	 * @param array<string, mixed> $erasers Existing erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['parseless'] = array(
			'eraser_friendly_name' => __( 'ParseLess request logs', 'parseless' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Returns an empty export — IP hashes are not reversible from an email address.
	 *
	 * @param string $email Email address (unused).
	 * @param int    $page  Page number (unused).
	 * @return array{data:array<int,mixed>, done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		unset( $email, $page );
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	/**
	 * Returns an empty erase — IP hashes are not reversible from an email address.
	 *
	 * @param string $email Email address (unused).
	 * @param int    $page  Page number (unused).
	 * @return array{items_removed:bool, items_retained:bool, messages:array<int,string>, done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $email, $page );
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array( __( 'ParseLess only stores salted SHA-256 IP hashes that cannot be matched from an email address.', 'parseless' ) ),
			'done'           => true,
		);
	}
}

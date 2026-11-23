<?php
/**
 * Crawler identity verification against operator-published IP ranges.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a claimed crawler's IP against the CIDR list its operator publishes.
 */
class MD4AI_Verifier {

	public const OPTION_KEY = 'md4ai_ip_ranges';
	public const CRON_HOOK  = 'md4ai_refresh_ip_ranges';

	/**
	 * Operator-published range lists by bot token.
	 *
	 * @var array<string, string>
	 */
	private const SOURCES = array(
		'GPTBot'           => 'https://openai.com/gptbot.json',
		'OAI-SearchBot'    => 'https://openai.com/searchbot.json',
		'ChatGPT-User'     => 'https://openai.com/chatgpt-user.json',
		'ClaudeBot'        => 'https://claude.com/crawling/bots.json',
		'Claude-User'      => 'https://claude.com/crawling/bots.json',
		'Claude-SearchBot' => 'https://claude.com/crawling/bots.json',
		'anthropic-ai'     => 'https://claude.com/crawling/bots.json',
		'PerplexityBot'    => 'https://www.perplexity.ai/perplexitybot.json',
		'Perplexity-User'  => 'https://www.perplexity.ai/perplexity-user.json',
		'CCBot'            => 'https://index.commoncrawl.org/ccbot.json',
		'DuckAssistBot'    => 'https://duckduckgo.com/duckassistbot.json',
		'MistralAI-User'   => 'https://mistral.ai/mistralai-user-ips.json',
		'Amazonbot'        => 'https://developer.amazon.com/amazonbot/ip-addresses/',
	);

	/**
	 * Tokens that exist only in robots.txt, never in a real User-Agent.
	 *
	 * @var string[]
	 */
	private const SPOOFED_TOKENS = array( 'Google-Extended', 'Applebot-Extended' );

	/**
	 * Off-network client UA fragments and the bot tokens each may excuse.
	 *
	 * @var array<string, string[]>
	 */
	private const EXEMPT_UA_SUBSTRINGS = array(
		'claude-code/' => array( 'Claude-User', 'ClaudeBot', 'Claude-SearchBot', 'anthropic-ai' ),
	);

	/**
	 * Registers the cron callback.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Schedules the daily refresh when verification is on.
	 */
	public static function schedule(): void {
		if ( ! md4ai_get_setting( 'verify_bots' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedules the daily refresh.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Bot => list URL map, filtered by md4ai_ip_range_sources.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		static $sources = null;
		if ( null === $sources ) {
			$filtered = apply_filters( 'md4ai_ip_range_sources', self::SOURCES );
			$sources  = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : array();
		}
		return $sources;
	}

	/**
	 * Returns the verdict for the current request's claimed bot.
	 *
	 * @param string $bot_name Bot identifier as logged.
	 * @return bool|null Null when it could not be checked.
	 */
	public static function verify( string $bot_name ): ?bool {
		if ( ! md4ai_get_setting( 'verify_bots' ) ) {
			return null;
		}
		if ( '' === $bot_name || in_array( $bot_name, array( 'manual', 'unknown' ), true ) ) {
			return null;
		}

		static $memo = array();
		$key         = strtolower( $bot_name );
		if ( ! array_key_exists( $key, $memo ) ) {
			$memo[ $key ] = self::check( $bot_name );
		}
		return $memo[ $key ];
	}

	/**
	 * Uncached verdict.
	 *
	 * @param string $bot_name Bot identifier as logged.
	 * @return bool|null
	 */
	private static function check( string $bot_name ): ?bool {
		if ( self::token_in( $bot_name, self::SPOOFED_TOKENS ) ) {
			return false;
		}

		$ua = md4ai_current_ua();
		foreach ( self::EXEMPT_UA_SUBSTRINGS as $fragment => $tokens ) {
			if ( false !== stripos( $ua, $fragment ) && self::token_in( $bot_name, $tokens ) ) {
				return null;
			}
		}

		$url = self::source_for( $bot_name );
		if ( '' === $url ) {
			return null;
		}

		$stored = self::stored();
		if ( empty( $stored[ $url ]['ranges'] ) || (int) ( $stored[ $url ]['fetched_at'] ?? 0 ) < time() - WEEK_IN_SECONDS ) {
			return null;
		}

		$ip = self::client_ip();
		if ( '' === $ip ) {
			return null;
		}

		foreach ( (array) $stored[ $url ]['ranges'] as $cidr ) {
			if ( self::ip_in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Case-insensitive membership test for bot tokens.
	 *
	 * @param string   $bot_name Bot identifier.
	 * @param string[] $tokens   Candidates.
	 * @return bool
	 */
	private static function token_in( string $bot_name, array $tokens ): bool {
		foreach ( $tokens as $token ) {
			if ( 0 === strcasecmp( (string) $token, $bot_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List URL for a bot token, case-insensitive, or "" when none is published.
	 *
	 * @param string $bot_name Bot identifier.
	 * @return string
	 */
	private static function source_for( string $bot_name ): string {
		foreach ( self::sources() as $token => $url ) {
			if ( 0 === strcasecmp( (string) $token, $bot_name ) ) {
				return (string) $url;
			}
		}
		return '';
	}

	/**
	 * Client IP per the "Visitor IP source" setting, or "" when invalid.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$keys   = array(
			'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',
			'x_forwarded_for'  => 'HTTP_X_FORWARDED_FOR',
			'x_real_ip'        => 'HTTP_X_REAL_IP',
		);
		$server = $keys[ (string) md4ai_get_setting( 'client_ip_header' ) ] ?? 'REMOTE_ADDR';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP literal below, never output.
		$raw = isset( $_SERVER[ $server ] ) ? (string) wp_unslash( $_SERVER[ $server ] ) : '';
		if ( '' === $raw && 'REMOTE_ADDR' !== $server ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same validation below.
			$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		}

		// The proxy appends to X-Forwarded-For; the first entry is client-supplied.
		$chain = array_map( 'trim', explode( ',', $raw ) );
		$ip    = (string) end( $chain );

		$bin = self::pton( $ip );
		if ( false === $bin ) {
			return '';
		}

		if ( 16 === strlen( $bin ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $bin, 0, 12 ) ) {
			return (string) inet_ntop( substr( $bin, 12 ) );
		}

		return $ip;
	}

	/**
	 * Whether an IP falls inside a CIDR block; v4 never matches v6 or vice versa.
	 *
	 * @param string $ip   Textual IP.
	 * @param string $cidr "network/bits".
	 * @return bool
	 */
	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[1] ) ) {
			return false;
		}
		$ip_bin  = self::pton( $ip );
		$net_bin = self::pton( $parts[0] );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits = (int) $parts[1];
		if ( $bits < 0 || $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		if ( $whole > 0 && 0 !== strncmp( $ip_bin, $net_bin, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $whole ] ) & $mask ) === ( ord( $net_bin[ $whole ] ) & $mask );
	}

	/**
	 * Extracts normalised CIDRs from a JSON or HTML-embedded body.
	 *
	 * @param string $body Response body.
	 * @return string[]
	 */
	public static function parse_ranges( string $body ): array {
		$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! preg_match_all( '/"ipv[46]Prefix"\s*:\s*"([0-9a-fA-F.:\/]+)"/', $body, $matches ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches[1] as $raw ) {
			$parts = explode( '/', $raw, 2 );
			$bin   = self::pton( $parts[0] );
			if ( false === $bin ) {
				continue;
			}
			$max  = strlen( $bin ) * 8;
			$bits = isset( $parts[1] ) ? (int) $parts[1] : $max;
			if ( $bits < 0 || $bits > $max ) {
				continue;
			}
			$out[ $parts[0] . '/' . $bits ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Binary form of an IP, or false; inet_pton() alone warns on garbage.
	 *
	 * @param string $ip Textual IP.
	 * @return string|false
	 */
	private static function pton( string $ip ): string|false {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return inet_pton( $ip );
	}

	/**
	 * Refetches every source list, keeping the previous copy on failure.
	 */
	public static function refresh(): void {
		if ( ! md4ai_get_setting( 'verify_bots' ) ) {
			return;
		}

		$stored = self::stored();
		$urls   = array_values( array_unique( array_values( self::sources() ) ) );

		foreach ( $urls as $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
			$code     = (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || 200 !== $code ) {
				$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only trace of a broken source.
				error_log( 'ParseLess: IP range fetch failed for ' . $url . ' (' . $reason . ')' );
				continue;
			}

			$ranges = self::parse_ranges( (string) wp_remote_retrieve_body( $response ) );
			if ( empty( $ranges ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see above.
				error_log( 'ParseLess: IP range list at ' . $url . ' contained no prefixes; keeping the previous copy' );
				continue;
			}

			$stored[ $url ] = array(
				'fetched_at' => time(),
				'ranges'     => $ranges,
			);
			// Stored per source so a time-limit kill keeps what already fetched.
			self::store( array_intersect_key( $stored, array_flip( $urls ) ) );
		}
	}

	/**
	 * Per-source freshness for the Analytics tab.
	 *
	 * @return array<string, array{bots:string[], fetched_at:int, count:int}> Keyed by list URL.
	 */
	public static function source_status(): array {
		$stored = self::stored();
		$status = array();
		foreach ( self::sources() as $bot => $url ) {
			if ( ! isset( $status[ $url ] ) ) {
				$status[ $url ] = array(
					'bots'       => array(),
					'fetched_at' => (int) ( $stored[ $url ]['fetched_at'] ?? 0 ),
					'count'      => count( (array) ( $stored[ $url ]['ranges'] ?? array() ) ),
				);
			}
			$status[ $url ]['bots'][] = (string) $bot;
		}
		return $status;
	}

	/**
	 * Bots on the owner's list that no operator list can vouch for.
	 *
	 * @return string[]
	 */
	public static function unverifiable_bots(): array {
		$out = array();
		foreach ( MD4AI_Detector::get_bot_list() as $bot ) {
			$bot = (string) $bot;
			if ( '' === self::source_for( $bot ) && ! self::token_in( $bot, self::SPOOFED_TOKENS ) ) {
				$out[] = $bot;
			}
		}
		return $out;
	}

	/**
	 * Reads the stored lists.
	 *
	 * @return array<string, array{fetched_at:int, ranges:string[]}>
	 */
	private static function stored(): array {
		$value = get_option( self::OPTION_KEY, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Writes the lists; created without autoload because the blob is tens of KB.
	 *
	 * @param array<string, array{fetched_at:int, ranges:string[]}> $data Lists keyed by URL.
	 */
	private static function store( array $data ): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, $data, '', false );
			return;
		}
		update_option( self::OPTION_KEY, $data );
	}
}

<?php
/**
 * Bot taxonomy: which company runs a crawler and what it fetches for.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static lookup from a logged bot_name to its operator and purpose.
 *
 * Derived at read time from the UA substring the detector matched, so the
 * requests table needs no extra column and the map can be corrected in a
 * release without a migration.
 */
class MD4AI_Bots {

	public const PURPOSE_TRAINING = 'training';
	public const PURPOSE_SEARCH   = 'search';
	public const PURPOSE_LIVE     = 'live';
	public const PURPOSE_AGENT    = 'agent';
	public const PURPOSE_MANUAL   = 'manual';
	public const PURPOSE_UNKNOWN  = 'unknown';

	/**
	 * Known crawlers, keyed by the UA substring as it appears in the bot list.
	 * Keys are matched case-insensitively against the stored bot_name.
	 *
	 * @var array<string, array{0:string, 1:string}> company, purpose.
	 */
	private const MAP = array(
		'GPTBot'               => array( 'OpenAI', self::PURPOSE_TRAINING ),
		'ChatGPT-User'         => array( 'OpenAI', self::PURPOSE_LIVE ),
		'OAI-SearchBot'        => array( 'OpenAI', self::PURPOSE_SEARCH ),
		'ClaudeBot'            => array( 'Anthropic', self::PURPOSE_TRAINING ),
		'Claude-User'          => array( 'Anthropic', self::PURPOSE_LIVE ),
		'Claude-SearchBot'     => array( 'Anthropic', self::PURPOSE_SEARCH ),
		'Claude-Code'          => array( 'Anthropic', self::PURPOSE_AGENT ),
		'anthropic-ai'         => array( 'Anthropic', self::PURPOSE_TRAINING ),
		'PerplexityBot'        => array( 'Perplexity', self::PURPOSE_SEARCH ),
		'Perplexity-User'      => array( 'Perplexity', self::PURPOSE_LIVE ),
		'CCBot'                => array( 'Common Crawl', self::PURPOSE_TRAINING ),
		'Google-Extended'      => array( 'Google', self::PURPOSE_TRAINING ),
		'Applebot-Extended'    => array( 'Apple', self::PURPOSE_TRAINING ),
		'Bytespider'           => array( 'ByteDance', self::PURPOSE_TRAINING ),
		'Meta-ExternalAgent'   => array( 'Meta', self::PURPOSE_TRAINING ),
		'Meta-ExternalFetcher' => array( 'Meta', self::PURPOSE_LIVE ),
		'cohere-ai'            => array( 'Cohere', self::PURPOSE_TRAINING ),
		'Amazonbot'            => array( 'Amazon', self::PURPOSE_SEARCH ),
		'DuckAssistBot'        => array( 'DuckDuckGo', self::PURPOSE_LIVE ),
		'MistralAI-User'       => array( 'Mistral', self::PURPOSE_LIVE ),
		'YouBot'               => array( 'You.com', self::PURPOSE_SEARCH ),
		'Diffbot'              => array( 'Diffbot', self::PURPOSE_TRAINING ),
	);

	/**
	 * Returns the taxonomy map with the md4ai_bot_taxonomy filter applied.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function map(): array {
		static $map = null;
		if ( null === $map ) {
			$map = (array) apply_filters( 'md4ai_bot_taxonomy', self::MAP );
		}
		return $map;
	}

	/**
	 * Returns company and purpose for a logged bot_name.
	 *
	 * "manual" is the detector's label for a human using ?format=md; anything
	 * else not in the map is a crawler the owner added by hand.
	 *
	 * @param string $bot_name Stored bot_name value.
	 * @return array{company:string, purpose:string}
	 */
	public static function info( string $bot_name ): array {
		if ( 'manual' === $bot_name ) {
			return array(
				'company' => '',
				'purpose' => self::PURPOSE_MANUAL,
			);
		}
		foreach ( self::map() as $key => $entry ) {
			if ( 0 === strcasecmp( (string) $key, $bot_name ) ) {
				return array(
					'company' => (string) ( $entry[0] ?? '' ),
					'purpose' => (string) ( $entry[1] ?? self::PURPOSE_UNKNOWN ),
				);
			}
		}
		return array(
			'company' => '',
			'purpose' => self::PURPOSE_UNKNOWN,
		);
	}

	/**
	 * Returns translated labels for every purpose, in display order.
	 *
	 * @return array<string, string>
	 */
	public static function purpose_labels(): array {
		return array(
			self::PURPOSE_LIVE     => __( 'Live answers', 'parseless' ),
			self::PURPOSE_SEARCH   => __( 'Search index', 'parseless' ),
			self::PURPOSE_TRAINING => __( 'Model training', 'parseless' ),
			self::PURPOSE_AGENT    => __( 'Coding agents', 'parseless' ),
			self::PURPOSE_MANUAL   => __( 'Manual requests', 'parseless' ),
			self::PURPOSE_UNKNOWN  => __( 'Unclassified', 'parseless' ),
		);
	}

	/**
	 * Returns the translated label for one purpose.
	 *
	 * @param string $purpose Purpose constant.
	 * @return string
	 */
	public static function purpose_label( string $purpose ): string {
		$labels = self::purpose_labels();
		return $labels[ $purpose ] ?? $labels[ self::PURPOSE_UNKNOWN ];
	}

	/**
	 * Derives the KPI set from aggregated bot rows.
	 *
	 * "Chat impressions" counts fetches by user-triggered bots — the page was
	 * read live while an assistant answered someone. "Distinct crawlers"
	 * excludes manual and unknown traffic.
	 *
	 * @param array<int, array{bot_name:string, hits:int}> $rows Rows from MD4AI_Stats::top_bots().
	 * @return array{chat_impressions:int, distinct_crawlers:int, by_company:array<string,int>, by_purpose:array<string,int>}
	 */
	public static function summarize( array $rows ): array {
		$summary = array(
			'chat_impressions'  => 0,
			'distinct_crawlers' => 0,
			'by_company'        => array(),
			'by_purpose'        => array(),
		);

		foreach ( $rows as $row ) {
			$bot  = (string) ( $row['bot_name'] ?? '' );
			$hits = (int) ( $row['hits'] ?? 0 );
			if ( '' === $bot || 'unknown' === $bot ) {
				continue;
			}
			$info    = self::info( $bot );
			$purpose = $info['purpose'];

			$summary['by_purpose'][ $purpose ] = ( $summary['by_purpose'][ $purpose ] ?? 0 ) + $hits;

			if ( self::PURPOSE_MANUAL === $purpose ) {
				continue;
			}
			$company = '' !== $info['company'] ? $info['company'] : __( 'Other', 'parseless' );
			++$summary['distinct_crawlers'];
			$summary['by_company'][ $company ] = ( $summary['by_company'][ $company ] ?? 0 ) + $hits;
			if ( self::PURPOSE_LIVE === $purpose ) {
				$summary['chat_impressions'] += $hits;
			}
		}

		arsort( $summary['by_company'] );
		return $summary;
	}
}

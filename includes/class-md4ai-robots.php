<?php
/**
 * Robots.txt composition.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sole owner of the robots_txt filter: sitemap, Content Signals, AI bot rules
 * and the RSL licence directive all compose here.
 */
class MD4AI_Robots {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );
	}

	/**
	 * Composes ParseLess's additions to robots.txt.
	 *
	 * @param string $output    The robots.txt content so far.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public static function robots_txt( string $output, bool $is_public ): string {
		if ( ! $is_public ) {
			return $output;
		}

		$output = self::insert_content_signal( $output );
		$output = self::append_ai_rules( $output );
		$output = self::append_sitemap( $output );
		$output = self::append_license( $output );

		return $output;
	}

	/**
	 * Inserts the Content-Signal line into the existing "User-agent: *" group.
	 *
	 * The directive is only valid inside a user-agent group, so it is inserted
	 * after that line rather than appended — appending yields a file validators
	 * reject.
	 *
	 * @param string $output robots.txt content.
	 * @return string
	 */
	private static function insert_content_signal( string $output ): string {
		$line = self::signal_line();
		if ( '' === $line ) {
			return $output;
		}

		$preamble = md4ai_get_setting( 'content_signals_boilerplate' ) ? self::policy_text() : '';

		$lines    = preg_split( '/\r\n|\r|\n/', $output );
		$inserted = false;
		$out      = array();
		foreach ( (array) $lines as $current ) {
			$out[] = $current;
			if ( ! $inserted && preg_match( '/^\s*user-agent\s*:\s*\*\s*$/i', (string) $current ) ) {
				$out[]    = $line;
				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			// No wildcard group to join, so declare our own.
			$out[] = '';
			$out[] = 'User-agent: *';
			$out[] = $line;
		}

		$body = implode( "\n", $out );

		return '' !== $preamble ? $preamble . "\n" . $body : $body;
	}

	/**
	 * Builds the Content-Signal directive line, or "" when the owner has
	 * expressed no preference at all.
	 *
	 * @return string
	 */
	public static function signal_line(): string {
		$signals = array(
			'search'   => (string) md4ai_get_setting( 'content_signal_search' ),
			'ai-input' => (string) md4ai_get_setting( 'content_signal_ai_input' ),
			'ai-train' => (string) md4ai_get_setting( 'content_signal_ai_train' ),
		);

		$parts = array();
		foreach ( $signals as $name => $value ) {
			if ( 'yes' === $value || 'no' === $value ) {
				$parts[] = $name . '=' . $value;
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return 'Content-Signal: ' . implode( ', ', $parts );
	}

	/**
	 * Returns the human-readable content signals policy, as robots.txt comments.
	 *
	 * @return string
	 */
	private static function policy_text(): string {
		$lines = array(
			'# As a condition of accessing this website, you agree to honour the',
			'# content signals below.',
			'#',
			'# A signal set to yes permits the corresponding use. A signal set to no',
			'# prohibits it. A signal that is absent neither grants nor restricts',
			'# permission for that use.',
			'#',
			'# search: building a search index and returning links or short excerpts.',
			'#         This does not include AI-generated summaries of the content.',
			'# ai-input: supplying this content to a generative AI system to produce',
			'#           an answer, including retrieval-augmented generation.',
			'# ai-train: training or fine-tuning AI models.',
			'#',
			'# Any restriction expressed above is an express reservation of rights',
			'# under Article 4 of EU Directive 2019/790.',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Declares the blocked-bot list as Disallow groups.
	 *
	 * These crawlers already receive a 403 from the Markdown endpoint. Saying so
	 * in robots.txt means the polite ones never fetch in the first place.
	 *
	 * @param string $output robots.txt content.
	 * @return string
	 */
	private static function append_ai_rules( string $output ): string {
		if ( ! md4ai_get_setting( 'robots_ai_rules' ) ) {
			return $output;
		}

		$blocked = MD4AI_Detector::get_blocked_bot_list();
		if ( empty( $blocked ) ) {
			return $output;
		}

		$output .= "\n# ParseLess: crawlers blocked from this site's content\n";
		foreach ( $blocked as $bot ) {
			$output .= 'User-agent: ' . sanitize_text_field( (string) $bot ) . "\n";
			$output .= "Disallow: /\n";
		}

		return $output;
	}

	/**
	 * Advertises the AI sitemap.
	 *
	 * @param string $output robots.txt content.
	 * @return string
	 */
	private static function append_sitemap( string $output ): string {
		if ( ! md4ai_get_setting( 'sitemap_enabled' ) ) {
			return $output;
		}

		$output .= "\n# ParseLess: Markdown versions of content for AI crawlers\n";
		$output .= 'Sitemap: ' . esc_url_raw( home_url( '/' . MD4AI_Sitemap::PATH ) ) . "\n";

		return $output;
	}

	/**
	 * Advertises the RSL licence document.
	 *
	 * @param string $output robots.txt content.
	 * @return string
	 */
	private static function append_license( string $output ): string {
		if ( ! md4ai_get_setting( 'rsl_enabled' ) ) {
			return $output;
		}

		$output .= "\n# ParseLess: machine-readable licence terms\n";
		$output .= 'License: ' . esc_url_raw( MD4AI_RSL::license_url() ) . "\n";

		return $output;
	}
}

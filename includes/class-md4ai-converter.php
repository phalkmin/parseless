<?php
/**
 * HTML to Markdown converter.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts HTML to Markdown via DOMDocument.
 */
class MD4AI_Converter {

	/**
	 * Converts HTML to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	public static function convert( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$html = preg_replace( '/<!--\s*\/?wp:[^-]*?-->/s', '', (string) $html );

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>'
		);
		libxml_clear_errors();

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return wp_strip_all_tags( $html );
		}

		$md = self::children_to_md( $body, array() );
		$md = preg_replace( "/\n{3,}/", "\n\n", (string) $md );
		$md = str_replace( "\0", '', (string) $md );

		return trim( $md );
	}

	/**
	 * Converts child nodes to Markdown.
	 *
	 * @param DOMNode              $node Node whose children should be converted.
	 * @param array<string, mixed> $ctx  Conversion context.
	 * @return string
	 */
	private static function children_to_md( DOMNode $node, array $ctx ): string {
		$out = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		foreach ( $node->childNodes as $child ) {
			$out .= self::node_to_md( $child, $ctx );
		}
		return $out;
	}

	/**
	 * Converts one node to Markdown.
	 *
	 * @param DOMNode              $node Node to convert.
	 * @param array<string, mixed> $ctx  Conversion context.
	 * @return string
	 */
	private static function node_to_md( DOMNode $node, array $ctx ): string {
		if ( $node instanceof DOMText ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			$text = $node->nodeValue;
			$text = str_replace( "\u{00A0}", ' ', $text );
			if ( ! empty( $ctx['in_code'] ) ) {
				return $text;
			}
			return preg_replace( '/\s+/', ' ', $text );
		}

		if ( ! ( $node instanceof DOMElement ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		$tag = strtolower( $node->tagName );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				$text  = self::single_line( self::children_to_md( $node, $ctx ) );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . $text . "\n\n";

			case 'p':
				return trim( self::children_to_md( $node, $ctx ) ) . "\n\n";

			case 'strong':
			case 'b':
				return self::wrap_inline( '**', self::children_to_md( $node, $ctx ) );

			case 'em':
			case 'i':
				return self::wrap_inline( '*', self::children_to_md( $node, $ctx ) );

			case 'code':
				$code_ctx = array_merge( $ctx, array( 'in_code' => true ) );
				$code     = self::children_to_md( $node, $code_ctx );
				if ( ! empty( $ctx['in_pre'] ) ) {
					return $code;
				}
				return self::code_span( $code );

			case 'pre':
				return self::pre_to_md( $node, $ctx );

			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = self::children_to_md( $node, $ctx );
				if ( '' === $href ) {
					return $text;
				}
				return "[{$text}]({$href})";

			case 'img':
				$src = $node->getAttribute( 'src' );
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					foreach ( array( 'data-src', 'data-lazy-src' ) as $lazy_attr ) {
						$lazy = $node->getAttribute( $lazy_attr );
						if ( '' !== $lazy ) {
							$src = $lazy;
							break;
						}
					}
				}
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					return '';
				}
				$alt = $node->getAttribute( 'alt' );
				return "![{$alt}]({$src})";

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'ul':
			case 'ol':
				return self::list_to_md( $node, $ctx, (string) ( $ctx['list_indent'] ?? '' ) );

			case 'blockquote':
				$inner  = trim( self::children_to_md( $node, $ctx ) );
				$lines  = explode( "\n", $inner );
				$quoted = array_map( static fn( string $l ): string => '> ' . $l, $lines );
				return "\n\n" . implode( "\n", $quoted ) . "\n\n";

			case 'table':
				return self::table_to_md( $node, $ctx );

			case 'script':
			case 'style':
			case 'noscript':
			case 'template':
			case 'svg':
			case 'iframe':
				return '';

			default:
				return self::children_to_md( $node, $ctx );
		}
	}

	/**
	 * Converts a preformatted block to Markdown.
	 *
	 * @param DOMElement           $node Pre element.
	 * @param array<string, mixed> $ctx  Conversion context.
	 * @return string
	 */
	private static function pre_to_md( DOMElement $node, array $ctx ): string {
		$lang       = '';
		$source     = $node;
		$code_nodes = $node->getElementsByTagName( 'code' );
		if ( $code_nodes->length > 0 ) {
			$code_el = $code_nodes->item( 0 );
			if ( preg_match( '/(?:^|\s)language-(\w+)/', $code_el->getAttribute( 'class' ), $m ) ) {
				$lang = $m[1];
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			if ( 1 === $code_nodes->length && $code_el->parentNode->isSameNode( $node ) ) {
				$source = $code_el;
			}
		}
		$pre_ctx = array_merge(
			$ctx,
			array(
				'in_pre'  => true,
				'in_code' => true,
			)
		);
		$code    = self::children_to_md( $source, $pre_ctx );
		// Strip only leading blank lines, not trim(): the first line's indentation is content.
		$code  = rtrim( (string) preg_replace( '/^(?:[ \t]*\r?\n)+/', '', $code ) );
		$fence = str_repeat( '`', max( 3, self::longest_backtick_run( $code ) + 1 ) );
		// NULs shield the fence's blank lines from later newline collapses; convert() strips them.
		$code = str_replace( "\n", "\n\0", $code );

		return "\n\n{$fence}{$lang}\n" . $code . "\n{$fence}\n\n";
	}

	/**
	 * Wraps text in a code span whose delimiter outruns any backtick run inside.
	 *
	 * @param string $code Code span content.
	 * @return string
	 */
	private static function code_span( string $code ): string {
		$run = self::longest_backtick_run( $code );
		if ( 0 === $run ) {
			return '`' . $code . '`';
		}
		$fence = str_repeat( '`', $run + 1 );
		return $fence . ' ' . $code . ' ' . $fence;
	}

	/**
	 * Returns the length of the longest run of consecutive backticks.
	 *
	 * @param string $text Text to scan.
	 * @return int
	 */
	private static function longest_backtick_run( string $text ): int {
		if ( ! preg_match_all( '/`+/', $text, $m ) ) {
			return 0;
		}
		return max( array_map( 'strlen', $m[0] ) );
	}

	/**
	 * Converts a list to Markdown.
	 *
	 * @param DOMElement           $node   List element.
	 * @param array<string, mixed> $ctx    Conversion context.
	 * @param string               $indent Indentation before this list's markers.
	 * @return string
	 */
	private static function list_to_md( DOMElement $node, array $ctx, string $indent ): string {
		$top = '' === $indent;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		$ordered = 'ol' === strtolower( $node->tagName );
		$number  = $node->hasAttribute( 'start' ) ? max( 0, (int) $node->getAttribute( 'start' ) ) : 1;
		$out     = $top ? "\n" : '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		foreach ( $node->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			if ( ! ( $child instanceof DOMElement ) || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$marker       = $ordered ? $number . '. ' : '- ';
			$item_indent  = $indent . str_repeat( ' ', strlen( $marker ) );
			$item_ctx     = array_merge( $ctx, array( 'list_indent' => $item_indent ) );
			$item_text    = '';
			$nested_lists = '';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child instanceof DOMElement ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
					$li_tag = strtolower( $li_child->tagName );
					if ( 'ul' === $li_tag || 'ol' === $li_tag ) {
						$nested_lists .= self::list_to_md( $li_child, $item_ctx, $item_indent );
						continue;
					}
				}
				$item_text .= self::node_to_md( $li_child, $item_ctx );
			}

			$item_text = trim( $item_text );
			$item_text = preg_replace( "/\n{2,}/", "\n", $item_text );
			$item_text = str_replace( "\n", "\n" . $item_indent, (string) $item_text );

			$out .= $indent . $marker . $item_text . "\n" . $nested_lists;
			++$number;
		}

		return $out . ( $top ? "\n" : '' );
	}

	/**
	 * Converts a table to Markdown.
	 *
	 * @param DOMElement           $node Table element.
	 * @param array<string, mixed> $ctx  Conversion context.
	 * @return string
	 */
	private static function table_to_md( DOMElement $node, array $ctx ): string {
		$rows = self::collect_table_rows( $node );
		if ( empty( $rows ) ) {
			return '';
		}

		$parsed         = array();
		$has_header_row = false;

		foreach ( $rows as $row ) {
			$cells     = array();
			$is_header = false;

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			foreach ( $row->childNodes as $cell ) {
				if ( ! ( $cell instanceof DOMElement ) ) {
					continue;
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
				$cell_tag = strtolower( $cell->tagName );
				if ( 'th' === $cell_tag ) {
					$is_header = true;
				}
				if ( 'th' === $cell_tag || 'td' === $cell_tag ) {
					$cells[] = self::table_cell( self::children_to_md( $cell, $ctx ) );
				}
			}

			if ( ! empty( $cells ) ) {
				if ( $is_header ) {
					$has_header_row = true;
				}
				$parsed[] = array(
					'cells'     => $cells,
					'is_header' => $is_header,
				);
			}
		}

		if ( empty( $parsed ) ) {
			return '';
		}

		$col_count = max( array_map( static fn( array $r ): int => count( $r['cells'] ), $parsed ) );
		$separator = '| ' . implode( ' | ', array_fill( 0, $col_count, '---' ) ) . ' |';
		$md        = "\n\n";
		$sep_added = false;

		foreach ( $parsed as $i => $row ) {
			$cells = array_pad( $row['cells'], $col_count, '' );
			$md   .= '| ' . implode( ' | ', $cells ) . " |\n";

			if ( ! $sep_added && ( $row['is_header'] || ( ! $has_header_row && 0 === $i ) ) ) {
				$md       .= $separator . "\n";
				$sep_added = true;
			}
		}

		return $md . "\n";
	}

	/**
	 * Collapses content to a single trimmed line (headings, table cells).
	 *
	 * @param string $text Markdown fragment.
	 * @return string
	 */
	private static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/\s*\n\s*/', ' ', $text ) );
	}

	/**
	 * Wraps inline content in emphasis markers, hoisting edge whitespace outside.
	 *
	 * @param string $marker Emphasis marker (`**` or `*`).
	 * @param string $inner  Inner Markdown content.
	 * @return string
	 */
	private static function wrap_inline( string $marker, string $inner ): string {
		$trimmed = trim( $inner );
		if ( '' === $trimmed ) {
			return $inner;
		}
		$lead  = ltrim( $inner ) !== $inner ? ' ' : '';
		$trail = rtrim( $inner ) !== $inner ? ' ' : '';
		return $lead . $marker . $trimmed . $marker . $trail;
	}

	/**
	 * Sanitizes a table cell: newlines and pipes inside a cell break the row.
	 *
	 * @param string $text Cell Markdown content.
	 * @return string
	 */
	private static function table_cell( string $text ): string {
		return str_replace( '|', '\\|', self::single_line( $text ) );
	}

	/**
	 * Collects table rows including rows inside table sections.
	 *
	 * @param DOMElement $table Table element.
	 * @return DOMElement[]
	 */
	private static function collect_table_rows( DOMElement $table ): array {
		$rows = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		foreach ( $table->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			$tag = strtolower( $child->tagName );
			if ( 'tr' === $tag ) {
				$rows[] = $child;
			} elseif ( in_array( $tag, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
				foreach ( $child->childNodes as $tr ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
					if ( $tr instanceof DOMElement && 'tr' === strtolower( $tr->tagName ) ) {
						$rows[] = $tr;
					}
				}
			}
		}
		return $rows;
	}
}

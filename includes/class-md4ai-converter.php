<?php
/**
 * HTML to Markdown converter.
 *
 * @package WP_Botfood
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts HTML to Markdown using DOMDocument for correct handling of nested
 * structures (lists, tables) and Gutenberg block output.
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

		// Strip Gutenberg block comments before parsing.
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

		return trim( (string) $md );
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
			// Replace non-breaking space with a regular space.
			$text = str_replace( "\u{00A0}", ' ', $text );
			// Inside code/pre, preserve all whitespace.
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
				return "\n\n# " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";
			case 'h2':
				return "\n\n## " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";
			case 'h3':
				return "\n\n### " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";
			case 'h4':
				return "\n\n#### " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";
			case 'h5':
				return "\n\n##### " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";
			case 'h6':
				return "\n\n###### " . trim( self::children_to_md( $node, $ctx ) ) . "\n\n";

			case 'p':
				return trim( self::children_to_md( $node, $ctx ) ) . "\n\n";

			case 'strong':
			case 'b':
				return '**' . self::children_to_md( $node, $ctx ) . '**';

			case 'em':
			case 'i':
				return '*' . self::children_to_md( $node, $ctx ) . '*';

			case 'code':
				$code_ctx = array_merge( $ctx, array( 'in_code' => true ) );
				$code     = self::children_to_md( $node, $code_ctx );
				// Inside <pre>, the parent handles fencing; just return raw text.
				if ( ! empty( $ctx['in_pre'] ) ) {
					return $code;
				}
				return '`' . $code . '`';

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
				$alt = $node->getAttribute( 'alt' );
				return "![{$alt}]({$src})";

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'ul':
			case 'ol':
				return self::list_to_md( $node, $ctx, $ctx['list_depth'] ?? 0 );

			case 'blockquote':
				$inner  = trim( self::children_to_md( $node, $ctx ) );
				$lines  = explode( "\n", $inner );
				$quoted = array_map( static fn( string $l ): string => '> ' . $l, $lines );
				return "\n\n" . implode( "\n", $quoted ) . "\n\n";

			case 'table':
				return self::table_to_md( $node, $ctx );

			// Pass-through: render children without adding markup.
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
		$code_nodes = $node->getElementsByTagName( 'code' );
		if ( $code_nodes->length > 0 ) {
			$class = $code_nodes->item( 0 )->getAttribute( 'class' );
			if ( preg_match( '/(?:^|\s)language-(\w+)/', $class, $m ) ) {
				$lang = $m[1];
			}
		}
		$pre_ctx = array_merge(
			$ctx,
			array(
				'in_pre'  => true,
				'in_code' => true,
			)
		);
		$code    = self::children_to_md( $node, $pre_ctx );
		return "\n\n```{$lang}\n" . trim( $code ) . "\n```\n\n";
	}

	/**
	 * Converts a list to Markdown.
	 *
	 * @param DOMElement           $node  List element.
	 * @param array<string, mixed> $ctx   Conversion context.
	 * @param int                  $depth List depth.
	 * @return string
	 */
	private static function list_to_md( DOMElement $node, array $ctx, int $depth ): string {
		$indent   = str_repeat( '  ', $depth );
		$item_ctx = array_merge( $ctx, array( 'list_depth' => $depth + 1 ) );
		$out      = 0 === $depth ? "\n" : '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
		foreach ( $node->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			if ( ! ( $child instanceof DOMElement ) || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$item_text    = '';
			$nested_lists = '';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child instanceof DOMElement ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.
					$li_tag = strtolower( $li_child->tagName );
					if ( 'ul' === $li_tag || 'ol' === $li_tag ) {
						$nested_lists .= self::list_to_md( $li_child, $item_ctx, $depth + 1 );
						continue;
					}
				}
				$item_text .= self::node_to_md( $li_child, $item_ctx );
			}

			$out .= $indent . '- ' . trim( $item_text ) . "\n";
			if ( '' !== $nested_lists ) {
				$out .= $nested_lists;
			}
		}

		return $out . ( 0 === $depth ? "\n" : '' );
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
					$cells[] = trim( self::children_to_md( $cell, $ctx ) );
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

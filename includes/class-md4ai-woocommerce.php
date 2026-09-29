<?php
/**
 * WooCommerce product support.
 *
 * Entirely optional: every entry point is guarded, and the plugin behaves
 * exactly as it does without WooCommerce when WooCommerce is absent.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds product data to Markdown and OKF output for WooCommerce stores.
 */
class MD4AI_WooCommerce {

	/**
	 * Registers hooks. Safe to call unconditionally.
	 */
	public static function init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'md4ai_excluded_post_ids', array( __CLASS__, 'excluded_post_ids' ) );
		add_filter( 'md4ai_cache_ttl', array( __CLASS__, 'cache_ttl' ), 10, 2 );

		if ( md4ai_get_setting( 'product_details_enabled' ) ) {
			add_filter( 'md4ai_markdown_output', array( __CLASS__, 'append_product_details' ), 10, 2 );
		}
	}

	/**
	 * Shortens the cache lifetime for products.
	 *
	 * Price and stock change on their own schedule, and a stale price is the
	 * one error an agent will confidently repeat to a customer.
	 *
	 * Documents not tied to one post (llms.txt, the sitemap, the OKF index)
	 * apply the filter with no post ID, so it must stay optional — and 0 must
	 * not reach get_post(), which would resolve it to whatever global post
	 * the request happens to hold.
	 *
	 * @param int $ttl     Cache lifetime in seconds.
	 * @param int $post_id Post being cached, or 0 for a document spanning many.
	 * @return int
	 */
	public static function cache_ttl( int $ttl, int $post_id = 0 ): int {
		if ( $post_id <= 0 ) {
			return $ttl;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! self::is_product( $post ) ) {
			return $ttl;
		}

		$product_ttl = absint( md4ai_get_setting( 'product_cache_ttl' ) );

		return $product_ttl > 0 ? $product_ttl : $ttl;
	}

	/**
	 * Whether WooCommerce is active and its product API is available.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether a post is a WooCommerce product.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_product( WP_Post $post ): bool {
		return self::is_active() && 'product' === $post->post_type;
	}

	/**
	 * Whether a post will receive a product details section.
	 *
	 * Cheap on purpose: the OKF bundle build asks this for every product to
	 * decide membership. Any resolvable product has at least an availability
	 * row, so there is no need to build the table to know the answer.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function has_details( WP_Post $post ): bool {
		return (bool) md4ai_get_setting( 'product_details_enabled' ) && self::is_product( $post );
	}

	/**
	 * Returns the OKF concept type for a post.
	 *
	 * OKF v0.2 leaves type values uncentralized and requires consumers to
	 * tolerate unknown ones, so "Product" needs no spec extension.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $fallback Type the caller would otherwise use.
	 * @return string
	 */
	public static function okf_type( WP_Post $post, string $fallback ): string {
		return self::is_product( $post ) ? 'Product' : $fallback;
	}

	/**
	 * Returns an OKF "# Schema" section for a product.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Empty string for non-products.
	 */
	public static function okf_schema_section( WP_Post $post ): string {
		$details = self::details_section( $post );
		if ( '' === $details ) {
			return '';
		}

		// Inside an OKF concept "# Schema" is the section, so the table's own
		// headings move down one level to sit under it.
		$details = preg_replace( '/^(#{2,3}) /m', '#$1 ', $details );

		return '# ' . __( 'Schema', 'parseless' ) . "\n\n" . trim( (string) $details );
	}

	/**
	 * Returns WooCommerce's transactional page IDs, which are never worth
	 * serving as Markdown: without a session they render as empty shells.
	 *
	 * @param int[] $ids Existing excluded IDs.
	 * @return int[]
	 */
	public static function excluded_post_ids( array $ids ): array {
		$options = array(
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
		);

		foreach ( $options as $option ) {
			$page_id = absint( get_option( $option ) );
			if ( $page_id > 0 ) {
				$ids[] = $page_id;
			}
		}

		return $ids;
	}

	/**
	 * Appends a product details table to a product's Markdown.
	 *
	 * @param string  $markdown Markdown produced so far.
	 * @param WP_Post $post     Post being served.
	 * @return string
	 */
	public static function append_product_details( string $markdown, WP_Post $post ): string {
		$section = self::details_section( $post );
		if ( '' === $section ) {
			return $markdown;
		}

		return rtrim( $markdown ) . "\n\n" . $section;
	}

	/**
	 * Builds the product details block: the fields an agent actually asks
	 * about, as a Markdown table, plus a variations table where relevant.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Empty string when the post is not a resolvable product.
	 */
	public static function details_section( WP_Post $post ): string {
		if ( ! self::has_details( $post ) ) {
			return '';
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$rows = self::detail_rows( $product );
		if ( empty( $rows ) ) {
			return '';
		}

		$out  = '## ' . __( 'Product details', 'parseless' ) . "\n\n";
		$out .= '| ' . __( 'Field', 'parseless' ) . ' | ' . __( 'Value', 'parseless' ) . " |\n";
		$out .= "| --- | --- |\n";
		foreach ( $rows as $label => $value ) {
			$out .= '| ' . self::cell( (string) $label ) . ' | ' . self::cell( (string) $value ) . " |\n";
		}

		$variations = self::variations_table( $product );
		if ( '' !== $variations ) {
			$out .= "\n" . $variations;
		}

		return (string) apply_filters( 'md4ai_product_details', rtrim( $out ), $product );
	}

	/**
	 * Collects the label => value pairs for the details table.
	 *
	 * Prices carry both a formatted string and the currency code so an agent
	 * gets something readable and something parseable.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string, string>
	 */
	private static function detail_rows( WC_Product $product ): array {
		$rows = array();

		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$rows[ __( 'SKU', 'parseless' ) ] = $sku;
		}

		$rows = array_merge( $rows, self::price_rows( $product ) );

		$rows[ __( 'Availability', 'parseless' ) ] = self::stock_label( $product );

		if ( $product->managing_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( null !== $qty ) {
				$rows[ __( 'Stock quantity', 'parseless' ) ] = (string) (int) $qty;
			}
		}

		$categories = self::term_names( $product->get_id(), 'product_cat' );
		if ( '' !== $categories ) {
			$rows[ __( 'Categories', 'parseless' ) ] = $categories;
		}

		foreach ( self::attribute_rows( $product ) as $label => $value ) {
			$rows[ $label ] = $value;
		}

		return $rows;
	}

	/**
	 * Collects the price, sale and tax rows.
	 *
	 * The tax row says which way the prices lean so an agent quoting them
	 * can say so too; it is omitted when tax does not apply to the product.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string, string>
	 */
	private static function price_rows( WC_Product $product ): array {
		$rows = array();

		$price = self::money( $product, $product->get_price() );
		if ( '' === $price ) {
			return $rows;
		}
		$rows[ __( 'Price', 'parseless' ) ] = $price;

		if ( $product->is_on_sale() ) {
			$regular = self::money( $product, $product->get_regular_price() );
			if ( '' !== $regular ) {
				$rows[ __( 'Regular price', 'parseless' ) ] = $regular;
				$rows[ __( 'On sale', 'parseless' ) ]       = __( 'Yes', 'parseless' );
			}
		}

		if ( $product->is_taxable() ) {
			$rows[ __( 'Tax', 'parseless' ) ] = self::display_includes_tax()
				? __( 'Prices include tax', 'parseless' )
				: __( 'Prices exclude tax', 'parseless' );
		}

		return $rows;
	}

	/**
	 * Formats a price as "CUR 12.34" the way the storefront displays it, or
	 * '' when there is no price.
	 *
	 * WooCommerce stores prices with or without tax and displays them with or
	 * without tax, independently: a shop that enters 100 and shows 120 would
	 * otherwise have its Markdown quote 100. The shop's base rates are used
	 * deliberately — the storefront also varies by customer address or
	 * geolocation, and a cached public document must not.
	 *
	 * @param WC_Product $product Product the price belongs to, for its tax class.
	 * @param mixed      $amount  Raw stored price.
	 * @return string
	 */
	private static function money( WC_Product $product, mixed $amount ): string {
		if ( '' === (string) $amount ) {
			return '';
		}

		$amount = (float) $amount;
		if ( $product->is_taxable() && wc_prices_include_tax() !== self::display_includes_tax() ) {
			$rates = WC_Tax::get_base_tax_rates( $product->get_tax_class() );
			$taxes = WC_Tax::calc_tax( $amount, $rates, wc_prices_include_tax() );
			// Rounded per rate unless the shop rounds at subtotal — the same
			// rule wc_get_price_to_display() follows, so multi-rate prices
			// land on the storefront's cent.
			if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
				$taxes = array_map( 'wc_round_tax_total', $taxes );
			}
			$amount += wc_prices_include_tax() ? -array_sum( $taxes ) : array_sum( $taxes );
		}

		return get_woocommerce_currency() . ' ' . wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	/**
	 * Whether the shop displays prices including tax.
	 *
	 * @return bool
	 */
	private static function display_includes_tax(): bool {
		return 'incl' === get_option( 'woocommerce_tax_display_shop' );
	}

	/**
	 * Returns a human-readable availability label.
	 *
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private static function stock_label( WC_Product $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'instock':
				return __( 'In stock', 'parseless' );
			case 'onbackorder':
				return __( 'On backorder', 'parseless' );
			default:
				return __( 'Out of stock', 'parseless' );
		}
	}

	/**
	 * Returns a product's attributes as label => comma-separated values.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string, string>
	 */
	private static function attribute_rows( WC_Product $product ): array {
		$rows = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$label = wc_attribute_label( $attribute->get_name(), $product );

			if ( $attribute->is_taxonomy() ) {
				$values = self::term_names( $product->get_id(), $attribute->get_name() );
			} else {
				$values = implode( ', ', array_map( 'md4ai_plain_text', $attribute->get_options() ) );
			}

			if ( '' !== $values ) {
				$rows[ md4ai_plain_text( $label ) ] = $values;
			}
		}

		return $rows;
	}

	/**
	 * Returns a post's term names in a taxonomy as a comma-separated string.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private static function term_names( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return '';
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = md4ai_plain_text( $term->name );
		}

		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * Escapes a value for use inside a Markdown table cell.
	 *
	 * Pipes would break the table and newlines would break the row, so both
	 * are neutralised rather than dropped.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function cell( string $value ): string {
		$value = str_replace( '|', '\|', $value );
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Builds a table of purchasable variations.
	 *
	 * Capped: a product with hundreds of variations would dominate the response
	 * and defeat the point of serving Markdown in the first place. When the cap
	 * bites, the output says so rather than silently truncating.
	 *
	 * @param WC_Product $product Product object.
	 * @return string Empty string for non-variable products.
	 */
	private static function variations_table( WC_Product $product ): string {
		if ( ! $product instanceof WC_Product_Variable ) {
			return '';
		}

		$cap        = max( 1, (int) apply_filters( 'md4ai_max_variations', 25 ) );
		$variations = $product->get_available_variations();
		$total      = count( $variations );
		if ( 0 === $total ) {
			return '';
		}

		$out  = '### ' . __( 'Variations', 'parseless' ) . "\n\n";
		$out .= '| ' . __( 'Options', 'parseless' ) . ' | ' . __( 'SKU', 'parseless' ) . ' | ' . __( 'Price', 'parseless' ) . ' | ' . __( 'Availability', 'parseless' ) . " |\n";
		$out .= "| --- | --- | --- | --- |\n";

		$shown = 0;
		foreach ( $variations as $variation ) {
			if ( $shown >= $cap ) {
				break;
			}

			$child = wc_get_product( (int) ( $variation['variation_id'] ?? 0 ) );
			if ( ! $child instanceof WC_Product ) {
				continue;
			}

			$options = array();
			foreach ( (array) ( $variation['attributes'] ?? array() ) as $value ) {
				if ( '' !== (string) $value ) {
					$options[] = md4ai_plain_text( (string) $value );
				}
			}

			$out .= '| ' . self::cell( implode( ' / ', $options ) )
				. ' | ' . self::cell( (string) $child->get_sku() )
				. ' | ' . self::cell( self::money( $child, $child->get_price() ) )
				. ' | ' . self::cell( self::stock_label( $child ) )
				. " |\n";

			++$shown;
		}

		if ( $total > $shown ) {
			$out .= "\n" . sprintf(
				/* translators: 1: number of variations shown, 2: total number of variations */
				__( 'Showing %1$d of %2$d variations.', 'parseless' ),
				$shown,
				$total
			) . "\n";
		}

		return $out;
	}
}

<?php
/**
 * Admin settings page.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the plugin settings page.
 */
class MD4AI_Settings {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Registers the Tools submenu page.
	 */
	public static function register_menu(): void {
		add_management_page(
			__( 'ParseLess Settings', 'parseless' ),
			__( 'ParseLess', 'parseless' ),
			'manage_options',
			'parseless',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handles settings form submissions.
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST['_md4ai_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_md4ai_nonce'] ), 'md4ai_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'parseless' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaults  = md4ai_get_default_settings();
		$all_types = md4ai_supported_post_types();

		$detection_mode     = isset( $_POST['detection_mode'] ) ? sanitize_key( wp_unslash( $_POST['detection_mode'] ) ) : '';
		$query_param_name   = isset( $_POST['query_param_name'] ) ? sanitize_key( wp_unslash( $_POST['query_param_name'] ) ) : $defaults['query_param_name'];
		$cache_ttl          = isset( $_POST['cache_ttl'] ) ? absint( wp_unslash( $_POST['cache_ttl'] ) ) : 0;
		$enabled_post_types = isset( $_POST['enabled_post_types'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['enabled_post_types'] ) ) : array();
		$llmstxt_max_posts  = isset( $_POST['llmstxt_max_posts'] ) ? absint( wp_unslash( $_POST['llmstxt_max_posts'] ) ) : $defaults['llmstxt_max_posts'];
		$retention_days     = isset( $_POST['log_retention_days'] ) ? absint( wp_unslash( $_POST['log_retention_days'] ) ) : $defaults['log_retention_days'];
		$signal_values      = array( 'yes', 'no', 'omit' );
		$signal_search      = isset( $_POST['content_signal_search'] ) ? sanitize_key( wp_unslash( $_POST['content_signal_search'] ) ) : '';
		$signal_input       = isset( $_POST['content_signal_ai_input'] ) ? sanitize_key( wp_unslash( $_POST['content_signal_ai_input'] ) ) : '';
		$signal_train       = isset( $_POST['content_signal_ai_train'] ) ? sanitize_key( wp_unslash( $_POST['content_signal_ai_train'] ) ) : '';
		$rsl_permits        = isset( $_POST['rsl_permits'] ) ? sanitize_text_field( wp_unslash( $_POST['rsl_permits'] ) ) : '';
		$rsl_payment        = isset( $_POST['rsl_payment_type'] ) ? sanitize_key( wp_unslash( $_POST['rsl_payment_type'] ) ) : '';
		$product_details    = isset( $_POST['product_details_enabled'] );
		$product_ttl        = isset( $_POST['product_cache_ttl'] ) ? absint( wp_unslash( $_POST['product_cache_ttl'] ) ) : 0;
		if ( ! MD4AI_WooCommerce::is_active() ) {
			// The WooCommerce section only renders when Woo is active; its
			// absent fields must not silently reset the saved values.
			$product_details = (bool) md4ai_get_setting( 'product_details_enabled' );
			$product_ttl     = absint( md4ai_get_setting( 'product_cache_ttl' ) );
		}
		// RSL 1.0 vocabulary: usage tokens for <permits>, payment types for
		// <payment>. "none" is our own sentinel that emits <prohibits>.
		$permit_tokens = array( 'all', 'train-ai', 'ai-use', 'search', 'none' );
		$payment_types = array( 'free', 'attribution', 'subscription', 'use', 'training' );

		$settings = array(
			'detection_mode'              => in_array( $detection_mode, array( 'both', 'query_param', 'ua' ), true )
				? $detection_mode
				: $defaults['detection_mode'],
			'query_param_name'            => $query_param_name,
			'bot_list'                    => sanitize_textarea_field( wp_unslash( $_POST['bot_list'] ?? '' ) ),
			'blocked_bots'                => sanitize_textarea_field( wp_unslash( $_POST['blocked_bots'] ?? '' ) ),
			'rate_limit_enabled'          => isset( $_POST['rate_limit_enabled'] ),
			'rate_limit_per_minute'       => isset( $_POST['rate_limit_per_minute'] ) ? max( 1, min( 10000, absint( wp_unslash( $_POST['rate_limit_per_minute'] ) ) ) ) : $defaults['rate_limit_per_minute'],
			'cache_ttl'                   => in_array( $cache_ttl, array( HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, WEEK_IN_SECONDS ), true )
					? $cache_ttl
					: $defaults['cache_ttl'],
			'enabled_post_types'          => array_values(
				array_intersect(
					$enabled_post_types,
					$all_types
				)
			),
			'llmstxt_enabled'             => isset( $_POST['llmstxt_enabled'] ),
			// Clamped to the number fields' rendered min/max: an emptied field
			// submits "", and absint('') = 0 would silently list zero posts.
			'llmstxt_max_posts'           => max( 1, min( 1000, $llmstxt_max_posts ) ),
			'sitemap_enabled'             => isset( $_POST['sitemap_enabled'] ),
			'okf_enabled'                 => isset( $_POST['okf_enabled'] ),
			'okf_endpoint'                => isset( $_POST['okf_endpoint'] ) ? sanitize_title( wp_unslash( $_POST['okf_endpoint'] ) ) : $defaults['okf_endpoint'],
			'okf_max_posts'               => isset( $_POST['okf_max_posts'] ) ? max( 1, min( 5000, absint( wp_unslash( $_POST['okf_max_posts'] ) ) ) ) : $defaults['okf_max_posts'],
			'include_frontmatter'         => isset( $_POST['include_frontmatter'] ),
			'include_schema'              => isset( $_POST['include_schema'] ),
			'enable_logging'              => isset( $_POST['enable_logging'] ),
			'log_retention_days'          => in_array( $retention_days, array( 7, 30, 90, 365 ), true )
				? $retention_days
				: $defaults['log_retention_days'],
			'log_unknown_uas'             => isset( $_POST['log_unknown_uas'] ),
			'log_all_bot_traffic'         => isset( $_POST['log_all_bot_traffic'] ),
			'accept_negotiation'          => isset( $_POST['accept_negotiation'] ),
			'md_suffix_enabled'           => isset( $_POST['md_suffix_enabled'] ),
			'link_header_enabled'         => isset( $_POST['link_header_enabled'] ),
			'html_link_tag_enabled'       => isset( $_POST['html_link_tag_enabled'] ),
			'content_signal_search'       => in_array( $signal_search, $signal_values, true ) ? $signal_search : $defaults['content_signal_search'],
			'content_signal_ai_input'     => in_array( $signal_input, $signal_values, true ) ? $signal_input : $defaults['content_signal_ai_input'],
			'content_signal_ai_train'     => in_array( $signal_train, $signal_values, true ) ? $signal_train : $defaults['content_signal_ai_train'],
			'content_signals_boilerplate' => isset( $_POST['content_signals_boilerplate'] ),
			'robots_ai_rules'             => isset( $_POST['robots_ai_rules'] ),
			'rsl_enabled'                 => isset( $_POST['rsl_enabled'] ),
			'rsl_permits'                 => in_array( $rsl_permits, $permit_tokens, true ) ? $rsl_permits : $defaults['rsl_permits'],
			'rsl_payment_type'            => in_array( $rsl_payment, $payment_types, true ) ? $rsl_payment : $defaults['rsl_payment_type'],
			'product_details_enabled'     => $product_details,
			'product_cache_ttl'           => $product_ttl > 0 ? $product_ttl : $defaults['product_cache_ttl'],
		);

		if ( '' === $settings['query_param_name'] ) {
			$settings['query_param_name'] = $defaults['query_param_name'];
		}
		if ( '' === $settings['okf_endpoint'] ) {
			$settings['okf_endpoint'] = $defaults['okf_endpoint'];
		}

		update_option( 'md4ai_settings', $settings );

		// Cached documents embed setting-dependent output: the OKF bundle URL
		// in llms.txt, the query param name in sitemap URLs, and the
		// frontmatter/schema toggles in every per-post Markdown body.
		MD4AI_LLMsTxt::clear_cache();
		MD4AI_OKF::purge_all();
		MD4AI_RSL::clear_cache();
		MD4AI_Sitemap::clear_cache();
		MD4AI_Cache::purge_all();

		add_settings_error( 'md4ai', 'md4ai_saved', __( 'Settings saved.', 'parseless' ), 'updated' );
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		settings_errors( 'md4ai' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'analytics' ), true ) ) {
			$tab = 'settings';
		}

		$base = admin_url( 'tools.php?page=parseless' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ParseLess', 'parseless' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'parseless' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'analytics', $base ) ); ?>"
					class="nav-tab <?php echo 'analytics' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Analytics', 'parseless' ); ?>
				</a>
			</nav>
			<?php
			if ( 'analytics' === $tab ) {
				MD4AI_Analytics::render();
			} else {
				self::render_settings_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the settings form (extracted from the legacy single-page render).
	 */
	public static function render_settings_form(): void {
		$s                 = md4ai_get_settings();
		$all_types         = md4ai_supported_post_types();
		$ttl_options       = array(
			HOUR_IN_SECONDS     => __( '1 Hour', 'parseless' ),
			6 * HOUR_IN_SECONDS => __( '6 Hours', 'parseless' ),
			DAY_IN_SECONDS      => __( '1 Day', 'parseless' ),
			WEEK_IN_SECONDS     => __( '1 Week', 'parseless' ),
		);
		$retention_options = array(
			7   => __( '7 days', 'parseless' ),
			30  => __( '30 days', 'parseless' ),
			90  => __( '90 days', 'parseless' ),
			365 => __( '1 year', 'parseless' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=parseless' ) ); ?>">
			<?php wp_nonce_field( 'md4ai_save_settings', '_md4ai_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Detection Mode', 'parseless' ); ?></th>
					<td>
						<?php
						$modes = array(
							'both'        => __( 'Query param + User-Agent (default)', 'parseless' ),
							'query_param' => __( 'Query param only', 'parseless' ),
							'ua'          => __( 'User-Agent only', 'parseless' ),
						);
						foreach ( $modes as $val => $label ) :
							?>
							<label>
								<input type="radio" name="detection_mode" value="<?php echo esc_attr( $val ); ?>"
									<?php checked( $s['detection_mode'], $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_query_param_name"><?php esc_html_e( 'Query Param Name', 'parseless' ); ?></label></th>
					<td>
						<input type="text" id="md4ai_query_param_name" name="query_param_name"
							value="<?php echo esc_attr( $s['query_param_name'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'The URL parameter name. Default is "format" (so ?format=md). Change only if it conflicts with a theme or plugin.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_bot_list"><?php esc_html_e( 'Bot List', 'parseless' ); ?></label></th>
					<td>
						<textarea id="md4ai_bot_list" name="bot_list" rows="10" class="large-text code"><?php echo esc_textarea( $s['bot_list'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One User-Agent substring per line. Leave blank to use the built-in defaults (GPTBot, ClaudeBot, etc.).', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_blocked_bots"><?php esc_html_e( 'Blocked Bots', 'parseless' ); ?></label></th>
					<td>
						<textarea id="md4ai_blocked_bots" name="blocked_bots" rows="5" class="large-text code"><?php echo esc_textarea( $s['blocked_bots'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One User-Agent substring per line. Matching crawlers get a 403 instead of Markdown. Empty by default — blocks nothing. Does not affect normal HTML browsing.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_rate_limit_per_minute"><?php esc_html_e( 'Rate Limit', 'parseless' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" name="rate_limit_enabled" value="1" <?php checked( (bool) $s['rate_limit_enabled'] ); ?>>
							<?php esc_html_e( 'Throttle each AI crawler to at most', 'parseless' ); ?>
						</label>
						<input type="number" id="md4ai_rate_limit_per_minute" name="rate_limit_per_minute" min="1" max="10000" step="1" class="small-text" value="<?php echo esc_attr( (string) $s['rate_limit_per_minute'] ); ?>">
						<?php esc_html_e( 'requests per minute', 'parseless' ); ?>
						<p class="description"><?php esc_html_e( 'Off by default. Applies to known bots on Markdown, llms-full.txt and the OKF bundle; over the limit they get a 429 with a Retry-After header. Never applies to people using ?format=md, and never to normal HTML browsing.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_cache_ttl"><?php esc_html_e( 'Cache TTL', 'parseless' ); ?></label></th>
					<td>
						<select id="md4ai_cache_ttl" name="cache_ttl">
							<?php foreach ( $ttl_options as $seconds => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $seconds ); ?>" <?php selected( (int) $s['cache_ttl'], $seconds ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled Post Types', 'parseless' ); ?></th>
					<td>
						<?php foreach ( $all_types as $type ) : ?>
							<label>
								<input type="checkbox" name="enabled_post_types[]"
									value="<?php echo esc_attr( $type ); ?>"
									<?php checked( empty( $s['enabled_post_types'] ) || in_array( $type, (array) $s['enabled_post_types'], true ) ); ?>>
								<?php echo esc_html( $type ); ?>
							</label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Uncheck post types you do not want served as Markdown. All are enabled by default — clearing every checkbox restores that default rather than disabling serving.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'llms.txt', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="llmstxt_enabled" value="1" <?php checked( (bool) $s['llmstxt_enabled'] ); ?>>
							<?php esc_html_e( 'Enable the /llms.txt and /llms-full.txt endpoints', 'parseless' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'llms.txt is an index of your posts; llms-full.txt concatenates their full Markdown content (capped at 500KB).', 'parseless' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_llmstxt_max_posts"><?php esc_html_e( 'llms.txt Max Posts', 'parseless' ); ?></label></th>
					<td>
						<input type="number" id="md4ai_llmstxt_max_posts" name="llmstxt_max_posts" min="1" max="1000"
							value="<?php echo esc_attr( (string) (int) $s['llmstxt_max_posts'] ); ?>" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'AI sitemap', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sitemap_enabled" value="1" <?php checked( (bool) $s['sitemap_enabled'] ); ?>>
							<?php esc_html_e( 'Expose /botfood-sitemap.xml and advertise it in robots.txt', 'parseless' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Lists the Markdown version of every public post for AI crawlers.', 'parseless' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OKF bundle', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="okf_enabled" value="1" <?php checked( (bool) $s['okf_enabled'] ); ?>>
							<?php
							printf(
								/* translators: %s: the bundle URL, e.g. https://example.com/okf/ */
								esc_html__( 'Serve an Open Knowledge Format (OKF) bundle at %s', 'parseless' ),
								'<code>' . esc_html( MD4AI_OKF::bundle_url() ) . '</code>'
							);
							?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: OKF spec version, e.g. 0.2 */
								esc_html__( 'Exposes your content as a Google OKF v%s bundle for AI agents: an index, a changelog, and one cross-linked Markdown file per post (also reachable via ?format=okf). Experimental — few agents crawl OKF bundles yet.', 'parseless' ),
								esc_html( MD4AI_OKF::version() )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_okf_endpoint"><?php esc_html_e( 'OKF Endpoint', 'parseless' ); ?></label></th>
					<td>
						<input type="text" id="md4ai_okf_endpoint" name="okf_endpoint"
							value="<?php echo esc_attr( (string) $s['okf_endpoint'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'The URL path the bundle is served under. Default is "okf" (so /okf/). Change it if it clashes with an existing page or post.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_okf_max_posts"><?php esc_html_e( 'OKF Max Posts', 'parseless' ); ?></label></th>
					<td>
						<input type="number" id="md4ai_okf_max_posts" name="okf_max_posts" min="1" max="5000"
							value="<?php echo esc_attr( (string) (int) $s['okf_max_posts'] ); ?>" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Include Frontmatter', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="include_frontmatter" value="1" <?php checked( (bool) $s['include_frontmatter'] ); ?>>
							<?php esc_html_e( 'Add YAML frontmatter at the top of every Markdown output', 'parseless' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Include Schema.org Block', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="include_schema" value="1" <?php checked( (bool) $s['include_schema'] ); ?>>
							<?php esc_html_e( 'Append a schema.org Article JSON block to every Markdown output', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Gives AI systems structured metadata (title, author, dates, featured image) derived from the post itself.', 'parseless' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Agent discovery', 'parseless' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'How AI agents find and request your Markdown. These follow the conventions Cloudflare and the llms.txt ecosystem have settled on.', 'parseless' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Accept negotiation', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="accept_negotiation" value="1" <?php checked( (bool) $s['accept_negotiation'] ); ?>>
							<?php esc_html_e( 'Serve Markdown to clients that send Accept: text/markdown', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The standard way agents ask for Markdown. Browsers and curl are unaffected — only clients that explicitly prefer Markdown get it.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '.md URLs', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="md_suffix_enabled" value="1" <?php checked( (bool) $s['md_suffix_enabled'] ); ?>>
							<?php esc_html_e( 'Serve Markdown at /your-post.md as well as /your-post/', 'parseless' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Link header', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="link_header_enabled" value="1" <?php checked( (bool) $s['link_header_enabled'] ); ?>>
							<?php esc_html_e( 'Advertise the Markdown version in an HTTP Link header', 'parseless' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'HTML link tag', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="html_link_tag_enabled" value="1" <?php checked( (bool) $s['html_link_tag_enabled'] ); ?>>
							<?php esc_html_e( 'Add <link rel="alternate" type="text/markdown"> to your page head', 'parseless' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Your terms', 'parseless' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Declare in robots.txt how AI systems may use your content. These are declarations, not enforcement — use the Blocked Bots list above for that.', 'parseless' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php
				$signal_labels  = array(
					'content_signal_search'   => __( 'Search indexing', 'parseless' ),
					'content_signal_ai_input' => __( 'AI answers (ai-input)', 'parseless' ),
					'content_signal_ai_train' => __( 'AI training (ai-train)', 'parseless' ),
				);
				$signal_choices = array(
					'yes'  => __( 'Allow', 'parseless' ),
					'no'   => __( 'Disallow', 'parseless' ),
					'omit' => __( 'Say nothing', 'parseless' ),
				);
				foreach ( $signal_labels as $key => $label ) :
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<?php foreach ( $signal_choices as $val => $choice ) : ?>
								<label style="margin-right:12px;">
									<input type="radio" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>"
										<?php checked( $s[ $key ], $val ); ?>>
									<?php echo esc_html( $choice ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Policy text', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="content_signals_boilerplate" value="1" <?php checked( (bool) $s['content_signals_boilerplate'] ); ?>>
							<?php esc_html_e( 'Include the Content Signals policy text in robots.txt', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds a comment block explaining what each signal means and stating that restrictions are an express reservation of rights. Recommended if you disallow anything.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'AI bot rules', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="robots_ai_rules" value="1" <?php checked( (bool) $s['robots_ai_rules'] ); ?>>
							<?php esc_html_e( 'Declare your Blocked Bots list in robots.txt as Disallow rules', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Blocked crawlers already get a 403. This tells the polite ones up front, so they never fetch at all.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'RSL license', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="rsl_enabled" value="1" <?php checked( (bool) $s['rsl_enabled'] ); ?>>
							<?php
							printf(
								/* translators: %s: the license file URL, e.g. https://example.com/license.xml */
								esc_html__( 'Publish machine-readable license terms at %s', 'parseless' ),
								'<code>' . esc_html( MD4AI_RSL::license_url() ) . '</code>'
							);
							?>
						</label>
						<p class="description"><?php esc_html_e( 'Really Simple Licensing (RSL) is an emerging standard for stating your terms in a form AI companies can read. Declaration only — no payment handling.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_rsl_permits"><?php esc_html_e( 'Permitted use', 'parseless' ); ?></label></th>
					<td>
						<select id="md4ai_rsl_permits" name="rsl_permits">
							<?php
							$permit_labels = array(
								'all'      => __( 'Everything', 'parseless' ),
								'search'   => __( 'Search indexing only', 'parseless' ),
								'ai-use'   => __( 'AI answers only', 'parseless' ),
								'train-ai' => __( 'AI training', 'parseless' ),
								'none'     => __( 'Nothing without a licence', 'parseless' ),
							);
							foreach ( $permit_labels as $val => $label ) :
								?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $s['rsl_permits'], $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_rsl_payment_type"><?php esc_html_e( 'On what terms', 'parseless' ); ?></label></th>
					<td>
						<select id="md4ai_rsl_payment_type" name="rsl_payment_type">
							<?php
							$payment_labels = array(
								'free'         => __( 'Free', 'parseless' ),
								'attribution'  => __( 'Free with attribution', 'parseless' ),
								'subscription' => __( 'Subscription — contact me', 'parseless' ),
								'use'          => __( 'Per-use payment — contact me', 'parseless' ),
								'training'     => __( 'Paid training licence — contact me', 'parseless' ),
							);
							foreach ( $payment_labels as $val => $label ) :
								?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $s['rsl_payment_type'], $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<?php if ( MD4AI_WooCommerce::is_active() ) : ?>
			<h2><?php esc_html_e( 'WooCommerce', 'parseless' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Products already appear in your Markdown output, but without price, SKU or stock an agent cannot answer the question customers actually ask.', 'parseless' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Product details', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="product_details_enabled" value="1" <?php checked( (bool) $s['product_details_enabled'] ); ?>>
							<?php esc_html_e( 'Include price, SKU, stock, categories and attributes in product output', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Cart, checkout and account pages are always excluded.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_product_cache_ttl"><?php esc_html_e( 'Product cache lifetime', 'parseless' ); ?></label></th>
					<td>
						<input type="number" id="md4ai_product_cache_ttl" name="product_cache_ttl" class="small-text"
							min="60" step="60" value="<?php echo esc_attr( (string) absint( $s['product_cache_ttl'] ) ); ?>">
						<?php esc_html_e( 'seconds', 'parseless' ); ?>
						<p class="description"><?php esc_html_e( 'Shorter than the general cache lifetime, because prices and stock change. One hour by default.', 'parseless' ); ?></p>
					</td>
				</tr>
			</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Logging', 'parseless' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Logging', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_logging" value="1" <?php checked( (bool) $s['enable_logging'] ); ?>>
							<?php esc_html_e( 'Log markdown requests to a custom database table', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, ParseLess records who fetched what. IP addresses are stored as salted SHA-256 hashes only.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="md4ai_log_retention_days"><?php esc_html_e( 'Log Retention', 'parseless' ); ?></label></th>
					<td>
						<select id="md4ai_log_retention_days" name="log_retention_days">
							<?php foreach ( $retention_options as $days => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( (int) $s['log_retention_days'], $days ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Log rows older than this are removed by a daily cron job.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log Unknown UAs', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="log_unknown_uas" value="1" <?php checked( (bool) $s['log_unknown_uas'] ); ?>>
							<?php esc_html_e( 'Record bot-like User-Agents that did not match the configured list', 'parseless' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log All AI Crawler Traffic', 'parseless' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="log_all_bot_traffic" value="1" <?php checked( (bool) $s['log_all_bot_traffic'] ); ?>>
							<?php esc_html_e( 'Also record known AI crawlers when they receive the normal HTML page', 'parseless' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Shows which AI bots actually reach your site and when each was last seen — useful for spotting a host that blocks them before WordPress runs. Pages served by a full-page cache or CDN never reach PHP and cannot be recorded.', 'parseless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Purge Logs', 'parseless' ); ?></th>
					<td>
						<button type="button" class="button" id="md4ai-purge-logs"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'md4ai_purge_logs' ) ); ?>">
							<?php esc_html_e( 'Delete all logged requests', 'parseless' ); ?>
						</button>
						<span id="md4ai-purge-status" aria-live="polite" style="margin-left:8px;"></span>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'parseless' ) ); ?>
		</form>
		<?php
	}
}

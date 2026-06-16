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

		$settings = array(
			'detection_mode'      => in_array( $detection_mode, array( 'both', 'query_param', 'ua' ), true )
				? $detection_mode
				: $defaults['detection_mode'],
			'query_param_name'    => $query_param_name,
			'bot_list'            => sanitize_textarea_field( wp_unslash( $_POST['bot_list'] ?? '' ) ),
			'cache_ttl'           => in_array( $cache_ttl, array( HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, WEEK_IN_SECONDS ), true )
					? $cache_ttl
					: $defaults['cache_ttl'],
			'enabled_post_types'  => array_values(
				array_intersect(
					$enabled_post_types,
					$all_types
				)
			),
			'llmstxt_enabled'     => isset( $_POST['llmstxt_enabled'] ),
			'llmstxt_max_posts'   => $llmstxt_max_posts,
			'sitemap_enabled'     => isset( $_POST['sitemap_enabled'] ),
			'include_frontmatter' => isset( $_POST['include_frontmatter'] ),
			'include_schema'      => isset( $_POST['include_schema'] ),
			'enable_logging'      => isset( $_POST['enable_logging'] ),
			'log_retention_days'  => in_array( $retention_days, array( 7, 30, 90, 365 ), true )
				? $retention_days
				: $defaults['log_retention_days'],
			'log_unknown_uas'     => isset( $_POST['log_unknown_uas'] ),
		);

		if ( '' === $settings['query_param_name'] ) {
			$settings['query_param_name'] = $defaults['query_param_name'];
		}

		update_option( 'md4ai_settings', $settings );
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
						<p class="description"><?php esc_html_e( 'Uncheck post types you do not want served as Markdown. All are enabled by default.', 'parseless' ); ?></p>
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

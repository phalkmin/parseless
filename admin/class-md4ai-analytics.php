<?php
/**
 * Analytics tab on the ParseLess settings page.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders aggregated traffic data and handles tab-only AJAX endpoints.
 */
class MD4AI_Analytics {

	public const AJAX_PURGE_LOGS   = 'md4ai_purge_logs';
	public const AJAX_ADD_TO_LIST  = 'md4ai_add_ua_to_list';
	public const ACTION_EXPORT_CSV = 'md4ai_export_csv';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_PURGE_LOGS, array( __CLASS__, 'ajax_purge_logs' ) );
		add_action( 'wp_ajax_' . self::AJAX_ADD_TO_LIST, array( __CLASS__, 'ajax_add_ua_to_list' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT_CSV, array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_inline_script' ) );
	}

	/**
	 * Renders the analytics tab body.
	 */
	public static function render(): void {
		if ( ! md4ai_get_setting( 'enable_logging' ) ) {
			$url = add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=parseless' ) );
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings tab URL */
					__( 'Logging is currently disabled. <a href="%s">Enable it under the Settings tab</a> to start collecting data.', 'parseless' ),
					esc_url( $url )
				)
			) . '</p>';
			return;
		}

		$totals = array(
			1  => MD4AI_Stats::total_requests( 1 ),
			7  => MD4AI_Stats::total_requests( 7 ),
			30 => MD4AI_Stats::total_requests( 30 ),
		);

		echo '<h2>' . esc_html__( 'Summary', 'parseless' ) . '</h2>';
		echo '<p>';
		printf(
			/* translators: 1: last 24h, 2: last 7d, 3: last 30d */
			esc_html__( '%1$d requests (24h) · %2$d requests (7d) · %3$d requests (30d)', 'parseless' ),
			(int) $totals[1],
			(int) $totals[7],
			(int) $totals[30]
		);
		echo '</p>';

		$bots = MD4AI_Stats::top_bots( 30, 500 );
		self::render_kpis( MD4AI_Bots::summarize( $bots ) );
		self::render_bot_chart();
		self::render_reachability_table( MD4AI_Stats::bot_reachability( 30 ) );
		self::render_bot_table( array_slice( $bots, 0, 50 ) );
		self::render_post_table( MD4AI_Stats::top_posts( 30, 50 ) );
		self::render_url_table( MD4AI_Stats::top_urls( 30, 50 ) );
		self::render_unknown_ua_table( MD4AI_Stats::unknown_uas( 30, 50 ) );
		self::render_export_form();
	}

	/**
	 * Renders the "who is reading" KPI cards and the platform / purpose
	 * breakdowns derived from the bot taxonomy.
	 *
	 * @param array{chat_impressions:int, distinct_crawlers:int, by_company:array<string,int>, by_purpose:array<string,int>} $summary Output of MD4AI_Bots::summarize().
	 */
	private static function render_kpis( array $summary ): void {
		echo '<h2>' . esc_html__( 'Who is reading (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $summary['by_purpose'] ) ) {
			echo '<p>' . esc_html__( 'No crawler traffic recorded yet.', 'parseless' ) . '</p>';
			return;
		}

		echo '<div class="md4ai-kpis" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">';
		self::render_kpi_card( __( 'Chat impressions', 'parseless' ), (int) $summary['chat_impressions'], __( 'Pages fetched live while an AI assistant answered someone.', 'parseless' ) );
		self::render_kpi_card( __( 'Distinct crawlers', 'parseless' ), (int) $summary['distinct_crawlers'], __( 'Known AI bots seen at least once.', 'parseless' ) );
		echo '</div>';

		echo '<div style="display:flex;gap:32px;flex-wrap:wrap;">';
		self::render_breakdown_table( __( 'By company', 'parseless' ), $summary['by_company'] );

		$purposes = array();
		foreach ( MD4AI_Bots::purpose_labels() as $purpose => $label ) {
			if ( isset( $summary['by_purpose'][ $purpose ] ) ) {
				$purposes[ $label ] = (int) $summary['by_purpose'][ $purpose ];
			}
		}
		self::render_breakdown_table( __( 'By purpose', 'parseless' ), $purposes );
		echo '</div>';
	}

	/**
	 * Renders one KPI card.
	 *
	 * @param string $label Card title.
	 * @param int    $value Metric.
	 * @param string $hint  One-line explanation.
	 */
	private static function render_kpi_card( string $label, int $value, string $hint ): void {
		echo '<div class="md4ai-kpi" style="flex:1 1 200px;max-width:280px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">';
		echo '<div style="font-size:28px;font-weight:600;line-height:1.2;">' . esc_html( number_format_i18n( $value ) ) . '</div>';
		echo '<div style="font-weight:600;">' . esc_html( $label ) . '</div>';
		echo '<div class="description">' . esc_html( $hint ) . '</div>';
		echo '</div>';
	}

	/**
	 * Renders a two-column label / requests table.
	 *
	 * @param string             $title Table heading.
	 * @param array<string, int> $rows  Label => hits, already in display order.
	 */
	private static function render_breakdown_table( string $title, array $rows ): void {
		echo '<div style="flex:1 1 280px;max-width:420px;">';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $hits ) {
			echo '<tr><td>' . esc_html( (string) $label ) . '</td><td style="text-align:right;">' . (int) $hits . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Renders the bot-activity-over-time chart canvas.
	 *
	 * The chart itself is drawn by Chart.js using data localised in
	 * {@see self::enqueue_inline_script()}. When there is no data, the canvas
	 * is skipped entirely.
	 */
	private static function render_bot_chart(): void {
		$chart = MD4AI_Stats::daily_bot_hits( 30, 5 );
		if ( empty( $chart['datasets'] ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Bot activity (30 days)', 'parseless' ) . '</h2>';
		echo '<div class="md4ai-chart-wrap" style="max-width:900px;height:320px;"><canvas id="md4ai-bot-chart"></canvas></div>';
	}

	/**
	 * Renders the known-bot reachability table: every crawler on the list,
	 * when it was last seen, and what it received.
	 *
	 * @param array<int, array{bot_name:string,last_seen:string,markdown:int,html:int,blocked:int,throttled:int}> $rows Rows from MD4AI_Stats::bot_reachability().
	 */
	private static function render_reachability_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Known bots — reachability (30 days)', 'parseless' ) . '</h2>';
		if ( ! md4ai_get_setting( 'log_all_bot_traffic' ) ) {
			echo '<p class="description">' . esc_html__( 'Only Markdown fetches are recorded. Enable "Log All AI Crawler Traffic" under Settings to also see crawlers that receive the normal HTML page.', 'parseless' ) . '</p>';
		}
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'The bot list is empty.', 'parseless' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bot', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Markdown', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'HTML', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Blocked', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Throttled', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['bot_name'] ) . '</td>';
			if ( '' === $row['last_seen'] ) {
				echo '<td><em>' . esc_html__( 'Never seen', 'parseless' ) . '</em></td>';
			} else {
				echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			}
			echo '<td>' . (int) $row['markdown'] . '</td>';
			echo '<td>' . (int) $row['html'] . '</td>';
			echo '<td>' . (int) $row['blocked'] . '</td>';
			echo '<td>' . (int) $row['throttled'] . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'A bot that is never seen, or only ever blocked or throttled, may be stopped by your host or CDN before it reaches WordPress. Pages served from a full-page cache are not recorded.', 'parseless' ) . '</p>';
	}

	/**
	 * Renders the per-bot breakdown table.
	 *
	 * @param array<int, array{bot_name:string,hits:int,last_seen:string}> $rows Aggregated bot rows.
	 */
	private static function render_bot_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Bot breakdown (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No bot traffic recorded yet.', 'parseless' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bot', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Company', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Purpose', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Requests', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$info = MD4AI_Bots::info( (string) $row['bot_name'] );
			echo '<tr>';
			echo '<td>' . esc_html( $row['bot_name'] ) . '</td>';
			echo '<td>' . esc_html( '' !== $info['company'] ? $info['company'] : '—' ) . '</td>';
			echo '<td>' . esc_html( MD4AI_Bots::purpose_label( $info['purpose'] ) ) . '</td>';
			echo '<td>' . (int) $row['hits'] . '</td>';
			echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the top-posts table.
	 *
	 * @param array<int, array{post_id:int,hits:int,last_seen:string}> $rows Aggregated post rows.
	 */
	private static function render_post_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Top URLs (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No URL hits recorded yet.', 'parseless' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Requests', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$title = get_the_title( (int) $row['post_id'] );
			$edit  = get_edit_post_link( (int) $row['post_id'] );
			echo '<tr>';
			if ( $edit ) {
				echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a></td>';
			} else {
				echo '<td>' . esc_html( $title ) . '</td>';
			}
			echo '<td>' . (int) $row['hits'] . '</td>';
			echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the top-fetched-URLs table, including endpoint fetches
	 * (llms.txt, sitemap, OKF bundle files) that carry no post ID.
	 *
	 * @param array<int, array{url:string,hits:int,last_seen:string}> $rows Aggregated URL rows.
	 */
	private static function render_url_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Top fetched URLs (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No URL fetches recorded yet.', 'parseless' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Fetches', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( $row['url'] ) . '</code></a></td>';
			echo '<td>' . (int) $row['hits'] . '</td>';
			echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the unknown-UA review table.
	 *
	 * @param array<int, array{user_agent:string,hits:int,last_seen:string}> $rows Unknown UA rows.
	 */
	private static function render_unknown_ua_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Unknown bot-like UAs (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No unrecognised AI-like UAs flagged.', 'parseless' ) . '</p>';
			return;
		}
		$nonce = wp_create_nonce( 'md4ai_add_ua_to_list' );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'User-Agent', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Hits', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $row['user_agent'] ) . '</code></td>';
			echo '<td>' . (int) $row['hits'] . '</td>';
			echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			echo '<td><button type="button" class="button md4ai-add-ua" '
				. 'data-ua="' . esc_attr( $row['user_agent'] ) . '" '
				. 'data-nonce="' . esc_attr( $nonce ) . '">'
				. esc_html__( 'Add to bot list', 'parseless' )
				. '</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the CSV export form.
	 */
	private static function render_export_form(): void {
		$url = admin_url( 'admin-post.php' );
		echo '<h2>' . esc_html__( 'Export', 'parseless' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( 'md4ai_export_csv', '_md4ai_export_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT_CSV ) . '">';
		echo '<label for="md4ai_export_days">' . esc_html__( 'Window:', 'parseless' ) . ' </label>';
		echo '<select id="md4ai_export_days" name="days">';
		foreach ( array( 7, 30, 90, 365 ) as $days ) {
			/* translators: %d: number of days in the export window. */
			$label = sprintf( _n( '%d day', '%d days', $days, 'parseless' ), $days );
			echo '<option value="' . esc_attr( (string) $days ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Export CSV', 'parseless' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Enqueues the inline JS that powers analytics tab buttons.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_inline_script( string $hook ): void {
		if ( 'tools_page_parseless' !== $hook ) {
			return;
		}

		self::enqueue_chart();

		$script = "
		(function(){
			document.addEventListener('click', function(e){
				var btn = e.target.closest('.md4ai-add-ua');
				if (btn) {
					e.preventDefault();
					btn.disabled = true;
					var data = new FormData();
					data.append('action', '" . esc_js( self::AJAX_ADD_TO_LIST ) . "');
					data.append('nonce', btn.dataset.nonce);
					data.append('ua', btn.dataset.ua);
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r){ return r.json(); })
						.then(function(j){
							if (j && j.success) { btn.textContent = '" . esc_js( __( 'Added', 'parseless' ) ) . "'; }
							else { btn.disabled = false; alert(j && j.data && j.data.message ? j.data.message : 'Error'); }
						})
						.catch(function(){ btn.disabled = false; });
					return;
				}
				var purge = e.target.closest('#md4ai-purge-logs');
				if (purge) {
					e.preventDefault();
					if (!window.confirm('" . esc_js( __( 'Delete all logged requests? This cannot be undone.', 'parseless' ) ) . "')) return;
					purge.disabled = true;
					var status = document.getElementById('md4ai-purge-status');
					var data = new FormData();
					data.append('action', '" . esc_js( self::AJAX_PURGE_LOGS ) . "');
					data.append('nonce', purge.dataset.nonce);
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r){ return r.json(); })
						.then(function(j){
							purge.disabled = false;
							if (status) status.textContent = j && j.success ? '" . esc_js( __( 'Logs deleted.', 'parseless' ) ) . "' : '" . esc_js( __( 'Failed.', 'parseless' ) ) . "';
						})
						.catch(function(){ purge.disabled = false; });
				}
			});
		})();
		";
		wp_add_inline_script( 'common', $script );
	}

	/**
	 * Enqueues the bundled Chart.js and feeds it the bot-activity data.
	 *
	 * Only runs on the analytics tab when logging is on and there is data to
	 * plot, so the 200KB asset is never loaded needlessly.
	 */
	private static function enqueue_chart(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch, no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( 'analytics' !== $tab || ! md4ai_get_setting( 'enable_logging' ) ) {
			return;
		}

		$chart = MD4AI_Stats::daily_bot_hits( 30, 5 );
		if ( empty( $chart['datasets'] ) ) {
			return;
		}

		wp_enqueue_script(
			'md4ai-chartjs',
			MD4AI_PLUGIN_URL . 'assets/js/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);

		wp_add_inline_script(
			'md4ai-chartjs',
			'window.md4aiChart = ' . wp_json_encode( $chart ) . ';',
			'before'
		);

		$init = "
		(function(){
			var el = document.getElementById('md4ai-bot-chart');
			if (!el || typeof Chart === 'undefined' || !window.md4aiChart) { return; }
			var palette = ['#2271b1','#d63638','#00a32a','#dba617','#8c5cff','#3582c4'];
			var datasets = window.md4aiChart.datasets.map(function(ds, i){
				var c = palette[i % palette.length];
				ds.borderColor = c;
				ds.backgroundColor = c;
				ds.tension = 0.2;
				ds.pointRadius = 2;
				return ds;
			});
			new Chart(el, {
				type: 'line',
				data: { labels: window.md4aiChart.labels, datasets: datasets },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
				}
			});
		})();
		";
		wp_add_inline_script( 'md4ai-chartjs', $init );
	}

	/**
	 * AJAX handler for purging all logged requests.
	 */
	public static function ajax_purge_logs(): void {
		check_ajax_referer( 'md4ai_purge_logs', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'parseless' ) ) );
		}
		MD4AI_Logger::purge_all();
		wp_send_json_success();
	}

	/**
	 * AJAX handler for adding an unknown UA to the bot list.
	 */
	public static function ajax_add_ua_to_list(): void {
		check_ajax_referer( 'md4ai_add_ua_to_list', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'parseless' ) ) );
		}
		$ua = isset( $_POST['ua'] ) ? sanitize_text_field( wp_unslash( $_POST['ua'] ) ) : '';
		if ( '' === $ua ) {
			wp_send_json_error( array( 'message' => __( 'Empty UA', 'parseless' ) ) );
		}

		$settings = md4ai_get_settings();
		$bot_list = (string) $settings['bot_list'];
		// An empty saved list means the built-in defaults are active; seed
		// them first, or saving a single UA would silently replace them all.
		$lines = '' === trim( $bot_list )
			? MD4AI_Detector::default_bot_list()
			: array_filter( array_map( 'trim', explode( "\n", $bot_list ) ) );
		if ( ! in_array( $ua, $lines, true ) ) {
			$lines[] = $ua;
		}
		$settings['bot_list'] = implode( "\n", $lines );
		update_option( 'md4ai_settings', $settings );
		wp_send_json_success();
	}

	/**
	 * Neutralises CSV formula injection: spreadsheet apps execute a cell whose
	 * first character is one of = + - @ (or a leading tab/CR), and the logged
	 * URL and User-Agent are attacker-controlled. Prefixing with a single quote
	 * forces the cell to be read as text.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private static function csv_safe( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Streams logged requests as CSV to the browser.
	 */
	public static function export_csv(): void {
		check_admin_referer( 'md4ai_export_csv', '_md4ai_export_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'parseless' ) );
		}

		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 30;
		if ( ! in_array( $days, array( 7, 30, 90, 365 ), true ) ) {
			$days = 30;
		}

		$rows = MD4AI_Stats::export_rows( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="parseless-requests-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'created_at', 'post_id', 'url', 'user_agent', 'bot_name', 'bytes_served', 'cache_hit', 'served' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					self::csv_safe( (string) $row['created_at'] ),
					(int) $row['post_id'],
					self::csv_safe( (string) $row['url'] ),
					self::csv_safe( (string) $row['user_agent'] ),
					self::csv_safe( (string) $row['bot_name'] ),
					(int) $row['bytes_served'],
					(int) $row['cache_hit'],
					self::csv_safe( (string) ( $row['served'] ?? '' ) ),
				),
				',',
				'"',
				''
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output stream, not a filesystem resource.
		fclose( $out );
		exit;
	}
}

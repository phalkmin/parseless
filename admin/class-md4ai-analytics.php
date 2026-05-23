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

	public const AJAX_PURGE_LOGS    = 'md4ai_purge_logs';
	public const AJAX_ADD_TO_LIST   = 'md4ai_add_ua_to_list';
	public const ACTION_EXPORT_CSV  = 'md4ai_export_csv';

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

		self::render_bot_table( MD4AI_Stats::top_bots( 30, 50 ) );
		self::render_post_table( MD4AI_Stats::top_posts( 30, 50 ) );
		self::render_unknown_ua_table( MD4AI_Stats::unknown_uas( 30, 50 ) );
		self::render_export_form();
	}

	/**
	 * @param array<int, array{bot_name:string,hits:int,last_seen:string}> $rows
	 */
	private static function render_bot_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Bot breakdown (30 days)', 'parseless' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No bot traffic recorded yet.', 'parseless' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bot', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Requests', 'parseless' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'parseless' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['bot_name'] ) . '</td>';
			echo '<td>' . (int) $row['hits'] . '</td>';
			echo '<td>' . esc_html( $row['last_seen'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<int, array{post_id:int,hits:int,last_seen:string}> $rows
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
	 * @param array<int, array{user_agent:string,hits:int,last_seen:string}> $rows
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

	public static function enqueue_inline_script( string $hook ): void {
		if ( 'tools_page_parseless' !== $hook ) {
			return;
		}
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

	public static function ajax_purge_logs(): void {
		check_ajax_referer( 'md4ai_purge_logs', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'parseless' ) ) );
		}
		MD4AI_Logger::purge_all();
		wp_send_json_success();
	}

	public static function ajax_add_ua_to_list(): void {
		check_ajax_referer( 'md4ai_add_ua_to_list', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'parseless' ) ) );
		}
		$ua = isset( $_POST['ua'] ) ? sanitize_text_field( wp_unslash( $_POST['ua'] ) ) : '';
		if ( '' === $ua ) {
			wp_send_json_error( array( 'message' => __( 'Empty UA', 'parseless' ) ) );
		}

		$settings   = md4ai_get_settings();
		$bot_list   = (string) $settings['bot_list'];
		$lines      = '' === trim( $bot_list ) ? array() : array_filter( array_map( 'trim', explode( "\n", $bot_list ) ) );
		if ( ! in_array( $ua, $lines, true ) ) {
			$lines[] = $ua;
		}
		$settings['bot_list'] = implode( "\n", $lines );
		update_option( 'md4ai_settings', $settings );
		wp_send_json_success();
	}

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
		fputcsv( $out, array( 'created_at', 'post_id', 'url', 'user_agent', 'bot_name', 'bytes_served', 'cache_hit' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['created_at'],
					$row['post_id'],
					$row['url'],
					$row['user_agent'],
					$row['bot_name'],
					$row['bytes_served'],
					$row['cache_hit'],
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output stream, not a filesystem resource.
		fclose( $out );
		exit;
	}
}

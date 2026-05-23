<?php
/**
 * WP-admin dashboard widget.
 *
 * @package WP_Parseless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a short summary of ParseLess traffic on the wp-admin dashboard.
 */
class MD4AI_Dashboard_Widget {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the widget if the current user can see it.
	 */
	public static function register(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'md4ai_traffic',
			__( 'ParseLess — AI Traffic', 'parseless' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Renders the widget body.
	 */
	public static function render(): void {
		if ( ! md4ai_get_setting( 'enable_logging' ) ) {
			$url = admin_url( 'tools.php?page=parseless' );
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings URL */
					__( 'Logging is disabled. <a href="%s">Enable it in Tools → ParseLess</a> to see AI traffic.', 'parseless' ),
					esc_url( $url )
				)
			) . '</p>';
			return;
		}

		$bots  = MD4AI_Stats::top_bots( 7, 5 );
		$posts = MD4AI_Stats::top_posts( 7, 5 );
		$total = MD4AI_Stats::total_requests( 7 );
		$rate  = MD4AI_Stats::cache_hit_rate( 7 );
		$rate_label = null === $rate ? '—' : round( $rate * 100 ) . '%';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: request count, 2: cache hit rate */
				__( '%1$d markdown requests served · %2$s cache hit rate (last 7 days)', 'parseless' ),
				$total,
				$rate_label
			)
		) . '</p>';

		echo '<h4>' . esc_html__( 'Top bots', 'parseless' ) . '</h4>';
		if ( empty( $bots ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'parseless' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $bots as $row ) {
				echo '<li>' . esc_html( $row['bot_name'] ) . ' — ' . (int) $row['hits'] . '</li>';
			}
			echo '</ul>';
		}

		echo '<h4>' . esc_html__( 'Top URLs', 'parseless' ) . '</h4>';
		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'parseless' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $posts as $row ) {
				$title = get_the_title( (int) $row['post_id'] );
				$edit  = get_edit_post_link( (int) $row['post_id'] );
				if ( $edit ) {
					echo '<li><a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a> — ' . (int) $row['hits'] . '</li>';
				} else {
					echo '<li>' . esc_html( $title ) . ' — ' . (int) $row['hits'] . '</li>';
				}
			}
			echo '</ul>';
		}
	}
}

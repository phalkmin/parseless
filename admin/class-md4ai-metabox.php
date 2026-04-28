<?php
/**
 * Admin metabox.
 *
 * @package WP_Botfood
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Markdown preview metabox to supported post types.
 */
class MD4AI_Metabox {

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_md4ai_preview_markdown', array( __CLASS__, 'ajax_preview' ) );
	}

	/**
	 * Enqueues metabox assets on post editing screens.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_register_script(
			'md4ai-metabox',
			MD4AI_PLUGIN_URL . 'admin/js/md4ai-metabox.js',
			array(),
			MD4AI_VERSION,
			array( 'in_footer' => true )
		);
		wp_enqueue_script( 'md4ai-metabox' );

		wp_register_style(
			'md4ai-metabox',
			MD4AI_PLUGIN_URL . 'admin/css/md4ai-metabox.css',
			array(),
			MD4AI_VERSION
		);
		wp_enqueue_style( 'md4ai-metabox' );
	}

	/**
	 * Registers the metabox on supported post types.
	 */
	public static function register(): void {
		$enabled    = md4ai_get_setting( 'enabled_post_types' );
		$all        = md4ai_supported_post_types();
		$base_types = ( ! empty( $enabled ) && is_array( $enabled ) ) ? array_intersect( $all, $enabled ) : $all;
		$post_types = apply_filters( 'md4ai_supported_post_types', array_values( $base_types ) );
		foreach ( $post_types as $type ) {
			add_meta_box(
				'md4ai-preview',
				__( 'ParseLess — Markdown Preview', 'wp-botfood' ),
				array( __CLASS__, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( WP_Post $post ): void {
		$param_name = md4ai_get_setting( 'query_param_name' );
		$md_url     = add_query_arg( $param_name, 'md', get_permalink( $post ) );
		$preview    = self::build_preview( $post );
		$nonce      = wp_create_nonce( 'md4ai_preview_' . $post->ID );
		?>
		<p>
			<a href="<?php echo esc_url( $md_url ); ?>" target="_blank" rel="noopener" class="button button-secondary">
				<?php esc_html_e( 'View as Markdown', 'wp-botfood' ); ?>
			</a>
		</p>
		<p>
			<button type="button" class="button button-secondary" id="md4ai-copy-btn"
				data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-fetching-text="<?php echo esc_attr__( 'Fetching...', 'wp-botfood' ); ?>"
				data-error-text="<?php echo esc_attr__( 'Error.', 'wp-botfood' ); ?>"
				data-copied-text="<?php echo esc_attr__( 'Copied!', 'wp-botfood' ); ?>">
				<?php esc_html_e( 'Copy Markdown', 'wp-botfood' ); ?>
			</button>
			<span id="md4ai-copy-status" class="md4ai-copy-status"></span>
		</p>
		<?php if ( '' !== $preview ) : ?>
			<details>
				<summary><?php esc_html_e( 'Preview (first 500 chars)', 'wp-botfood' ); ?></summary>
				<pre class="md4ai-preview"><?php echo esc_html( $preview ); ?></pre>
			</details>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handles the Markdown preview AJAX request.
	 */
	public static function ajax_preview(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ) );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'md4ai_preview_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Nonce check failed.' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}
		$markdown = MD4AI::build_markdown_for_preview( $post );
		wp_send_json_success( array( 'markdown' => $markdown ) );
	}

	/**
	 * Builds a short preview for the metabox.
	 *
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	private static function build_preview( WP_Post $post ): string {
		if ( 'publish' !== $post->post_status ) {
			return '';
		}
		$markdown = MD4AI::build_markdown_for_preview( $post );
		return mb_substr( $markdown, 0, 500 );
	}
}

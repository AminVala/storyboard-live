<?php
/**
 * Sequence preview page.
 *
 * Serves a standalone, full-screen preview of a Sequence that is accessible
 * only to users who can edit that specific post.  The URL looks like:
 *   https://site.com/?shseq_preview=123&_shseq_token=<nonce>
 *
 * Because the preview is purely frontend (not admin), the real theme header,
 * real admin bar, and the single-image runtime all load exactly as they would
 * for a live visitor — giving the editor a faithful preview before embedding
 * the shortcode anywhere.
 *
 * Features:
 * - Security: logged-in + edit_post cap + per-post nonce
 * - Works for draft, pending, private, and published statuses
 * - Thin admin bar strip at the very top with a Back/Edit link
 * - Renders via the same [storyboard_live] shortcode pipeline
 * - No sequence must be published to be previewed
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Registers and serves the standalone sequence preview URL.
 */
final class SequencePreview {

	/** Query variable name. */
	const QUERY_VAR = 'shseq_preview';

	/** Nonce action prefix (suffixed with post ID). */
	const NONCE_ACTION = 'shseq_preview_';

	/** Register hooks. */
	public function register_hooks() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_preview' ) );
	}

	/**
	 * Add shseq_preview to WordPress's recognised query vars.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Generate a signed preview URL for the given Sequence.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return string
	 */
	public static function preview_url( $post_id ) {
		return add_query_arg(
			array(
				self::QUERY_VAR   => absint( $post_id ),
				'_shseq_token'    => wp_create_nonce( self::NONCE_ACTION . $post_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Intercept the request and serve the preview if the query var is present.
	 */
	public function maybe_serve_preview() {
		$post_id = (int) get_query_var( self::QUERY_VAR, 0 );
		if ( $post_id <= 0 ) {
			return;
		}

		/* ── Authentication ──────────────────────────────────────── */
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		/* Verify nonce */
		$token = isset( $_GET['_shseq_token'] ) ? sanitize_text_field( wp_unslash( $_GET['_shseq_token'] ) ) : '';
		if ( ! wp_verify_nonce( $token, self::NONCE_ACTION . $post_id ) ) {
			wp_die( esc_html__( 'Preview link has expired. Go back and generate a fresh preview.', 'sh-sequence-engine' ), 403 );
		}

		/* Verify post exists, is the right type, and user can edit it */
		$post = get_post( $post_id );
		if (
			! $post ||
			SequencePostType::POST_TYPE !== $post->post_type ||
			! current_user_can( 'edit_post', $post_id )
		) {
			wp_die( esc_html__( 'Preview not available.', 'sh-sequence-engine' ), 403 );
		}

		/* ── Build content ───────────────────────────────────────── */
		$sequence_title = get_the_title( $post ) ?: __( '(Untitled)', 'sh-sequence-engine' );
		$edit_url       = get_edit_post_link( $post_id, 'raw' ) ?: admin_url();
		$back_url       = wp_get_referer() ?: $edit_url;

		/* Render shortcode — forces publish=false check to pass because user has edit_post cap */
		$shortcode_output = do_shortcode( '[storyboard_live id="' . $post_id . '"]' );

		$this->output_preview_page( $sequence_title, $edit_url, $back_url, $shortcode_output, $post );
		exit;
	}

	/**
	 * Output the standalone preview HTML page.
	 *
	 * @param string   $title    Sequence title.
	 * @param string   $edit_url Edit post link.
	 * @param string   $back_url Referrer / back link.
	 * @param string   $content  Rendered shortcode HTML.
	 * @param \WP_Post $post     The sequence post.
	 */
	private function output_preview_page( $title, $edit_url, $back_url, $content, $post ) {
		$status_label = '';
		$status_obj   = get_post_status_object( $post->post_status );
		if ( $status_obj ) {
			$status_label = $status_obj->label;
		}

		// Use the active theme's stylesheet so the preview includes real theme CSS.
		$stylesheet_url = get_stylesheet_uri();

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( sprintf( __( 'Preview — %s', 'sh-sequence-engine' ), $title ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $stylesheet_url ); ?>">
	<?php wp_head(); ?>
	<style>
		/* Preview strip */
		.shseq-preview-bar {
			position: fixed;
			top: 0; left: 0; right: 0;
			z-index: 99999;
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 0 20px;
			height: 40px;
			background: #1d2327;
			color: #c3c4c7;
			font: 600 12px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		}
		.shseq-preview-bar a {
			color: #72aee6;
			text-decoration: none;
		}
		.shseq-preview-bar a:hover { text-decoration: underline; }
		.shseq-preview-bar__sep { color: #50575e; }
		.shseq-preview-bar__status {
			display: inline-flex;
			align-items: center;
			padding: 2px 8px;
			border-radius: 999px;
			background: #2c3338;
			color: #c3c4c7;
			font-size: 11px;
		}
		/* Push content below fixed bar */
		body { padding-top: 40px !important; }
	</style>
</head>
<body <?php body_class( 'shseq-preview-mode' ); ?>>

	<div class="shseq-preview-bar" role="banner" aria-label="<?php echo esc_attr__( 'Preview bar', 'sh-sequence-engine' ); ?>">
		<a href="<?php echo esc_url( $back_url ); ?>">← <?php echo esc_html__( 'Back', 'sh-sequence-engine' ); ?></a>
		<span class="shseq-preview-bar__sep" aria-hidden="true">|</span>
		<span><?php echo esc_html( $title ); ?></span>
		<?php if ( $status_label ) : ?>
			<span class="shseq-preview-bar__status"><?php echo esc_html( $status_label ); ?></span>
		<?php endif; ?>
		<span class="shseq-preview-bar__sep" aria-hidden="true">|</span>
		<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Edit Sequence', 'sh-sequence-engine' ); ?></a>
	</div>

	<?php if ( empty( trim( $content ) ) ) : ?>
		<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 40px);flex-direction:column;gap:16px;color:#667085;font-family:inherit;text-align:center;padding:40px">
			<svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<rect x="8" y="8" width="32" height="32" rx="6" stroke="currentColor" stroke-width="2"/>
				<path d="M18 24h12M18 30h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				<circle cx="24" cy="18" r="3" stroke="currentColor" stroke-width="2"/>
			</svg>
			<p style="font-size:16px;font-weight:600;margin:0"><?php echo esc_html__( 'No content to preview yet.', 'sh-sequence-engine' ); ?></p>
			<p style="font-size:14px;margin:0"><?php echo esc_html__( 'Upload and confirm a Golden Master image to render the sequence.', 'sh-sequence-engine' ); ?></p>
			<a href="<?php echo esc_url( $edit_url ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:1px solid #e4e7ec;border-radius:8px;color:#2563eb;font-size:14px;font-weight:600;text-decoration:none">
				<?php echo esc_html__( 'Go to Sequence editor', 'sh-sequence-engine' ); ?>
			</a>
		</div>
	<?php else : ?>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output already sanitised. ?>
	<?php endif; ?>

	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}

<?php
/**
 * Fallback variant notices.
 *
 * G04: When tablet or mobile Golden Master is not confirmed and the plugin
 * is using the desktop master as a fallback, the admin must be informed.
 *
 * Two surfaces:
 *   1. Admin notice on the Sequence edit screen.
 *   2. An HTML comment in the shortcode output (visible in Dev Tools).
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Emits admin notices and HTML comments when variant masters fall back to desktop.
 */
final class FallbackNotice {

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'render_fallback_notice' ) );
	}

	/**
	 * Render an admin notice on the Sequence edit screen listing which variants
	 * are using the desktop Golden Master as a fallback.
	 */
	public function render_fallback_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'shseq_sequence' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$fallbacks = $this->get_fallback_variants( $post_id );
		if ( empty( $fallbacks ) ) {
			return;
		}

		$list = implode( ', ', array_map( 'ucfirst', $fallbacks ) );

		echo '<div class="notice notice-warning">';
		echo '<p>';
		printf(
			/* translators: %s: comma-separated list of variant names (e.g. "Tablet, Mobile") */
			esc_html__( '%s: Using desktop Golden Master as fallback for: %s. Upload and confirm variant-specific images for the best result on those screen sizes.', 'sh-sequence-engine' ),
			'<strong>' . esc_html__( 'StoryBoard Live', 'sh-sequence-engine' ) . '</strong>',
			'<strong>' . esc_html( $list ) . '</strong>'
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Return an HTML comment string listing fallback variants.
	 * Injected by SingleImageShortcode into the rendered output.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return string  HTML comment or empty string.
	 */
	public static function shortcode_comment( $post_id ) {
		$fallbacks = self::get_fallback_variants_static( $post_id );
		if ( empty( $fallbacks ) ) {
			return '';
		}

		$list = implode( ', ', array_map( 'ucfirst', $fallbacks ) );
		return "\n<!-- StoryBoard Live: desktop Golden Master fallback active for: {$list} -->\n";
	}

	/**
	 * Return array of variant names that are falling back to desktop.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return string[]
	 */
	private function get_fallback_variants( $post_id ) {
		return self::get_fallback_variants_static( $post_id );
	}

	/**
	 * Static version usable from shortcode context.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return string[]
	 */
	public static function get_fallback_variants_static( $post_id ) {
		$masters       = GoldenMasterMetaBox::get_masters( $post_id );
		$confirmations = GoldenMasterMetaBox::get_confirmations( $post_id );

		// Desktop must be confirmed for any fallback to be active.
		if ( ! $confirmations['desktop'] || empty( $masters['desktop'] ) ) {
			return array();
		}

		$fallbacks = array();
		foreach ( array( 'tablet', 'mobile' ) as $variant ) {
			$has_own      = ! empty( $masters[ $variant ] );
			$is_confirmed = ! empty( $confirmations[ $variant ] );

			if ( ! $has_own || ! $is_confirmed ) {
				$fallbacks[] = $variant;
			}
		}

		return $fallbacks;
	}
}

<?php
/**
 * Frame Sequence Shortcode — Loop 3 Final (v3)
 *
 * Registers [storyboard_live id="123"] and legacy alias [shseq_sequence id="123"].
 *
 * DOM structure (v3):
 *   .shseq-frame-sequence           — outer sentinel (420 vh tall)
 *     .shseq-stage                  — position:sticky, height = vh − header_offset
 *       canvas.shseq-canvas         — frame renderer (cover-fit via JS)
 *       img.shseq-static-fallback   — reduced-motion static image (CSS-controlled)
 *       .shseq-overlays             — content step overlays (inside sticky stage ✓)
 *         .shseq-overlay[data-step]
 *     noscript > img                — no-JS fallback
 *     script.shseq-manifest         — inline JSON for JS engine (class fixed from v1 bug)
 *
 * Fixes vs v1:
 *   - manifest class was "shseq-manifest-data" → JS looked for "shseq-manifest" → never found
 *   - overlays were OUTSIDE .shseq-stage → scrolled away from viewport during animation
 *   - no reduced-motion static image inside the sticky stage
 *   - no CSS custom property for header offset (--shseq-top)
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Frames\FrameManager;

/**
 * Handles [storyboard_live] shortcode rendering.
 */
final class FrameSequenceShortcode {

	const SHORTCODE     = 'storyboard_live';
	const SHORTCODE_OLD = 'shseq_sequence';

	/** @var FrameSequenceManifest */
	private $manifest;

	/** @var FrameSequenceAssets */
	private $assets;

	/** @var int Counter for unique instance IDs per page. */
	private static $instance_counter = 0;

	/**
	 * @param FrameSequenceManifest $manifest Manifest builder.
	 * @param FrameSequenceAssets   $assets   Asset enqueuer.
	 */
	public function __construct( FrameSequenceManifest $manifest, FrameSequenceAssets $assets ) {
		$this->manifest = $manifest;
		$this->assets   = $assets;
	}

	/** Register hooks. */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE,     array( $this, 'render' ) );
		add_shortcode( self::SHORTCODE_OLD, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => '0',
				'class'  => '',
				'height' => '420vh',
			),
			$atts,
			self::SHORTCODE
		);

		$post_id = absint( $atts['id'] );
		if ( 0 === $post_id ) {
			return $this->debug_comment( 'storyboard_live: missing id attribute' );
		}

		if ( 'shseq_sequence' !== get_post_type( $post_id ) ) {
			return $this->debug_comment( 'storyboard_live: post ' . $post_id . ' is not a Sequence' );
		}

		// Build manifest — bail silently if no frames yet.
		$instance_id = 'shseq-' . $post_id . '-' . ( ++self::$instance_counter );
		$data        = $this->manifest->build( $post_id, $instance_id );

		if ( null === $data ) {
			return is_admin()
				? $this->debug_comment( 'storyboard_live: no frames for sequence ' . $post_id )
				: $this->render_placeholder( $post_id );
		}

		// Enqueue assets on this page (idempotent).
		$this->assets->enqueue_for_page();

		// Resolve last frame for static fallbacks.
		$frames   = FrameManager::get_frames( $post_id );
		$last_att = ! empty( $frames ) ? end( $frames ) : 0;
		$last_url = $last_att ? (string) wp_get_attachment_image_url( $last_att, 'full' ) : '';
		$last_alt = '';
		if ( $last_att ) {
			$last_alt = trim( (string) get_post_meta( $last_att, '_wp_attachment_image_alt', true ) );
		}
		if ( '' === $last_alt ) {
			$last_alt = (string) get_the_title( $post_id );
		}

		$steps       = ContentStepsMetaBox::get_steps( $post_id );
		$extra_class = esc_attr( trim( (string) $atts['class'] ) );
		$height      = esc_attr( (string) $atts['height'] );
		$title       = esc_attr( (string) get_the_title( $post_id ) );

		ob_start();
		?>
		<div
			class="shseq-frame-sequence<?php echo $extra_class ? ' ' . $extra_class : ''; ?>"
			id="<?php echo esc_attr( $instance_id ); ?>"
			data-shseq="true"
			role="region"
			aria-label="<?php printf( esc_attr__( '%s — scroll animation', 'sh-sequence-engine' ), $title ); ?>"
			style="--shseq-height:<?php echo $height; ?>;"
		>
			<?php /*
			 * Sticky stage — pinned while user scrolls.
			 * --shseq-top is set by the JS engine after detecting the theme header + admin bar heights.
			 * All interactive overlay content lives INSIDE this stage so it stays in the viewport.
			 */ ?>
			<div class="shseq-stage" aria-hidden="true">

				<canvas
					class="shseq-canvas"
					aria-label="<?php echo esc_attr( $last_alt ); ?>"
				></canvas>

				<?php /* Reduced-motion static fallback — visible via CSS when prefers-reduced-motion: reduce */ ?>
				<?php if ( $last_url ) : ?>
					<img
						class="shseq-static-fallback"
						src="<?php echo esc_url( $last_url ); ?>"
						alt="<?php echo esc_attr( $last_alt ); ?>"
						loading="eager"
					>
				<?php endif; ?>

				<?php /* Content step overlays — inside sticky stage (v3 fix) */ ?>
				<?php if ( ! empty( $steps ) ) : ?>
					<div class="shseq-overlays">
						<?php foreach ( $steps as $i => $step ) : ?>
							<div
								class="shseq-overlay"
								data-step="<?php echo (int) $i; ?>"
								data-scroll="<?php echo (int) ( $step['scroll_pct'] ?? 0 ); ?>"
								aria-hidden="true"
							>
								<?php if ( ! empty( $step['logo_url'] ) ) : ?>
									<img
										class="shseq-overlay__logo"
										src="<?php echo esc_url( $step['logo_url'] ); ?>"
										alt=""
										loading="lazy"
									>
								<?php endif; ?>

								<?php if ( ! empty( $step['heading'] ) ) : ?>
									<h2 class="shseq-overlay__heading">
										<?php echo esc_html( $step['heading'] ); ?>
									</h2>
								<?php endif; ?>

								<?php if ( ! empty( $step['paragraph'] ) ) : ?>
									<p class="shseq-overlay__paragraph">
										<?php echo esc_html( $step['paragraph'] ); ?>
									</p>
								<?php endif; ?>

								<?php if ( ! empty( $step['badge_text'] ) ) : ?>
									<span class="shseq-overlay__badge">
										<?php echo esc_html( $step['badge_text'] ); ?>
									</span>
								<?php endif; ?>

								<?php if ( ! empty( $step['cta_text'] ) ) : ?>
									<a
										class="shseq-overlay__cta"
										href="<?php echo esc_url( $step['cta_url'] ?? '#' ); ?>"
									>
										<?php echo esc_html( $step['cta_text'] ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			</div><?php /* end .shseq-stage */ ?>

			<?php /* Noscript fallback — static last frame when JS disabled */ ?>
			<?php if ( $last_url ) : ?>
				<noscript>
					<img
						class="shseq-noscript-fallback"
						src="<?php echo esc_url( $last_url ); ?>"
						alt="<?php echo esc_attr( $last_alt ); ?>"
					>
				</noscript>
			<?php endif; ?>

			<?php /*
			 * Inline JSON manifest for the JS engine.
			 * Class "shseq-manifest" must match the querySelector in frame-sequence-engine.js.
			 * (v1 bug: class was "shseq-manifest-data" → engine never found it)
			 */ ?>
			<script type="application/json" class="shseq-manifest">
				<?php echo wp_json_encode( $data ); ?>
			</script>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a simple placeholder when no frames are ready.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return string
	 */
	private function render_placeholder( int $post_id ): string {
		return sprintf(
			'<div class="shseq-placeholder" aria-label="%s"><span>%s</span></div>',
			esc_attr( (string) get_the_title( $post_id ) ),
			esc_html__( 'Hero sequence loading…', 'sh-sequence-engine' )
		);
	}

	/**
	 * HTML comment visible in source but not in browser.
	 * Only emitted in WP_DEBUG mode.
	 *
	 * @param string $msg Message.
	 * @return string
	 */
	private function debug_comment( string $msg ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return '<!-- ' . esc_html( $msg ) . ' -->';
		}
		return '';
	}
}

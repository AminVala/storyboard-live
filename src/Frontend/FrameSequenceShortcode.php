<?php
/**
 * Frame Sequence shortcode handler.
 *
 * Registers [storyboard_live id="123"] (and the deprecated alias
 * [shseq_sequence id="123"]).
 *
 * Output:
 *   - A sticky wrapper div that the JS engine pins during scroll.
 *   - A <canvas> element for frame rendering.
 *   - Content step overlay divs (hidden, faded-in by JS).
 *   - A noscript fallback with the last frame as a static image.
 *   - Inline JSON manifest consumed by the engine (escaped via wp_json_encode).
 *
 * Accessibility:
 *   - role="region" with aria-label on the wrapper.
 *   - prefers-reduced-motion: engine switches to static last frame.
 *   - Alt text on the noscript <img> and on the canvas via aria-label.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

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
				'height' => '100vh',
			),
			$atts,
			self::SHORTCODE
		);

		$post_id = absint( $atts['id'] );
		if ( 0 === $post_id ) {
			return $this->comment( 'storyboard_live: missing id attribute' );
		}

		if ( 'shseq_sequence' !== get_post_type( $post_id ) ) {
			return $this->comment( 'storyboard_live: post ' . $post_id . ' is not a Sequence' );
		}

		// Build manifest first — bail silently if no frames yet.
		$instance_id = 'shseq-' . $post_id . '-' . ( ++self::$instance_counter );
		$data        = $this->manifest->build( $post_id, $instance_id );

		if ( null === $data ) {
			// Fallback: show placeholder if frames aren't ready yet.
			if ( is_admin() ) {
				return $this->comment( 'storyboard_live: no frames for sequence ' . $post_id );
			}
			return $this->render_placeholder( $post_id );
		}

		// Ensure assets are enqueued on this page.
		$this->assets->enqueue_for_page();

		// Resolve last frame for noscript/reduced-motion fallback.
		$frames     = FrameManager::get_frames( $post_id );
		$last_att   = ! empty( $frames ) ? end( $frames ) : 0;
		$last_url   = $last_att ? wp_get_attachment_image_url( $last_att, 'full' ) : '';
		$last_alt   = $last_att ? trim( (string) get_post_meta( $last_att, '_wp_attachment_image_alt', true ) ) : '';
		if ( '' === $last_alt ) {
			$last_alt = get_the_title( $post_id );
		}

		$steps    = ContentStepsMetaBox::get_steps( $post_id );
		$extra_class = esc_attr( $atts['class'] );
		$height      = esc_attr( $atts['height'] );
		$title       = esc_attr( get_the_title( $post_id ) );

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
			<?php /* Sticky canvas wrapper — pinned while user scrolls */ ?>
			<div class="shseq-stage" aria-hidden="true">
				<canvas
					class="shseq-canvas"
					aria-label="<?php echo esc_attr( $last_alt ); ?>"
				></canvas>
			</div>

			<?php /* Content Step overlays */ ?>
			<div class="shseq-overlays" aria-hidden="true">
				<?php foreach ( $steps as $i => $step ) : ?>
					<div
						class="shseq-overlay"
						data-step="<?php echo (int) $i; ?>"
						data-scroll="<?php echo (int) ( $step['scroll_pct'] ?? 0 ); ?>"
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

			<?php /* Noscript fallback — static last frame */ ?>
			<?php if ( $last_url ) : ?>
				<noscript>
					<img
						class="shseq-noscript-fallback"
						src="<?php echo esc_url( $last_url ); ?>"
						alt="<?php echo esc_attr( $last_alt ); ?>"
						loading="lazy"
					>
				</noscript>
			<?php endif; ?>

			<?php /* Inline manifest for the JS engine */ ?>
			<script type="application/json" class="shseq-manifest-data">
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
	private function render_placeholder( $post_id ) {
		return sprintf(
			'<div class="shseq-placeholder" aria-label="%s"><span>%s</span></div>',
			esc_attr( get_the_title( $post_id ) ),
			esc_html__( 'Hero sequence loading…', 'sh-sequence-engine' )
		);
	}

	/**
	 * Return an HTML comment (visible in source, not in browser).
	 *
	 * @param string $msg Debug message.
	 * @return string
	 */
	private function comment( $msg ) {
		return '<!-- ' . esc_html( $msg ) . ' -->';
	}
}

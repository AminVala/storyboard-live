<?php
/** Accessible, progressively enhanced frame sequence shortcode. */

namespace ShahreHonar\SequenceEngine\Frontend;


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
				'height' => '',
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

		$post = get_post( $post_id );
		if ( ! $post || ( ( 'publish' !== $post->post_status || post_password_required( $post ) ) && ! current_user_can( 'edit_post', $post_id ) ) ) {
			return '';
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

		// Use the same validated frames and normalized content as the player.
		$last = end( $data['frames'] );
		$last_url = $last['url'];
		$last_alt = $last['alt'] ?: (string) get_the_title( $post_id );
		$steps = $data['steps'];
		$extra_class = esc_attr( trim( (string) $atts['class'] ) );
		$height = trim( (string) $atts['height'] );
		if ( ! preg_match( '/^(?:[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?(?:vh|svh|dvh|px)$/D', $height ) ) {
			$height = '';
		}
		$title = (string) get_the_title( $post_id );

		ob_start();
		?>
		<div
			class="shseq-frame-sequence<?php echo $extra_class ? ' ' . $extra_class : ''; ?>"
			id="<?php echo esc_attr( $instance_id ); ?>"
			data-shseq="true"
			role="region"
			aria-label="<?php printf( esc_attr__( '%s — scroll animation', 'sh-sequence-engine' ), esc_attr( $title ) ); ?>"
			<?php if ( $height ) : ?>style="--shseq-height:<?php echo esc_attr( $height ); ?>;"<?php endif; ?>
		>
			<?php /*
			 * Sticky stage — pinned while user scrolls.
			 * --shseq-top is set by the JS engine after detecting the theme header + admin bar heights.
			 * All interactive overlay content lives INSIDE this stage so it stays in the viewport.
			 */ ?>
			<div class="shseq-stage">
				<div class="shseq-visual" role="img" aria-label="<?php echo esc_attr( $last_alt ); ?>">

				<canvas
					class="shseq-canvas"
					aria-hidden="true"
				></canvas>

				<?php /* Reduced-motion static fallback — visible via CSS when prefers-reduced-motion: reduce */ ?>
				<?php if ( $last_url ) : ?>
					<img
						class="shseq-static-fallback"
						src="<?php echo esc_url( $last_url ); ?>"
						alt=""
						loading="eager"
					>
				<?php endif; ?>

				</div>

				<?php /* Content step overlays — inside sticky stage (v3 fix) */ ?>
				<?php if ( ! empty( $steps ) ) : ?>
					<div class="shseq-overlays">
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
				<?php endif; ?>

			</div><?php /* end .shseq-stage */ ?>

			<?php /*
			 * Inline JSON manifest for the JS engine.
			 * Class "shseq-manifest" must match the querySelector in frame-sequence-engine.js.
			 * (v1 bug: class was "shseq-manifest-data" → engine never found it)
			 */ ?>
			<script type="application/json" class="shseq-manifest">
				<?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>
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

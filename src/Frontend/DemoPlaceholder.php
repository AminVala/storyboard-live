<?php
/**
 * Demo shortcode placeholder.
 *
 * G03: The [storyboard_live_demo] shortcode requires 60 WebP frame files that
 * are not bundled in the repository (they are too large for a WordPress.org
 * submission). When those files are absent, the shortcode currently produces a
 * broken Canvas runtime.
 *
 * This class overrides the demo shortcode output when the required frame files
 * are missing. It renders a polished, brand-neutral static placeholder that:
 *   1. Explains to the site administrator that demo frames are needed.
 *   2. Shows visitors a fallback hero gradient — not a blank screen.
 *   3. Includes a link to the plugin's documentation.
 *
 * When the demo frames ARE present (at assets/demo/frames/*.webp), the
 * standard RuntimeShortcode takes over and this class is a no-op.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

/**
 * Overrides [storyboard_live_demo] when demo frames are missing.
 */
final class DemoPlaceholder {

	/** Register hooks — only when frames are absent. */
	public function register_hooks() {
		if ( $this->demo_frames_present() ) {
			return; /* Let RuntimeShortcode handle it. */
		}

		/*
		 * Hook at priority 5 so this fires BEFORE RuntimeShortcode registers
		 * at the default add_shortcode priority (same call, same hook, but
		 * first-registered wins for the same shortcode tag).
		 *
		 * Actually WordPress does not allow overriding: the first add_shortcode
		 * wins. So we use remove_shortcode + add_shortcode at plugins_loaded 20.
		 */
		add_action( 'init', array( $this, 'override_demo_shortcode' ), 20 );
	}

	/**
	 * Replace the demo shortcodes with the placeholder renderer.
	 */
	public function override_demo_shortcode() {
		foreach ( $this->demo_shortcode_tags() as $tag ) {
			remove_shortcode( $tag );
			add_shortcode( $tag, array( $this, 'render' ) );
		}
	}

	/**
	 * Render the placeholder.
	 *
	 * Visitors see a minimal, polished gradient hero.
	 * Logged-in editors see an additional admin notice.
	 *
	 * @return string
	 */
	public function render() {
		$is_editor = current_user_can( 'edit_shseq_sequences' );

		ob_start();
		?>
		<section class="shseq-demo-placeholder" aria-label="<?php echo esc_attr__( 'StoryBoard Live demo', 'sh-sequence-engine' ); ?>">

			<?php if ( $is_editor ) : ?>
			<div class="shseq-demo-placeholder__notice" role="note">
				<strong><?php echo esc_html__( 'StoryBoard Live — demo frames not found', 'sh-sequence-engine' ); ?></strong>
				<p>
					<?php
					echo esc_html__( 'The [storyboard_live_demo] shortcode requires demo frame images that are not included in the plugin package. Use [storyboard_live id="YOUR_ID"] to embed your own published sequence instead.', 'sh-sequence-engine' );
					?>
				</p>
			</div>
			<?php endif; ?>

			<div class="shseq-demo-placeholder__stage" aria-hidden="true">
				<div class="shseq-demo-placeholder__gradient"></div>
				<div class="shseq-demo-placeholder__overlay">
					<p class="shseq-demo-placeholder__eyebrow">
						<?php echo esc_html__( 'Interactive visual storytelling for the web', 'sh-sequence-engine' ); ?>
					</p>
					<h2 class="shseq-demo-placeholder__title">
						<?php echo esc_html__( 'Bring your story to life with every scroll', 'sh-sequence-engine' ); ?>
					</h2>
					<p class="shseq-demo-placeholder__subtitle">
						<?php echo esc_html__( 'Embed your own sequence with [storyboard_live id="YOUR_ID"].', 'sh-sequence-engine' ); ?>
					</p>
				</div>
			</div>
		</section>
		<style>
		.shseq-demo-placeholder { position: relative; overflow: hidden; }
		.shseq-demo-placeholder__notice {
			padding: 12px 16px;
			background: #fef3c7;
			border-left: 4px solid #f59e0b;
			font-size: 13px;
			line-height: 1.5;
		}
		.shseq-demo-placeholder__notice p { margin: 4px 0 0; }
		.shseq-demo-placeholder__stage {
			position: relative;
			min-height: 60vh;
			display: flex;
			align-items: flex-end;
		}
		.shseq-demo-placeholder__gradient {
			position: absolute;
			inset: 0;
			background: linear-gradient(135deg, #1e3a5f 0%, #2d6a9f 40%, #1a2a4a 100%);
		}
		.shseq-demo-placeholder__overlay {
			position: relative;
			z-index: 1;
			padding: clamp(24px,5vw,64px);
			color: #fff;
		}
		.shseq-demo-placeholder__eyebrow {
			margin: 0 0 10px;
			font-size: clamp(11px,1vw,13px);
			font-weight: 700;
			letter-spacing: .08em;
			text-transform: uppercase;
			color: rgba(255,255,255,.7);
		}
		.shseq-demo-placeholder__title {
			margin: 0 0 14px;
			font-size: clamp(22px,4vw,48px);
			font-weight: 760;
			line-height: 1.15;
		}
		.shseq-demo-placeholder__subtitle {
			margin: 0;
			font-size: clamp(13px,1.4vw,16px);
			color: rgba(255,255,255,.8);
		}
		@media (prefers-reduced-motion: no-preference) {
			.shseq-demo-placeholder__gradient {
				animation: shseq-ph-shift 8s ease-in-out infinite alternate;
			}
		}
		@keyframes shseq-ph-shift {
			from { filter: hue-rotate(0deg); }
			to   { filter: hue-rotate(20deg) brightness(1.08); }
		}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check whether the demo frame files are present.
	 *
	 * @return bool
	 */
	private function demo_frames_present() {
		/* Check for the first desktop frame only. */
		$first_frame = SHSEQ_DIR . 'assets/demo/frames/storyboard-live-0001.webp';
		return file_exists( $first_frame );
	}

	/** @return string[] */
	private function demo_shortcode_tags() {
		return array(
			'storyboard_live_demo',
			'shseq_m6_demo',
			'shseq_m5_demo',
			'shseq_m4_demo',
			'shseq_m2_demo',
			'shseq_m1_demo',
		);
	}
}

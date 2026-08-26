<?php
/**
 * StoryBoard Live demo shortcode.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

/**
 * Renders a manifest-driven runtime instance.
 */
final class RuntimeShortcode {

	/** Primary public demo shortcode. */
	const SHORTCODE = 'storyboard_live_demo';

	/** Legacy aliases retained so previous staging pages do not break. */
	const M6_SHORTCODE = 'shseq_m6_demo';
	const M5_SHORTCODE = 'shseq_m5_demo';
	const M4_SHORTCODE = 'shseq_m4_demo';
	const M2_SHORTCODE = 'shseq_m2_demo';
	const M1_SHORTCODE = 'shseq_m1_demo';

	/** @var RuntimeAssets */
	private $assets;

	/** @var RuntimeManifest */
	private $manifest;

	/**
	 * @param RuntimeAssets   $assets   Runtime assets.
	 * @param RuntimeManifest $manifest Runtime manifest builder.
	 */
	public function __construct( RuntimeAssets $assets, RuntimeManifest $manifest ) {
		$this->assets   = $assets;
		$this->manifest = $manifest;
	}

	/** Register supported shortcodes. */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_shortcode( self::M6_SHORTCODE, array( $this, 'render' ) );
		add_shortcode( self::M5_SHORTCODE, array( $this, 'render' ) );
		add_shortcode( self::M4_SHORTCODE, array( $this, 'render' ) );
		add_shortcode( self::M2_SHORTCODE, array( $this, 'render' ) );
		add_shortcode( self::M1_SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render responsive picture source elements.
	 *
	 * The bundled demo intentionally reuses one frame set, but keeps the media
	 * contract intact so production manifests can provide independent assets.
	 *
	 * @param array<string, mixed> $variants   Named variants.
	 * @param string               $poster_key entry|golden.
	 * @param bool                 $reduced    Add reduced-motion condition.
	 * @return void
	 */
	private function render_picture_sources( $variants, $poster_key, $reduced = false ) {
		$urls = array();
		foreach ( $variants as $variant ) {
			if ( ! empty( $variant['posters'][ $poster_key ]['url'] ) ) {
				$urls[] = (string) $variant['posters'][ $poster_key ]['url'];
			}
		}
		$urls = array_values( array_unique( $urls ) );

		// When every responsive variant uses the same poster, avoid serializing
		// four redundant source tags. The img fallback already owns normal motion;
		// reduced motion needs only one media-qualified golden source.
		if ( 1 === count( $urls ) ) {
			if ( $reduced ) {
				?>
				<source media="(prefers-reduced-motion: reduce)" srcset="<?php echo esc_url( $urls[0] ); ?>">
				<?php
			}
			return;
		}

		$motion  = $reduced ? ' and (prefers-reduced-motion: reduce)' : '';
		$sources = array(
			array( 'mobile-landscape', '(max-width: 959px) and (max-height: 600px) and (orientation: landscape)' . $motion ),
			array( 'mobile-portrait', '(max-width: 767px) and (orientation: portrait)' . $motion ),
			array( 'tablet', '(min-width: 768px) and (max-width: 1179px)' . $motion ),
			array( 'desktop', '(min-width: 1180px)' . $motion ),
		);

		foreach ( $sources as $source ) {
			if ( empty( $variants[ $source[0] ]['posters'][ $poster_key ]['url'] ) ) {
				continue;
			}
			?>
			<source media="<?php echo esc_attr( $source[1] ); ?>" srcset="<?php echo esc_url( $variants[ $source[0] ]['posters'][ $poster_key ]['url'] ); ?>">
			<?php
		}
	}

	/** Render the runtime instance. */
	public function render() {
		$this->assets->enqueue();

		$instance_id = wp_unique_id( 'shseq-runtime-' );
		$heading_id  = $instance_id . '-heading';
		$after_id    = $instance_id . '-after';
		$manifest    = $this->manifest->build_demo_manifest( $instance_id );

		$variants = array();
		foreach ( $manifest['variants'] as $variant ) {
			$variants[ $variant['id'] ] = $variant;
		}

		$desktop    = isset( $variants['desktop'] ) ? $variants['desktop'] : reset( $variants );
		$entry      = $desktop['posters']['entry'];
		$golden     = $desktop['posters']['golden'];
		$scroll_vh  = (int) $desktop['scrollLengthVh'];
		$fit        = (string) $desktop['fit'];
		$handoff_ms = (int) $manifest['handoff']['durationMs'];

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="shseq-runtime"
			data-shseq-runtime
			data-shseq-core-url="<?php echo esc_url( $this->assets->get_runtime_core_url() ); ?>"
			data-shseq-fit="<?php echo esc_attr( $fit ); ?>"
			data-shseq-variant="desktop"
			data-shseq-live-layout="desktop"
			data-shseq-entry-poster="<?php echo esc_url( $entry['url'] ); ?>"
			data-shseq-golden-poster="<?php echo esc_url( $golden['url'] ); ?>"
			style="--shseq-scroll-vh: <?php echo esc_attr( (string) $scroll_vh ); ?>vh; --shseq-handoff-ms: <?php echo esc_attr( (string) $handoff_ms ); ?>ms;"
			aria-labelledby="<?php echo esc_attr( $heading_id ); ?>"
		>
			<div class="shseq-runtime__scrollspace">
				<div class="shseq-runtime__sticky">
					<a class="shseq-runtime__skip" href="#<?php echo esc_attr( $after_id ); ?>">
						<?php echo esc_html__( 'Skip visual story', 'sh-sequence-engine' ); ?>
					</a>

					<picture class="shseq-runtime__poster-wrap">
						<?php $this->render_picture_sources( $variants, 'golden', true ); ?>
						<?php $this->render_picture_sources( $variants, 'entry', false ); ?>
						<img
							class="shseq-runtime__poster"
							src="<?php echo esc_url( $entry['url'] ); ?>"
							alt=""
							width="<?php echo esc_attr( (string) $entry['width'] ); ?>"
							height="<?php echo esc_attr( (string) $entry['height'] ); ?>"
							loading="eager"
							fetchpriority="high"
						>
					</picture>

					<canvas class="shseq-runtime__canvas" aria-hidden="true"></canvas>

					<div class="shseq-runtime__live" data-shseq-live-content>
						<p class="shseq-runtime__eyebrow" data-shseq-overlay data-shseq-key="eyebrow"><?php echo esc_html__( 'Interactive visual storytelling for the web', 'sh-sequence-engine' ); ?></p>
						<h2 id="<?php echo esc_attr( $heading_id ); ?>" data-shseq-overlay data-shseq-key="title"><?php echo esc_html__( 'Bring the story to life with every scroll', 'sh-sequence-engine' ); ?></h2>
						<p data-shseq-overlay data-shseq-key="subtitle"><?php echo esc_html__( 'Frames move with the visitor and hand off cleanly to the real page content and the real theme header.', 'sh-sequence-engine' ); ?></p>
						<div class="shseq-runtime__actions" data-shseq-overlay data-shseq-key="actions">
							<a class="shseq-runtime__cta" href="#<?php echo esc_attr( $after_id ); ?>"><?php echo esc_html__( 'Continue to live content', 'sh-sequence-engine' ); ?></a>
						</div>
					</div>

					<div class="shseq-runtime__scroll-cue" aria-hidden="true"><?php echo esc_html__( 'Scroll to continue', 'sh-sequence-engine' ); ?></div>
					<div class="shseq-runtime__progress" aria-hidden="true"></div>

					<noscript>
						<picture>
							<?php $this->render_picture_sources( $variants, 'golden', false ); ?>
							<img
								class="shseq-runtime__noscript-poster"
								src="<?php echo esc_url( $golden['url'] ); ?>"
								alt=""
								width="<?php echo esc_attr( (string) $golden['width'] ); ?>"
								height="<?php echo esc_attr( (string) $golden['height'] ); ?>"
							>
						</picture>
					</noscript>
				</div>
			</div>

			<script type="application/json" class="shseq-runtime__manifest"><?php echo wp_json_encode( $manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
		</section>

		<div id="<?php echo esc_attr( $after_id ); ?>" class="shseq-runtime-demo-after" tabindex="-1">
			<strong><?php echo esc_html__( 'The live page continues here.', 'sh-sequence-engine' ); ?></strong>
			<span><?php echo esc_html__( 'The story hands off without replacing the real site header or semantic content.', 'sh-sequence-engine' ); ?></span>
		</div>
		<?php

		return ob_get_clean();
	}
}

<?php
/**
 * Single-image sequence shortcode.
 *
 * Renders a published Sequence as a scroll-driven single-image story from its
 * confirmed Golden Master and stored Production Sheet structure.
 *
 * Usage: [storyboard_live id="123" variant="desktop"]
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\LiveContentMetaBox;
use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Renders a manifest-driven single-image runtime instance.
 */
final class SingleImageShortcode {

	const SHORTCODE = 'storyboard_live';

	/** @var SingleImageManifest */
	private $manifest;

	/** @var SingleImageAssets */
	private $assets;

	/** @var bool Whether the last overlay render assigned the heading id. */
	private $heading_assigned = false;

	/**
	 * @param SingleImageManifest $manifest Manifest builder.
	 * @param SingleImageAssets   $assets   Assets loader.
	 */
	public function __construct( SingleImageManifest $manifest, SingleImageAssets $assets ) {
		$this->manifest = $manifest;
		$this->assets   = $assets;
	}

	/** Register the shortcode. */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'variant' => 'desktop',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$post_id = absint( $atts['id'] );
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || SequencePostType::POST_TYPE !== $post->post_type ) {
			return '';
		}

		// SECURITY FIX [SEC-001]: IDOR — Prevent unauthorized access to draft/private sequences.
		// Only published sequences are rendered to visitors. Editors with explicit
		// edit_post capability may preview unpublished sequences in admin context.
		// Without this check, any subscriber could embed [storyboard_live id="X"]
		// to expose Golden Master image URLs and overlay text of unpublished content.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		$instance_id = wp_unique_id( 'shseq-single-' );
		$manifest    = $this->manifest->build( $post_id, (string) $atts['variant'], $instance_id );

		if ( null === $manifest ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				return '<p class="shseq-single__notice">' . esc_html__( 'StoryBoard Live: upload and confirm a Golden Master image for this sequence to render the story.', 'sh-sequence-engine' ) . '</p>';
			}
			return '';
		}

		$this->assets->enqueue();

		$content    = LiveContentMetaBox::get_content( $post_id );
		$image      = $manifest['image'];
		$heading_id = $instance_id . '-heading';
		$after_id   = $instance_id . '-after';
		$scroll_vh  = (int) $manifest['scrollLengthVh'];

		// Render overlays first so we know whether a heading id was actually
		// emitted; only then reference it with aria-labelledby to avoid a
		// dangling reference to a non-existent element.
		$this->heading_assigned = false;
		$overlays_html          = $this->render_overlays( $manifest['overlays'], $content, $heading_id, $after_id );
		$label_attr             = $this->heading_assigned ? ' aria-labelledby="' . esc_attr( $heading_id ) . '"' : ' aria-label="' . esc_attr( get_the_title( $post_id ) ) . '"';

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="shseq-single"
			data-shseq-single
			data-shseq-variant="<?php echo esc_attr( $manifest['variant'] ); ?>"
			style="--shseq-scroll-vh: <?php echo esc_attr( (string) $scroll_vh ); ?>vh;"
			<?php echo $label_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
		>
			<div class="shseq-single__scrollspace">
				<div class="shseq-single__sticky">
					<a class="shseq-single__skip" href="#<?php echo esc_attr( $after_id ); ?>"><?php echo esc_html__( 'Skip visual story', 'sh-sequence-engine' ); ?></a>

					<div class="shseq-single__stage" data-shseq-stage>
						<img
							class="shseq-single__image"
							data-shseq-image
							src="<?php echo esc_url( $image['url'] ); ?>"
							<?php if ( ! empty( $image['srcset'] ) ) : ?>srcset="<?php echo esc_attr( $image['srcset'] ); ?>"<?php endif; ?>
							<?php if ( ! empty( $image['sizes'] ) ) : ?>sizes="<?php echo esc_attr( $image['sizes'] ); ?>"<?php endif; ?>
							alt="<?php echo esc_attr( $image['alt'] ); ?>"
							<?php if ( $image['width'] > 0 ) : ?>width="<?php echo esc_attr( (string) $image['width'] ); ?>"<?php endif; ?>
							<?php if ( $image['height'] > 0 ) : ?>height="<?php echo esc_attr( (string) $image['height'] ); ?>"<?php endif; ?>
							loading="eager"
							fetchpriority="high"
							decoding="async"
						>
					</div>

					<div class="shseq-single__live" data-shseq-live-content>
						<?php echo $overlays_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_overlays(). ?>
					</div>

					<div class="shseq-single__scroll-cue" aria-hidden="true"><?php echo esc_html__( 'Scroll to continue', 'sh-sequence-engine' ); ?></div>
					<div class="shseq-single__progress" data-shseq-progress aria-hidden="true"></div>

					<noscript>
						<img class="shseq-single__noscript" src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( $image['alt'] ); ?>">
					</noscript>
				</div>
			</div>

			<script type="application/json" class="shseq-single__manifest"><?php echo wp_json_encode( $manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
		</section>

		<div id="<?php echo esc_attr( $after_id ); ?>" class="shseq-single-after" tabindex="-1"></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render overlay HTML from stored content, keyed by overlay.
	 *
	 * @param array<int,array<string,mixed>>          $overlays   Overlay slots with reveal frames.
	 * @param array<string,array<string,string>>      $content    Stored overlay content.
	 * @param string                                  $heading_id Id for the primary heading.
	 * @param string                                  $after_id   Skip/handoff target id.
	 * @return string
	 */
	private function render_overlays( $overlays, $content, $heading_id, $after_id ) {
		$html = '';
		foreach ( $overlays as $overlay ) {
			$key = isset( $overlay['key'] ) ? sanitize_key( $overlay['key'] ) : '';
			if ( '' === $key || empty( $content[ $key ] ) || ! is_array( $content[ $key ] ) ) {
				continue;
			}
			$item = $content[ $key ];
			$text = isset( $item['text'] ) ? (string) $item['text'] : '';
			if ( '' === trim( $text ) ) {
				continue;
			}
			$tag   = isset( $item['tag'] ) ? sanitize_key( $item['tag'] ) : 'p';
			$href  = isset( $item['href'] ) ? (string) $item['href'] : '';
			$frame = isset( $overlay['frame'] ) ? (int) $overlay['frame'] : 1;

			$attrs = sprintf(
				' class="shseq-single__overlay shseq-single__overlay--%1$s" data-shseq-overlay data-shseq-key="%1$s" data-shseq-reveal-frame="%2$d"',
				esc_attr( $key ),
				$frame
			);

			if ( 'a' === $tag ) {
				$link_href = '' !== $href ? $href : '#' . $after_id;
				$html     .= sprintf(
					'<a href="%1$s"%2$s>%3$s</a>',
					esc_url( $link_href ),
					$attrs,
					esc_html( $text )
				);
				continue;
			}

			$allowed_headings = array( 'h1', 'h2', 'h3', 'p', 'span' );
			if ( ! in_array( $tag, $allowed_headings, true ) ) {
				$tag = 'p';
			}

			$is_heading = ( 'h1' === $tag || 'h2' === $tag );
			// Assign the heading id to the first h1/h2 only, so aria-labelledby
			// points at exactly one existing element.
			$id_attr = ( $is_heading && ! $this->heading_assigned ) ? sprintf( ' id="%s"', esc_attr( $heading_id ) ) : '';
			if ( '' !== $id_attr ) {
				$this->heading_assigned = true;
			}
			$html   .= sprintf( '<%1$s%2$s%3$s>%4$s</%1$s>', $tag, $id_attr, $attrs, esc_html( $text ) );
		}
		return $html;
	}
}

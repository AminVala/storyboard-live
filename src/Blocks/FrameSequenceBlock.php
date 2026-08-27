<?php
/**
 * Gutenberg block registration for [storyboard_live].
 *
 * The block is a dynamic block: render_callback calls the same
 * FrameSequenceShortcode::render() so there is one rendering path for both
 * shortcode and block.
 *
 * Block name:      shseq/frame-sequence
 * Editor script:   assets/admin/blocks/frame-sequence/index.js
 * Editor style:    assets/admin/blocks/frame-sequence/index.css
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Blocks;

use ShahreHonar\SequenceEngine\Frontend\FrameSequenceShortcode;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceManifest;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceAssets;

/**
 * Registers the Gutenberg block for the frame sequence.
 */
final class FrameSequenceBlock {

	const BLOCK_NAME = 'shseq/frame-sequence';

	/** @var FrameSequenceShortcode */
	private $shortcode;

	/**
	 * @param FrameSequenceShortcode $shortcode Shortcode renderer.
	 */
	public function __construct( FrameSequenceShortcode $shortcode ) {
		$this->shortcode = $shortcode;
	}

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/** Register the block type. */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Gutenberg not available.
		}

		register_block_type(
			self::BLOCK_NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'StoryBoard Hero', 'sh-sequence-engine' ),
				'description'     => __( 'Scroll-driven hero animation from a sequence of images.', 'sh-sequence-engine' ),
				'category'        => 'media',
				'icon'            => 'format-video',
				'keywords'        => array( 'hero', 'scroll', 'animation', 'sequence', 'storyboard' ),
				'supports'        => array(
					'html'    => false,
					'align'   => array( 'full', 'wide' ),
					'spacing' => false,
				),
				'attributes'      => array(
					'sequenceId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'height' => array(
						'type'    => 'string',
						'default' => '100vh',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( $this, 'render' ),
				'editor_script'   => 'shseq-block-frame-sequence',
				'editor_style'    => 'shseq-block-frame-sequence-editor',
			)
		);

		$this->register_editor_assets();
	}

	/**
	 * Register editor JS/CSS for the block.
	 */
	private function register_editor_assets() {
		$version = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		$url     = defined( 'SHSEQ_URL' ) ? SHSEQ_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/sh-sequence-engine.php' );

		wp_register_script(
			'shseq-block-frame-sequence',
			$url . 'assets/admin/blocks/frame-sequence/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			$version,
			false
		);

		wp_register_style(
			'shseq-block-frame-sequence-editor',
			$url . 'assets/admin/blocks/frame-sequence/index.css',
			array(),
			$version
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$sequence_id = isset( $attributes['sequenceId'] ) ? absint( $attributes['sequenceId'] ) : 0;
		$height      = isset( $attributes['height'] )     ? sanitize_text_field( $attributes['height'] ) : '100vh';
		$class       = isset( $attributes['className'] )  ? sanitize_text_field( $attributes['className'] ) : '';

		if ( 0 === $sequence_id ) {
			if ( $this->is_block_editor() ) {
				return $this->editor_placeholder();
			}
			return '';
		}

		// Delegate to shortcode renderer — single rendering path.
		return $this->shortcode->render(
			array(
				'id'     => (string) $sequence_id,
				'height' => $height,
				'class'  => $class,
			)
		);
	}

	/**
	 * HTML shown in the block editor when no sequence is selected.
	 *
	 * @return string
	 */
	private function editor_placeholder() {
		return sprintf(
			'<div class="shseq-block-placeholder"><p>%s</p><p class="shseq-block-placeholder__hint">%s</p></div>',
			esc_html__( 'StoryBoard Hero', 'sh-sequence-engine' ),
			esc_html__( 'Select a Sequence in the block sidebar to preview the hero.', 'sh-sequence-engine' )
		);
	}

	/**
	 * Whether the current request is the block editor context.
	 *
	 * @return bool
	 */
	private function is_block_editor() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}

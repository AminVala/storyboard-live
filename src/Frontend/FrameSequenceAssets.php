<?php
/**
 * Frame Sequence asset enqueuer.
 *
 * Registers the CSS and JS for the scroll engine. Scripts are loaded
 * conditionally — only on pages that contain the [storyboard_live] shortcode
 * or the Gutenberg block. This keeps the homepage performance impact to zero
 * on pages that don't use a Hero.
 *
 * JS bundle: assets/frontend/frame-sequence-engine.js
 * CSS:       assets/frontend/frame-sequence.css
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

/**
 * Handles conditional script/style enqueuing for the frame engine.
 */
final class FrameSequenceAssets {

	const SCRIPT_HANDLE = 'shseq-frame-engine';
	const STYLE_HANDLE  = 'shseq-frame-sequence';

	/** @var bool Whether assets have already been enqueued this request. */
	private $enqueued = false;

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but don't enqueue) assets.
	 * Enqueuing is deferred until the shortcode/block actually renders.
	 */
	public function register_assets() {
		$version = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		$url     = defined( 'SHSEQ_URL' ) ? SHSEQ_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/sh-sequence-engine.php' );

		wp_register_style(
			self::STYLE_HANDLE,
			$url . 'assets/frontend/frame-sequence.css',
			array(),
			$version
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$url . 'assets/frontend/frame-sequence-engine.js',
			array(),
			$version,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}

	/**
	 * Actually enqueue the assets.
	 * Called from the shortcode when it finds a Sequence with frames on the page.
	 */
	public function enqueue_for_page() {
		if ( $this->enqueued ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$this->enqueued = true;
	}
}

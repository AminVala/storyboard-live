<?php
/**
 * Single-image runtime assets.
 *
 * Registers and enqueues the lightweight single-image engine and its styles.
 * Only loaded when a [storyboard_live] shortcode renders.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

/**
 * Loads the single-image runtime engine and CSS on demand.
 */
final class SingleImageAssets {

	const HANDLE = 'shseq-single-engine';

	/** @var bool */
	private $enqueued = false;

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/** Register (but do not enqueue) assets. */
	public function register_assets() {
		wp_register_script(
			self::HANDLE,
			SHSEQ_URL . 'assets/frontend/single-image-engine.min.js',
			array(),
			SHSEQ_VERSION,
			true
		);

		wp_register_style(
			self::HANDLE,
			SHSEQ_URL . 'assets/frontend/single-image.min.css',
			array(),
			SHSEQ_VERSION
		);
	}

	/** Enqueue the runtime engine and styles. */
	public function enqueue() {
		if ( $this->enqueued ) {
			return;
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		$this->enqueued = true;
	}
}

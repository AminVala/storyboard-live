<?php
/**
 * Frame Sequence Asset Enqueuer — Loop 3 Final (v3)
 *
 * Changes from v1:
 *  - Passes SettingsPage options to the JS engine via wp_localize_script
 *    (scroll_speed, disable_on_mobile, lazy_threshold, admin_bar setting).
 *  - Reads real admin-bar height (32px logged-in, 0 otherwise).
 *  - plugin_dir_url() is derived correctly from SHSEQ_URL constant.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\SettingsPage;

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
		$url     = defined( 'SHSEQ_URL' )     ? SHSEQ_URL     : plugin_dir_url( dirname( __DIR__ ) . '/sh-sequence-engine.php' );

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
	 * Actually enqueue the assets and pass settings to JS.
	 * Called from the shortcode when it finds a Sequence with frames on the page.
	 */
	public function enqueue_for_page() {
		if ( $this->enqueued ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		// ── Pass SettingsPage options + environment to JS engine ──────────
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'shseqEngineConfig',
			$this->build_js_config()
		);

		$this->enqueued = true;
	}

	/**
	 * Build the JS config object.
	 *
	 * @return array<string, mixed>
	 */
	private function build_js_config(): array {
		// Admin bar: 32px when logged-in and show_admin_bar_front is active.
		$admin_bar_h = 0;
		if ( is_admin_bar_showing() ) {
			$admin_bar_h = 32; // WP core always renders it at exactly 32px on desktop.
		}

		// Option: show admin bar in sequence pages (user can disable in settings).
		$show_admin_bar = (bool) get_option( SettingsPage::OPT_ADMIN_BAR, 1 );

		return array(
			// Scroll speed (px moved per logical frame step, 1–200).
			'scrollSpeed'     => max( 1, min( 200, (int) get_option( SettingsPage::OPT_SCROLL_SPEED, 4 ) ) ),
			// Disable animation on mobile (true = show static last frame on narrow viewports).
			'disableOnMobile' => (bool) get_option( SettingsPage::OPT_DISABLE_MOBILE, 0 ),
			// Lazy-load threshold in pixels before the sequence enters viewport.
			'lazyThreshold'   => max( 0, (int) get_option( SettingsPage::OPT_LAZY_THRESHOLD, 200 ) ),
			// Known admin-bar height so engine can offset sticky top correctly.
			'adminBarHeight'  => $show_admin_bar ? $admin_bar_h : 0,
			// Nonce for any AJAX calls the engine might make (none currently, future-proof).
			'nonce'           => wp_create_nonce( 'shseq_engine' ),
		);
	}
}

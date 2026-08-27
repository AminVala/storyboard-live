<?php
/**
 * Internationalization bootstrap.
 *
 * Loads the plugin text domain so all strings pass through __() / esc_html_e()
 * translation functions. When WordPress is set to Persian (fa_IR), the bundled
 * languages/sh-sequence-engine-fa_IR.mo file is used automatically.
 *
 * Language detection flow (WordPress 6.2+):
 *   1. WordPress calls load_plugin_textdomain() at 'init' priority 5.
 *   2. It checks wp-content/languages/plugins/ for a .mo file first.
 *   3. Falls back to the plugin's own /languages/ directory.
 *   4. WordPress 6.7+ uses JIT translation — strings are loaded on demand.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\I18n;

/**
 * Registers the bundled language path.
 */
final class I18n {

	const TEXT_DOMAIN = 'sh-sequence-engine';

	/**
	 * Register i18n hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ), 5 );
	}

	/**
	 * Load the plugin text domain.
	 *
	 * Uses plugin_basename() via the SHSEQ_BASENAME constant so the path
	 * is always relative to the plugin root regardless of folder name.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( SHSEQ_BASENAME ) . '/languages'
		);
	}
}

<?php
/**
 * Internationalization bootstrap.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\I18n;

/**
 * Registers the bundled language path without forcing early translations.
 */
final class I18n {

	/**
	 * Register i18n hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_textdomain_path' ), 5 );
	}

	/**
	 * Register custom bundled translation path.
	 *
	 * WordPress 6.7+ hands loading to its JIT translation mechanism.
	 *
	 * @return void
	 */
	public function register_textdomain_path() {
		load_plugin_textdomain(
			'sh-sequence-engine',
			false,
			dirname( SHSEQ_BASENAME ) . '/languages'
		);
	}
}

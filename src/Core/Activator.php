<?php
/**
 * Plugin activation routine.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

/**
 * Handles safe activation setup.
 */
final class Activator {

	/**
	 * Activate plugin foundation.
	 *
	 * Network-wide activation is intentionally blocked in M0 because network
	 * provisioning is not implemented yet.
	 *
	 * @param bool $network_wide Whether plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Network-wide activation is not supported in this version. Activate استوری برد زنده | StoryBoard Live per site.', 'sh-sequence-engine' ),
				esc_html__( 'استوری برد زنده | StoryBoard Live', 'sh-sequence-engine' ),
				array( 'back_link' => true )
			);
		}

		SchemaManager::install();
		Capabilities::grant_to_administrator();

		if ( null === get_option( 'shseq_delete_data_on_uninstall', null ) ) {
			add_option( 'shseq_delete_data_on_uninstall', false, '', false );
		}
	}
}

<?php
/**
 * Plugin capability definitions.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Central capability registry.
 */
final class Capabilities {

	/**
	 * Return every primitive capability owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function all() {
		$cpt_caps = SequencePostType::primitive_capabilities();

		$plugin_caps = array(
			'manage_shseq_settings',
			'manage_shseq_advanced',
			'import_shseq_assets',
			'rollback_shseq_sequences',
		);

		return array_values( array_unique( array_merge( $cpt_caps, $plugin_caps ) ) );
	}

	/**
	 * Grant M0 capabilities to the Administrator role only.
	 *
	 * @return void
	 */
	public static function grant_to_administrator() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}
}

<?php
/**
 * Schema version management.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

/**
 * Owns plugin schema versioning and future migrations.
 */
final class SchemaManager {

	const OPTION_NAME = 'shseq_schema_version';

	/**
	 * Register schema hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Install initial schema marker.
	 *
	 * @return void
	 */
	public static function install() {
		$stored_version = get_option( self::OPTION_NAME, null );

		if ( null === $stored_version ) {
			add_option( self::OPTION_NAME, SHSEQ_SCHEMA_VERSION, '', false );
			return;
		}

		if ( (int) $stored_version < SHSEQ_SCHEMA_VERSION ) {
			update_option( self::OPTION_NAME, SHSEQ_SCHEMA_VERSION, false );
		}
	}

	/**
	 * Run future schema upgrades only in admin context.
	 *
	 * M0 has no migration steps yet; this method intentionally establishes the
	 * upgrade pipeline before later milestones add persistent structures.
	 *
	 * A static flag prevents redundant DB reads on pages that fire admin_init
	 * multiple times within a single request.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		static $checked = false;
		if ( $checked ) {
			return;
		}
		$checked = true;

		$stored_version = (int) get_option( self::OPTION_NAME, 0 );

		if ( $stored_version >= SHSEQ_SCHEMA_VERSION ) {
			return;
		}

		if ( get_transient( 'shseq_migration_lock' ) ) {
			return;
		}

		set_transient( 'shseq_migration_lock', 1, MINUTE_IN_SECONDS );

		try {
			// No migrations exist before schema version 1.
			update_option( self::OPTION_NAME, SHSEQ_SCHEMA_VERSION, false );
		} finally {
			delete_transient( 'shseq_migration_lock' );
		}
	}
}

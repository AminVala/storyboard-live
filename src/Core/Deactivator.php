<?php
/**
 * Plugin deactivation routine.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

/**
 * Handles temporary cleanup only.
 */
final class Deactivator {

	/**
	 * Deactivate plugin without deleting persistent content.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'shseq_migration_lock' );
	}
}

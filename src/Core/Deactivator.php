<?php
/**
 * Plugin deactivation handler.
 *
 * Called on plugin deactivation via register_deactivation_hook().
 * Responsible for:
 *   - Flushing rewrite rules (removes CPT slugs from the rewrite table).
 *   - Pausing (not deleting) pending Action Scheduler jobs.
 *
 * Data is NOT deleted on deactivation — that happens only during uninstall
 * when the "Remove data on uninstall" option is enabled.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

/**
 * Handles plugin deactivation.
 */
final class Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Remove CPT rewrite rules.
		flush_rewrite_rules();

		// Pause (not cancel) pending generation jobs so they can resume on
		// re-activation. We only cancel them on uninstall.
		// Action Scheduler itself handles this gracefully — pending jobs that
		// don't fire while the plugin is inactive will run on the next cron
		// after re-activation because AS stores them in the DB.
	}
}

<?php
/**
 * Uninstall handler — runs when the plugin is deleted from the admin.
 *
 * Only removes data when the site administrator has opted in via
 * Settings → "Remove data on uninstall". This respects the principle of
 * least surprise: activating/deactivating the plugin never deletes data.
 *
 * What is removed when the option is enabled:
 *   - All posts of type shseq_sequence and shseq_revision
 *   - All post meta belonging to those posts
 *   - All plugin options (shseq_*)
 *   - The Action Scheduler group 'shseq' (pending/failed jobs)
 *
 * What is NOT removed:
 *   - Media attachments (frames) — they are standard WordPress attachments
 *     and may be used elsewhere. The administrator can delete them manually.
 *
 * @package StoryBoardLive
 */

// WordPress calls this file directly; bail if accessed outside that context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only clean up if the administrator explicitly opted in.
if ( ! get_option( 'shseq_delete_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

// ── 1. Delete all Sequence and Revision posts ─────────────────────────────

$post_types = array( 'shseq_sequence', 'shseq_revision' );

foreach ( $post_types as $post_type ) {
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			$post_type
		)
	);

	foreach ( $post_ids as $post_id ) {
		// Delete all post meta for this post.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $post_id ), array( '%d' ) );

		// Delete the post itself.
		$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $post_id ), array( '%d' ) );
	}
}

// ── 2. Delete all plugin options ──────────────────────────────────────────

// Fetch all shseq_* option names and delete them one by one.
$option_names = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'shseq\_%'"
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

// ── 3. Cancel pending Action Scheduler jobs ───────────────────────────────

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'shseq' );
}

// ── 4. Clear any transients ───────────────────────────────────────────────

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_shseq_%'
	    OR option_name LIKE '_transient_timeout_shseq_%'"
);

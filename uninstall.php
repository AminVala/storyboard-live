<?php
/**
 * Plugin uninstall routine.
 *
 * SECURITY FIX [SEC-004]: Custom capabilities must be removed from all roles
 * when the plugin is deleted. Without this file, all custom caps added during
 * activation (edit_shseq_sequences, manage_shseq_settings, etc.) persist
 * permanently in the wp_options serialized role data. A later plugin with the
 * same capability names could inadvertently inherit elevated access.
 *
 * This file is executed by WordPress core only when the user explicitly deletes
 * the plugin from wp-admin — not on deactivation.
 *
 * @package StoryBoardLive
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Only wipe data if the site administrator opted in. This protects users who
// deactivate → delete → reinstall from losing all their sequences.
if ( ! get_option( 'shseq_delete_data_on_uninstall', false ) ) {
	// Even when data is kept, capabilities must always be cleaned up so they
	// do not linger as orphaned role entries after the plugin is gone.
	shseq_uninstall_remove_capabilities();
	return;
}

// ------------------------------------------------------------------
// Full data wipe path: capabilities + options + post-meta.
// ------------------------------------------------------------------

shseq_uninstall_remove_capabilities();
shseq_uninstall_remove_options();
shseq_uninstall_remove_posts();

// ------------------------------------------------------------------
// Helper functions (file-scoped, no autoloader available here).
// ------------------------------------------------------------------

/**
 * Remove all plugin-registered capabilities from every role.
 *
 * Covers both CPT primitive capabilities and plugin-specific caps.
 *
 * @return void
 */
function shseq_uninstall_remove_capabilities() {
	$caps = array(
		// CPT primitive capabilities.
		'edit_shseq_sequences',
		'edit_others_shseq_sequences',
		'publish_shseq_sequences',
		'read_private_shseq_sequences',
		'delete_shseq_sequences',
		'delete_private_shseq_sequences',
		'delete_published_shseq_sequences',
		'delete_others_shseq_sequences',
		'edit_private_shseq_sequences',
		'edit_published_shseq_sequences',
		'create_shseq_sequences',
		// Plugin-level capabilities.
		'manage_shseq_settings',
		'manage_shseq_advanced',
		'import_shseq_assets',
		'rollback_shseq_sequences',
	);

	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Remove all plugin options.
 *
 * @return void
 */
function shseq_uninstall_remove_options() {
	delete_option( 'shseq_schema_version' );
	delete_option( 'shseq_delete_data_on_uninstall' );
	delete_transient( 'shseq_migration_lock' );
}

/**
 * Delete all Sequence and Revision posts and their meta.
 *
 * Uses direct DB queries to avoid loading the full WordPress plugin stack
 * in an uninstall context where custom post types are not registered.
 *
 * @return void
 */
function shseq_uninstall_remove_posts() {
	global $wpdb;

	$post_types = array( 'shseq_sequence', 'shseq_revision' );

	foreach ( $post_types as $post_type ) {
		// Collect all post IDs of this type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				$post_type
			)
		);

		if ( empty( $ids ) ) {
			continue;
		}

		// Delete post meta first to avoid orphaned rows.
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders})", $ids )
		);

		// Delete the posts themselves.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", $post_type )
		);
	}
}

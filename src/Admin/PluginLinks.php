<?php
/**
 * Plugin list table enhancements.
 *
 * BUG-05 FIX: `manage_shseq_settings` was used in action_links() but
 * was never registered as a WordPress capability. Changed to `manage_options`
 * (the standard WP capability for plugin settings pages) so the Settings
 * link always appears for administrators.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Adds action links and meta links to the plugin row.
 */
final class PluginLinks {

	/** Register hooks. */
	public function register_hooks() {
		add_filter( 'plugin_action_links_' . SHSEQ_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . SHSEQ_BASENAME, array( $this, 'network_action_links' ) );
	}

	/**
	 * Add Settings and Dashboard links to the plugin row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$custom = array();

		// BUG-05 FIX: was `manage_shseq_settings` (undefined cap) — changed to `manage_options`.
		if ( current_user_can( 'manage_options' ) ) {
			$custom[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'sh-sequence-engine' )
			);
		}

		if ( current_user_can( 'edit_shseq_sequences' ) ) {
			$custom[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=shseq-dashboard' ) ),
				esc_html__( 'Dashboard', 'sh-sequence-engine' )
			);
		}

		return array_merge( $custom, $links );
	}

	/**
	 * On network-admin (Multisite), show a note — network activation not supported.
	 *
	 * @param string[] $links Existing network action links.
	 * @return string[]
	 */
	public function network_action_links( $links ) {
		return array(
			'<span style="color:#787c82">' . esc_html__( 'Activate per site — network activation not supported.', 'sh-sequence-engine' ) . '</span>',
		);
	}
}

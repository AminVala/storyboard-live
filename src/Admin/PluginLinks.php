<?php
/**
 * Plugin list table enhancements.
 *
 * Adds quick-access links under the plugin name on the Plugins page:
 *   - Settings
 *   - Sequences
 *
 * Also adds a "Deactivation" guard for Multisite network admins who try to
 * network-activate the plugin (which is not supported).
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
	 * Add Settings and Sequences links to the plugin row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$custom = array();

		if ( current_user_can( 'manage_shseq_settings' ) ) {
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
	 * On network-admin (Multisite), show a "Per-site only" note instead of
	 * normal action links, since network activation is not supported.
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

<?php
/**
 * Scoped admin assets.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Loads dashboard styles only on the plugin dashboard.
 */
final class AdminAssets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue dashboard styles only where needed.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		$screen = get_current_screen();
		$is_dashboard = 'toplevel_page_' . AdminMenu::MENU_SLUG === $hook_suffix;
		$is_templates = AdminMenu::MENU_SLUG . '_page_' . TemplatesPage::PAGE_SLUG === $hook_suffix;
		$is_sequence_edit = $screen && 'shseq_sequence' === $screen->post_type && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_dashboard && ! $is_templates && ! $is_sequence_edit ) {
			return;
		}

		wp_enqueue_style(
			'shseq-admin-dashboard',
			SHSEQ_URL . 'assets/admin/dashboard.css',
			array(),
			SHSEQ_VERSION
		);

		if ( is_rtl() ) {
			wp_enqueue_style(
				'shseq-admin-dashboard-rtl',
				SHSEQ_URL . 'assets/admin/dashboard-rtl.css',
				array( 'shseq-admin-dashboard' ),
				SHSEQ_VERSION
			);
		}

		// The Golden Master picker only belongs on the Sequence editor screens.
		if ( $is_sequence_edit ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'shseq-golden-master',
				SHSEQ_URL . 'assets/admin/golden-master.min.js',
				array(),
				SHSEQ_VERSION,
				true
			);
		}
	}
}

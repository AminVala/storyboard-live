<?php
/**
 * Admin navigation — updated to add "All Sequences" page.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Registers the plugin top-level menu before CPT submenus are attached.
 */
final class AdminMenu {

	const MENU_SLUG = 'shseq-dashboard';

	/** @var DashboardPage */
	private $dashboard_page;

	/** @var TemplatesPage */
	private $templates_page;

	/** @var AllSequencesPage */
	private $all_sequences_page;

	public function __construct(
		DashboardPage $dashboard_page,
		TemplatesPage $templates_page,
		AllSequencesPage $all_sequences_page
	) {
		$this->dashboard_page     = $dashboard_page;
		$this->templates_page     = $templates_page;
		$this->all_sequences_page = $all_sequences_page;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ), 9 );
	}

	public function register(): void {
		// Top-level
		add_menu_page(
			__( 'استوری برد زنده | StoryBoard Live', 'sh-sequence-engine' ),
			__( 'StoryBoard Live', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' ),
			'dashicons-images-alt2',
			58
		);

		// Dashboard
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard — StoryBoard Live', 'sh-sequence-engine' ),
			__( 'Dashboard', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' )
		);

		// All Sequences (custom page)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Sequences — StoryBoard Live', 'sh-sequence-engine' ),
			__( 'All Sequences', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			AllSequencesPage::PAGE_SLUG,
			array( $this->all_sequences_page, 'render' )
		);

		// Ready Templates
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Ready Templates — StoryBoard Live', 'sh-sequence-engine' ),
			__( 'Ready Templates', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			TemplatesPage::PAGE_SLUG,
			array( $this->templates_page, 'render' )
		);
	}
}

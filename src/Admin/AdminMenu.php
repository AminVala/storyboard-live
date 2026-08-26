<?php
/**
 * Admin navigation.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Registers the plugin top-level menu before CPT submenus are attached.
 */
final class AdminMenu {

	const MENU_SLUG = 'shseq-dashboard';

	/**
	 * Dashboard renderer.
	 *
	 * @var DashboardPage
	 */
	private $dashboard_page;

	/**
	 * Ready templates renderer.
	 *
	 * @var TemplatesPage
	 */
	private $templates_page;

	/**
	 * Constructor.
	 *
	 * @param DashboardPage $dashboard_page Dashboard page renderer.
	 * @param TemplatesPage $templates_page Ready templates renderer.
	 */
	public function __construct( DashboardPage $dashboard_page, TemplatesPage $templates_page ) {
		$this->dashboard_page = $dashboard_page;
		$this->templates_page = $templates_page;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register' ), 9 );
	}

	/**
	 * Register top-level dashboard and first submenu label.
	 *
	 * @return void
	 */
	public function register() {
		add_menu_page(
			__( 'استوری برد زنده | StoryBoard Live', 'sh-sequence-engine' ),
			__( 'StoryBoard Live', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' ),
			'dashicons-images-alt2',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'استوری برد زنده | StoryBoard Live Dashboard', 'sh-sequence-engine' ),
			__( 'Dashboard', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::MENU_SLUG,
			array( $this->dashboard_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Ready Templates', 'sh-sequence-engine' ),
			__( 'Ready Templates', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			TemplatesPage::PAGE_SLUG,
			array( $this->templates_page, 'render' )
		);
	}
}

<?php
/**
 * Main plugin composition root — Sprint 1 update.
 *
 * New in Sprint 1:
 *   - ContentStepsMetaBox  (replaces LiveContentMetaBox)
 *   - FrameUploadMetaBox   (new frame management)
 *   - LicenseManager gate on create_new_sequence admin notice
 *   - SequencePreview, SequenceDuplicator, AdminBar, PluginLinks,
 *     FallbackNotice, SettingsPage wired (were previously defined but
 *     not registered in boot()).
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine;

use ShahreHonar\SequenceEngine\Admin\AdminAssets;
use ShahreHonar\SequenceEngine\Admin\AdminBar;
use ShahreHonar\SequenceEngine\Admin\AdminMenu;
use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Admin\DashboardPage;
use ShahreHonar\SequenceEngine\Admin\FallbackNotice;
use ShahreHonar\SequenceEngine\Admin\FrameUploadMetaBox;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterValidation;
use ShahreHonar\SequenceEngine\Admin\PluginLinks;
use ShahreHonar\SequenceEngine\Admin\SequenceDuplicator;
use ShahreHonar\SequenceEngine\Admin\SequencePreview;
use ShahreHonar\SequenceEngine\Admin\SequenceStructureMetaBox;
use ShahreHonar\SequenceEngine\Admin\SettingsPage;
use ShahreHonar\SequenceEngine\Admin\TemplatesPage;
use ShahreHonar\SequenceEngine\Content\RevisionPostType;
use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Core\SchemaManager;
use ShahreHonar\SequenceEngine\Frontend\DemoPlaceholder;
use ShahreHonar\SequenceEngine\Frontend\RuntimeAssets;
use ShahreHonar\SequenceEngine\Frontend\RuntimeManifest;
use ShahreHonar\SequenceEngine\Frontend\RuntimeShortcode;
use ShahreHonar\SequenceEngine\Frontend\SingleImageAssets;
use ShahreHonar\SequenceEngine\Frontend\SingleImageManifest;
use ShahreHonar\SequenceEngine\Frontend\SingleImageShortcode;
use ShahreHonar\SequenceEngine\I18n\I18n;
use ShahreHonar\SequenceEngine\License\LicenseManager;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

/**
 * Wires plugin services together.
 */
final class Plugin {

	/**
	 * Boot plugin services.
	 *
	 * @return void
	 */
	public function boot() {

		// ----------------------------------------------------------------
		// Core — always runs.
		// ----------------------------------------------------------------

		$i18n = new I18n();
		$i18n->register_hooks();

		$schema_manager = new SchemaManager();
		$schema_manager->register_hooks();

		$sequence_post_type = new SequencePostType();
		$sequence_post_type->register_hooks();

		$revision_post_type = new RevisionPostType();
		$revision_post_type->register_hooks();

		// ----------------------------------------------------------------
		// Frontend — runs on both front and admin for shortcode parsing.
		// ----------------------------------------------------------------

		$runtime_manifest = new RuntimeManifest();
		$runtime_assets   = new RuntimeAssets( $runtime_manifest );
		$runtime_assets->register_hooks();

		$runtime_shortcode = new RuntimeShortcode( $runtime_assets, $runtime_manifest );
		$runtime_shortcode->register_hooks();

		// Single-image runtime (current public-facing engine).
		$single_manifest  = new SingleImageManifest();
		$single_assets    = new SingleImageAssets();
		$single_assets->register_hooks();
		$single_shortcode = new SingleImageShortcode( $single_manifest, $single_assets );
		$single_shortcode->register_hooks();

		// Demo placeholder — renders when no frames are present.
		$demo = new DemoPlaceholder();
		$demo->register_hooks();

		// ----------------------------------------------------------------
		// Admin only.
		// ----------------------------------------------------------------

		if ( ! is_admin() ) {
			return;
		}

		$template_catalog  = new TemplateCatalog();
		$templates_page    = new TemplatesPage( $template_catalog );
		$structure_box     = new SequenceStructureMetaBox();
		$golden_box        = new GoldenMasterMetaBox();

		// Sprint 1: replaced LiveContentMetaBox with ContentStepsMetaBox.
		$content_steps_box = new ContentStepsMetaBox();

		// Sprint 1: new frame management meta box.
		$frame_upload_box  = new FrameUploadMetaBox();

		$golden_validation = new GoldenMasterValidation();
		$dashboard_page    = new DashboardPage();
		$settings_page     = new SettingsPage();
		$admin_menu        = new AdminMenu( $dashboard_page, $templates_page );
		$admin_assets      = new AdminAssets();
		$admin_bar         = new AdminBar();
		$plugin_links      = new PluginLinks();
		$fallback_notice   = new FallbackNotice();
		$seq_preview       = new SequencePreview();
		$seq_duplicator    = new SequenceDuplicator();

		$templates_page->register_hooks();
		$structure_box->register_hooks();
		$golden_box->register_hooks();
		$content_steps_box->register_hooks();
		$frame_upload_box->register_hooks();
		$golden_validation->register_hooks();
		$admin_menu->register_hooks();
		$admin_assets->register_hooks();
		$admin_bar->register_hooks();
		$plugin_links->register_hooks();
		$fallback_notice->register_hooks();
		$seq_preview->register_hooks();
		$seq_duplicator->register_hooks();
		$settings_page->register_hooks();

		// Sprint 1: limit New Sequence creation on Free plan.
		$this->register_quota_gate();
	}

	/**
	 * Show an admin notice and block CPT creation when the Free-plan quota is full.
	 *
	 * @return void
	 */
	private function register_quota_gate() {
		add_action(
			'admin_notices',
			static function () {
				if ( LicenseManager::can_create_hero() ) {
					return;
				}

				// Only show the notice on the Sequences list screen.
				$screen = get_current_screen();
				if ( ! $screen || 'edit-shseq_sequence' !== $screen->id ) {
					return;
				}

				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html( LicenseManager::upgrade_notice() )
				);
			}
		);

		// Prevent wp-admin "Add New" from creating beyond the quota.
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps, $args ) {
				if ( ! in_array( 'create_shseq_sequences', (array) $caps, true ) ) {
					return $allcaps;
				}
				if ( ! LicenseManager::can_create_hero() ) {
					$allcaps['create_shseq_sequences'] = false;
				}
				return $allcaps;
			},
			10,
			3
		);
	}
}

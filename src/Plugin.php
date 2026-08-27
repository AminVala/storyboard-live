<?php
/**
 * Main plugin composition root — Sprint 3 update.
 *
 * New in Sprint 3:
 *   - OpenAIProvider     (DALL·E 3 Start Frame generation)
 *   - ReplicateProvider  (FILM/RIFE frame interpolation)
 *   - FrameGenerationJob (Action Scheduler async pipeline)
 *   - SettingsPage       (updated with BYOK API key fields + test buttons)
 *
 * Sprint 1 & 2 services remain wired as before.
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
use ShahreHonar\SequenceEngine\AI\OpenAIProvider;
use ShahreHonar\SequenceEngine\AI\ReplicateProvider;
use ShahreHonar\SequenceEngine\Blocks\FrameSequenceBlock;
use ShahreHonar\SequenceEngine\Content\RevisionPostType;
use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Core\SchemaManager;
use ShahreHonar\SequenceEngine\Frontend\DemoPlaceholder;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceAssets;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceManifest;
use ShahreHonar\SequenceEngine\Frontend\FrameSequenceShortcode;
use ShahreHonar\SequenceEngine\I18n\I18n;
use ShahreHonar\SequenceEngine\Jobs\FrameGenerationJob;
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

		// ── Core ─────────────────────────────────────────────────────────

		$i18n = new I18n();
		$i18n->register_hooks();

		$schema_manager = new SchemaManager();
		$schema_manager->register_hooks();

		$sequence_post_type = new SequencePostType();
		$sequence_post_type->register_hooks();

		$revision_post_type = new RevisionPostType();
		$revision_post_type->register_hooks();

		// ── Sprint 3: AI pipeline (runs on front + admin for AS callbacks) ─

		$openai    = new OpenAIProvider();
		$replicate = new ReplicateProvider();
		$gen_job   = new FrameGenerationJob( $openai, $replicate );
		$gen_job->register_hooks();

		// ── Sprint 2: Frame sequence frontend ────────────────────────────

		$frame_assets    = new FrameSequenceAssets();
		$frame_manifest  = new FrameSequenceManifest();
		$frame_shortcode = new FrameSequenceShortcode( $frame_manifest, $frame_assets );
		$frame_block     = new FrameSequenceBlock( $frame_shortcode );

		$frame_assets->register_hooks();
		$frame_shortcode->register_hooks();
		$frame_block->register_hooks();

		// Demo placeholder.
		$demo = new DemoPlaceholder();
		$demo->register_hooks();

		// ── Admin only ───────────────────────────────────────────────────

		if ( ! is_admin() ) {
			return;
		}

		$template_catalog  = new TemplateCatalog();
		$templates_page    = new TemplatesPage( $template_catalog );
		$structure_box     = new SequenceStructureMetaBox();
		$golden_box        = new GoldenMasterMetaBox();
		$content_steps_box = new ContentStepsMetaBox();
		$frame_upload_box  = new FrameUploadMetaBox();
		$golden_validation = new GoldenMasterValidation();
		$dashboard_page    = new DashboardPage();
		$settings_page     = new SettingsPage(); // ← Sprint 3 updated version
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

		// Free plan quota gate.
		$this->register_quota_gate();
	}

	/**
	 * Block creation beyond the Free plan limit.
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

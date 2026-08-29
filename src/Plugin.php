<?php
namespace ShahreHonar\SequenceEngine;

use ShahreHonar\SequenceEngine\Admin\AdminAssets;
use ShahreHonar\SequenceEngine\Admin\AdminBar;
use ShahreHonar\SequenceEngine\Admin\AdminMenu;
use ShahreHonar\SequenceEngine\Admin\AllSequencesPage;
use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Admin\DashboardPage;
use ShahreHonar\SequenceEngine\Admin\FallbackNotice;
use ShahreHonar\SequenceEngine\Admin\FrameUploadMetaBox;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Admin\PluginLinks;
use ShahreHonar\SequenceEngine\Admin\SequenceDuplicator;
use ShahreHonar\SequenceEngine\Admin\SequencePreview;
use ShahreHonar\SequenceEngine\Admin\SequenceStructureMetaBox;
use ShahreHonar\SequenceEngine\Admin\SequenceWizardPage;
use ShahreHonar\SequenceEngine\Admin\SettingsPage;
use ShahreHonar\SequenceEngine\Admin\TemplatesPage;
use ShahreHonar\SequenceEngine\Admin\WizardStep4Override;
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

final class Plugin {

	public function boot() {

		// ── Core ─────────────────────────────────────────────────────────
		( new I18n() )->register_hooks();
		( new SchemaManager() )->register_hooks();
		( new SequencePostType() )->register_hooks();
		( new RevisionPostType() )->register_hooks();

		// ── AI pipeline ───────────────────────────────────────────────────
		$openai    = new OpenAIProvider();
		$replicate = new ReplicateProvider();
		( new FrameGenerationJob( $openai, $replicate ) )->register_hooks();

		// ── Frontend ──────────────────────────────────────────────────────
		$frame_assets    = new FrameSequenceAssets();
		$frame_manifest  = new FrameSequenceManifest();
		$frame_shortcode = new FrameSequenceShortcode( $frame_manifest, $frame_assets );
		$frame_assets->register_hooks();
		$frame_shortcode->register_hooks();
		( new FrameSequenceBlock( $frame_shortcode ) )->register_hooks();
		( new DemoPlaceholder() )->register_hooks();

		// ── Admin only ───────────────────────────────────────────────────
		if ( ! is_admin() ) {
			return;
		}

		$template_catalog     = new TemplateCatalog();
		$dashboard_page       = new DashboardPage();
		$templates_page       = new TemplatesPage( $template_catalog );
		$all_sequences_page   = new AllSequencesPage();
		$sequence_wizard_page = new SequenceWizardPage( $template_catalog );

		// Wizard V3 — primary creation path (Section 4)
		$sequence_wizard_page->register_hooks();

		// WizardStep4Override — fixes mode-aware generation (must register AFTER wizard)
		( new WizardStep4Override( $sequence_wizard_page ) )->register_hooks();

		// All Sequences page: AJAX + cache-invalidation hooks
		$all_sequences_page->register_hooks();

		// Admin menu — Dashboard + All Sequences + Ready Templates
		( new AdminMenu( $dashboard_page, $templates_page, $all_sequences_page ) )->register_hooks();

		// Meta boxes (editor fallback)
		( new SequenceStructureMetaBox() )->register_hooks();
		( new GoldenMasterMetaBox() )->register_hooks();
		( new ContentStepsMetaBox() )->register_hooks();
		( new FrameUploadMetaBox() )->register_hooks();

		( new AdminAssets() )->register_hooks();
		( new AdminBar() )->register_hooks();
		( new PluginLinks() )->register_hooks();
		( new FallbackNotice() )->register_hooks();
		( new SequencePreview() )->register_hooks();
		( new SequenceDuplicator() )->register_hooks();
		( new SettingsPage() )->register_hooks();
		$templates_page->register_hooks();

		$this->register_quota_gate();
	}

	private function register_quota_gate() {
		add_action( 'admin_notices', static function () {
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
		} );

		add_filter( 'user_has_cap', static function ( $allcaps, $caps, $args ) {
			if ( ! in_array( 'create_shseq_sequences', (array) $caps, true ) ) {
				return $allcaps;
			}
			if ( ! LicenseManager::can_create_hero() ) {
				$allcaps['create_shseq_sequences'] = false;
			}
			return $allcaps;
		}, 10, 3 );
	}
}

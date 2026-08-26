<?php
/**
 * Updated Plugin::boot() — wires all new services from batch2 and batch3.
 *
 * This is a REFERENCE FILE showing the full updated boot() method.
 * Apply this diff to src/Plugin.php.
 *
 * New services wired in:
 *   - SequencePreview      (G01: standalone preview URL)
 *   - SequenceDuplicator   (G05: Duplicate row action)
 *   - SettingsPage         (G06: Settings submenu + save)
 *   - FallbackNotice       (G04: admin notice for fallback variants)
 *   - AdminBar             (new: admin bar on frontend sequence pages)
 *   - PluginLinks          (new: Settings/Dashboard links on Plugins page)
 *   - DemoPlaceholder      (G03: graceful fallback when demo frames absent)
 *   - GoldenMasterValidation (G22: file size/dimension/mime validation)
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine;

use ShahreHonar\SequenceEngine\Admin\AdminAssets;
use ShahreHonar\SequenceEngine\Admin\AdminBar;
use ShahreHonar\SequenceEngine\Admin\AdminMenu;
use ShahreHonar\SequenceEngine\Admin\DashboardPage;
use ShahreHonar\SequenceEngine\Admin\FallbackNotice;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Admin\LiveContentMetaBox;
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
        $i18n = new I18n();
        $i18n->register_hooks();

        $schema_manager = new SchemaManager();
        $schema_manager->register_hooks();

        $sequence_post_type = new SequencePostType();
        $sequence_post_type->register_hooks();

        $revision_post_type = new RevisionPostType();
        $revision_post_type->register_hooks();

        /* ── Frontend ─────────────────────────────────────────────── */

        $runtime_manifest = new RuntimeManifest();
        $runtime_assets   = new RuntimeAssets( $runtime_manifest );
        $runtime_assets->register_hooks();

        // Demo placeholder (graceful fallback when demo frames are absent).
        $demo_placeholder = new DemoPlaceholder();
        $demo_placeholder->register_hooks();

        // Register demo shortcodes AFTER the placeholder (placeholder overrides
        // when frames are absent via init:20; this runs at init default priority).
        $runtime_shortcode = new RuntimeShortcode( $runtime_assets, $runtime_manifest );
        $runtime_shortcode->register_hooks();

        // Single-image (Golden Master) shortcode.
        $single_manifest  = new SingleImageManifest();
        $single_assets    = new SingleImageAssets();
        $single_assets->register_hooks();
        $single_shortcode = new SingleImageShortcode( $single_manifest, $single_assets );
        $single_shortcode->register_hooks();

        // Sequence preview URL (frontend page, auth-gated).
        $preview = new SequencePreview();
        $preview->register_hooks();

        /* ── Admin bar (frontend + admin) ─────────────────────────── */
        $admin_bar = new AdminBar();
        $admin_bar->register_hooks();

        if ( ! is_admin() ) {
            return;
        }

        /* ── Admin-only services ──────────────────────────────────── */

        // Plugin list table enhancements.
        $plugin_links = new PluginLinks();
        $plugin_links->register_hooks();

        // Fallback variant notices.
        $fallback_notice = new FallbackNotice();
        $fallback_notice->register_hooks();

        // Sequence list table: Duplicate row action.
        $duplicator = new SequenceDuplicator();
        $duplicator->register_hooks();

        // Settings page.
        $settings_page = new SettingsPage();
        $settings_page->register_hooks();

        // Templates, structure editor, meta boxes, dashboard, menu, assets.
        $template_catalog = new TemplateCatalog();
        $templates_page   = new TemplatesPage( $template_catalog );
        $structure_box    = new SequenceStructureMetaBox();
        $golden_box       = new GoldenMasterMetaBox();
        $live_content_box = new LiveContentMetaBox();
        $dashboard_page   = new DashboardPage();
        $admin_menu       = new AdminMenu( $dashboard_page, $templates_page, $settings_page );
        $admin_assets     = new AdminAssets();

        $templates_page->register_hooks();
        $structure_box->register_hooks();
        $golden_box->register_hooks();
        $live_content_box->register_hooks();
        $admin_menu->register_hooks();
        $admin_assets->register_hooks();
    }
}

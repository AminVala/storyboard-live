<?php
/**
 * Main plugin composition root.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine;

use ShahreHonar\SequenceEngine\Admin\AdminAssets;
use ShahreHonar\SequenceEngine\Admin\AdminMenu;
use ShahreHonar\SequenceEngine\Admin\DashboardPage;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Admin\LiveContentMetaBox;
use ShahreHonar\SequenceEngine\Admin\SequenceStructureMetaBox;
use ShahreHonar\SequenceEngine\Admin\TemplatesPage;
use ShahreHonar\SequenceEngine\Content\RevisionPostType;
use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Core\SchemaManager;
use ShahreHonar\SequenceEngine\I18n\I18n;
use ShahreHonar\SequenceEngine\Frontend\RuntimeAssets;
use ShahreHonar\SequenceEngine\Frontend\RuntimeManifest;
use ShahreHonar\SequenceEngine\Frontend\RuntimeShortcode;
use ShahreHonar\SequenceEngine\Frontend\SingleImageAssets;
use ShahreHonar\SequenceEngine\Frontend\SingleImageManifest;
use ShahreHonar\SequenceEngine\Frontend\SingleImageShortcode;
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

		$runtime_manifest = new RuntimeManifest();
		$runtime_assets   = new RuntimeAssets( $runtime_manifest );
		$runtime_assets->register_hooks();

		$runtime_shortcode = new RuntimeShortcode( $runtime_assets, $runtime_manifest );
		$runtime_shortcode->register_hooks();

		// Single-image runtime: applies Production Sheet rules to one confirmed
		// Golden Master per sequence. This is the primary public shortcode.
		$single_manifest  = new SingleImageManifest();
		$single_assets    = new SingleImageAssets();
		$single_assets->register_hooks();
		$single_shortcode = new SingleImageShortcode( $single_manifest, $single_assets );
		$single_shortcode->register_hooks();

		if ( ! is_admin() ) {
			return;
		}

		$template_catalog = new TemplateCatalog();
		$templates_page   = new TemplatesPage( $template_catalog );
		$structure_box    = new SequenceStructureMetaBox();
		$golden_box       = new GoldenMasterMetaBox();
		$live_content_box = new LiveContentMetaBox();
		$dashboard_page   = new DashboardPage();
		$admin_menu       = new AdminMenu( $dashboard_page, $templates_page );
		$admin_assets     = new AdminAssets();

		$templates_page->register_hooks();
		$structure_box->register_hooks();
		$golden_box->register_hooks();
		$live_content_box->register_hooks();
		$admin_menu->register_hooks();
		$admin_assets->register_hooks();
	}
}

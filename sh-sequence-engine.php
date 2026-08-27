<?php
/**
 * Plugin Name:       StoryBoard Live
 * Plugin URI:        https://github.com/AminVala/storyboard-live
 * Description:       Turn any page hero into a cinematic scroll-driven frame sequence — no video, no JavaScript bloat.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Amin Vala
 * Author URI:        https://github.com/AminVala
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sh-sequence-engine
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package StoryBoardLive
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────

define( 'SHSEQ_VERSION',        '1.0.0' );
define( 'SHSEQ_FILE',           __FILE__ );
define( 'SHSEQ_DIR',            plugin_dir_path( __FILE__ ) );
define( 'SHSEQ_URL',            plugin_dir_url( __FILE__ ) );
define( 'SHSEQ_SLUG',           'sh-sequence-engine' );
// BUG-01 FIX: SHSEQ_BASENAME was never defined — used by I18n and PluginLinks.
define( 'SHSEQ_BASENAME',       plugin_basename( __FILE__ ) );
// BUG-02 FIX: SHSEQ_SCHEMA_VERSION was never defined — used by SchemaManager.
define( 'SHSEQ_SCHEMA_VERSION', 1 );

// ── Autoloader ────────────────────────────────────────────────────────────

$composer_autoload = SHSEQ_DIR . 'vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	// PSR-4 fallback for development / WP.org zip.
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'ShahreHonar\\SequenceEngine\\';
			$base   = SHSEQ_DIR . 'src/';

			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// ── Activation / Deactivation hooks ──────────────────────────────────────

register_activation_hook(
	__FILE__,
	array( 'ShahreHonar\\SequenceEngine\\Core\\Activator', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( 'ShahreHonar\\SequenceEngine\\Core\\Deactivator', 'deactivate' )
);

// ── Boot ──────────────────────────────────────────────────────────────────

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new \ShahreHonar\SequenceEngine\Plugin();
		$plugin->boot();
	}
);

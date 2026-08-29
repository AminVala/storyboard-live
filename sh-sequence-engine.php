<?php
/**
 * Plugin Name: StoryBoard Live — Scroll-Driven Visual Storytelling
 * Plugin URI:  https://wordpress.org/plugins/storyboard-live/
 * Description: Create scroll-driven visual stories from a single confirmed image — with live HTML overlays, cinematic camera animation, and smooth theme-header reveal. No frame exports, no custom JavaScript.
 * Version: 0.7.1
 * Author: Amin Akhyar
 * Author URI:  https://github.com/AminVala
 * Text Domain: sh-sequence-engine
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Tested up to: 6.8
 *
 * @package StoryBoardLive
 */

defined( 'ABSPATH' ) || exit;

define( 'SHSEQ_VERSION',        '0.7.1' );
define( 'SHSEQ_SCHEMA_VERSION', 1 );
define( 'SHSEQ_FILE',           __FILE__ );
define( 'SHSEQ_DIR',            plugin_dir_path( __FILE__ ) );
define( 'SHSEQ_URL',            plugin_dir_url( __FILE__ ) );
define( 'SHSEQ_BASENAME',       plugin_basename( __FILE__ ) );

$shseq_autoloader = SHSEQ_DIR . 'vendor/autoload.php';

if ( ! file_exists( $shseq_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: composer install command */
				esc_html__( 'StoryBoard Live is incomplete: vendor/autoload.php is missing. Run %s in the plugin directory or reinstall from WordPress.org.', 'sh-sequence-engine' ),
				'<code>composer install</code>'
			);
			echo '</p></div>';
		}
	);

	return;
}

require_once $shseq_autoloader;

use ShahreHonar\SequenceEngine\Core\Activator;
use ShahreHonar\SequenceEngine\Core\Deactivator;
use ShahreHonar\SequenceEngine\Plugin;

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new Plugin();
		$plugin->boot();
	}
);

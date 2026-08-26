<?php
/**
 * Plugin Name: استوری برد زنده | StoryBoard Live
 * Description: روایت‌های تصویری زنده، روان و ماندگار؛ همگام با اسکرول.
 * Version: 0.7.1
 * Author: امین اخیار
 * Text Domain: sh-sequence-engine
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package StoryBoardLive
 */

defined( 'ABSPATH' ) || exit;

define( 'SHSEQ_VERSION', '0.7.1' );
define( 'SHSEQ_SCHEMA_VERSION', 1 );
define( 'SHSEQ_FILE', __FILE__ );
define( 'SHSEQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHSEQ_URL', plugin_dir_url( __FILE__ ) );
define( 'SHSEQ_BASENAME', plugin_basename( __FILE__ ) );

$shseq_autoloader = SHSEQ_DIR . 'vendor/autoload.php';

if ( ! file_exists( $shseq_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'استوری برد زنده | StoryBoard Live is incomplete: vendor/autoload.php is missing. Build the plugin package with Composer before activation.' );
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

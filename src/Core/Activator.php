<?php
/**
 * Plugin activation handler.
 *
 * Called once on plugin activation via register_activation_hook().
 * Responsible for:
 *   - Creating / upgrading the DB schema (no custom tables currently,
 *     but reserved for future use).
 *   - Flushing rewrite rules so CPT permalinks work immediately.
 *   - Storing the plugin version to detect future upgrades.
 *   - Checking for the minimum PHP / WP version and deactivating
 *     gracefully if the environment is too old.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

/**
 * Handles plugin activation.
 */
final class Activator {

	const OPTION_VERSION     = 'shseq_version';
	const MIN_PHP            = '8.0';
	const MIN_WP             = '6.2';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		// Environment check.
		if ( ! self::check_environment() ) {
			return; // check_environment() deactivates the plugin and exits.
		}

		// Register CPTs so flush_rewrite_rules picks them up.
		// We call the registration methods directly rather than relying on
		// the init hook because activation runs before init fires.
		self::register_post_types();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Store current plugin version.
		update_option( self::OPTION_VERSION, SHSEQ_VERSION, false );

		// Action Scheduler availability notice — store a flag; the notice
		// is displayed by FallbackNotice on the next admin page load.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			update_option( 'shseq_as_missing_notice', 1, false );
		} else {
			delete_option( 'shseq_as_missing_notice' );
		}
	}

	/**
	 * Check PHP and WordPress version requirements.
	 *
	 * Deactivates the plugin and shows an admin notice if requirements
	 * are not met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	private static function check_environment() {
		$errors = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required version, 2: current version. */
				__( 'StoryBoard Live requires PHP %1$s or later. Your server is running PHP %2$s.', 'sh-sequence-engine' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required version, 2: current version. */
				__( 'StoryBoard Live requires WordPress %1$s or later. You are running %2$s.', 'sh-sequence-engine' ),
				self::MIN_WP,
				$wp_version
			);
		}

		if ( empty( $errors ) ) {
			return true;
		}

		// Deactivate and show error.
		deactivate_plugins( plugin_basename( SHSEQ_FILE ) );

		wp_die(
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>',
			esc_html__( 'Plugin Activation Error', 'sh-sequence-engine' ),
			array( 'back_link' => true )
		);

		return false; // Never reached, but satisfies static analysis.
	}

	/**
	 * Register CPTs inline so rewrite rules can be flushed during activation.
	 *
	 * @return void
	 */
	private static function register_post_types() {
		if ( ! post_type_exists( 'shseq_sequence' ) ) {
			register_post_type(
				'shseq_sequence',
				array(
					'public'      => true,
					'has_archive' => false,
					'rewrite'     => array( 'slug' => 'shseq-sequence' ),
					'supports'    => array( 'title' ),
				)
			);
		}
		if ( ! post_type_exists( 'shseq_revision' ) ) {
			register_post_type(
				'shseq_revision',
				array(
					'public'  => false,
					'rewrite' => false,
				)
			);
		}
	}
}

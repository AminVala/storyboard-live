<?php
/**
 * Plugin activation handler.
 *
 * BUG-04 FIX: Custom capabilities were never assigned to any role.
 * All primitive capabilities from SequencePostType::primitive_capabilities()
 * are now granted to the 'administrator' role on activation, so admins can
 * access the Sequences menu, create/edit/delete sequences, etc.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Core;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Handles plugin activation.
 */
final class Activator {

	const OPTION_VERSION     = 'shseq_version';
	const MIN_PHP            = '8.0';
	const MIN_WP             = '6.2';

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		if ( ! self::check_environment() ) {
			return;
		}

		// Register CPTs so flush_rewrite_rules picks them up.
		self::register_post_types();

		// BUG-04 FIX: Assign custom capabilities to admin role.
		self::assign_capabilities();

		flush_rewrite_rules();

		update_option( self::OPTION_VERSION, SHSEQ_VERSION, false );

		// Warn about missing Action Scheduler.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			update_option( 'shseq_as_missing_notice', 1, false );
		} else {
			delete_option( 'shseq_as_missing_notice' );
		}
	}

	/**
	 * Grant all custom capabilities to administrator role.
	 *
	 * Also grants the minimum subset to the 'editor' role so editors can
	 * create and manage sequences without needing admin access.
	 */
	private static function assign_capabilities() {
		$admin_role  = get_role( 'administrator' );
		$editor_role = get_role( 'editor' );

		// Full capability set for administrators.
		$all_caps = SequencePostType::primitive_capabilities();
		// Also add manage_shseq_settings so PluginLinks shows the Settings link.
		$all_caps[] = 'manage_shseq_settings';

		if ( $admin_role ) {
			foreach ( $all_caps as $cap ) {
				$admin_role->add_cap( $cap );
			}
		}

		// Editors get create/edit capabilities but NOT delete or publish.
		$editor_caps = array(
			'edit_shseq_sequences',
			'edit_private_shseq_sequences',
			'edit_published_shseq_sequences',
			'create_shseq_sequences',
			'read_private_shseq_sequences',
		);

		if ( $editor_role ) {
			foreach ( $editor_caps as $cap ) {
				$editor_role->add_cap( $cap );
			}
		}
	}

	/**
	 * Check PHP and WordPress version requirements.
	 *
	 * @return bool
	 */
	private static function check_environment() {
		$errors = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$errors[] = sprintf(
				__( 'StoryBoard Live requires PHP %1$s or later. Your server is running PHP %2$s.', 'sh-sequence-engine' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$errors[] = sprintf(
				__( 'StoryBoard Live requires WordPress %1$s or later. You are running %2$s.', 'sh-sequence-engine' ),
				self::MIN_WP,
				$wp_version
			);
		}

		if ( empty( $errors ) ) {
			return true;
		}

		deactivate_plugins( plugin_basename( SHSEQ_FILE ) );

		wp_die(
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>',
			esc_html__( 'Plugin Activation Error', 'sh-sequence-engine' ),
			array( 'back_link' => true )
		);

		return false;
	}

	/**
	 * Register CPTs inline during activation for rewrite rule flushing.
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
					'capability_type' => array( 'shseq_sequence', 'shseq_sequences' ),
					'map_meta_cap'    => true,
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

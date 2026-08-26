<?php
/**
 * Sequence duplication.
 *
 * Adds a "Duplicate" row action and admin-post handler for shseq_sequence CPT.
 * The duplicate inherits:
 *   - post title (appended with " — Copy")
 *   - _shseq_structure (deep copy)
 *   - _shseq_live_content (deep copy)
 *   - _shseq_golden_master (attachment IDs — NOT confirmations, must re-confirm)
 *   - _shseq_variant_confirmed is reset so the editor must re-confirm each master
 *   - _shseq_template_id and _shseq_template_version (for reference)
 *
 * The duplicate is always created as 'draft' regardless of the source status.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Handles sequence duplication via a row action.
 */
final class SequenceDuplicator {

	const ACTION = 'shseq_duplicate';

	/** Register hooks. */
	public function register_hooks() {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_duplicate' ) );
	}

	/**
	 * Add "Duplicate" to the Sequence list table row actions.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( SequencePostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'create_shseq_sequences' ) && ! current_user_can( 'edit_shseq_sequences' ) ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'action'       => self::ACTION,
				'post'         => $post->ID,
				'_shseq_nonce' => wp_create_nonce( self::ACTION . ':' . $post->ID ),
			),
			admin_url( 'admin-post.php' )
		);

		$actions['shseq_duplicate'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Duplicate', 'sh-sequence-engine' )
		);

		return $actions;
	}

	/**
	 * Handle the duplication request.
	 */
	public function handle_duplicate() {
		if ( ! current_user_can( 'create_shseq_sequences' ) && ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate sequences.', 'sh-sequence-engine' ) );
		}

		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
		$nonce   = isset( $_REQUEST['_shseq_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_shseq_nonce'] ) ) : '';

		if ( ! $post_id || ! wp_verify_nonce( $nonce, self::ACTION . ':' . $post_id ) ) {
			wp_die( esc_html__( 'The duplicate request could not be verified.', 'sh-sequence-engine' ) );
		}

		$source = get_post( $post_id );
		if ( ! $source || SequencePostType::POST_TYPE !== $source->post_type ) {
			wp_die( esc_html__( 'Sequence not found.', 'sh-sequence-engine' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this sequence.', 'sh-sequence-engine' ) );
		}

		/* ── Create the duplicate post ───────────────────────────── */
		$source_title = get_the_title( $source );
		$new_title    = sprintf(
			/* translators: %s: original sequence title */
			__( '%s — Copy', 'sh-sequence-engine' ),
			$source_title
		);

		$new_id = wp_insert_post(
			array(
				'post_type'   => SequencePostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $new_title ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'        => SequencePostType::POST_TYPE,
						'shseq_dup_error'  => '1',
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}

		/* ── Copy meta ───────────────────────────────────────────── */
		$meta_keys_to_copy = array(
			'_shseq_structure',
			'_shseq_live_content',
			'_shseq_golden_master',   // attachment IDs are shared (no deep copy needed)
			'_shseq_template_id',
			'_shseq_template_version',
		);

		foreach ( $meta_keys_to_copy as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				// Deep clone arrays to avoid unintentional reference sharing.
				update_post_meta( $new_id, $key, is_array( $value ) ? $value : $value );
			}
		}

		// Confirmations are intentionally NOT copied — editor must re-confirm
		// each variant master before the sequence goes live.
		update_post_meta( $new_id, '_shseq_variant_confirmed', array(
			'desktop' => false,
			'tablet'  => false,
			'mobile'  => false,
		) );

		/* ── Redirect to edit screen ─────────────────────────────── */
		$edit_url = get_edit_post_link( $new_id, 'raw' );
		if ( $edit_url ) {
			wp_safe_redirect( add_query_arg( 'shseq_duplicated', '1', $edit_url ) );
		} else {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'       => SequencePostType::POST_TYPE,
						'shseq_duplicated' => '1',
					),
					admin_url( 'edit.php' )
				)
			);
		}
		exit;
	}
}

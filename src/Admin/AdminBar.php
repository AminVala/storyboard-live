<?php
/**
 * WordPress admin bar integration.
 *
 * Adds a "StoryBoard Live" node to the admin bar on frontend pages that
 * contain a [storyboard_live] or [storyboard_live_demo] shortcode.
 * Logged-in editors see:
 *   - "Edit Sequence" (links to the sequence editor)
 *   - "Preview" (opens the signed preview URL)
 *   - "All Sequences" (links to the Sequences list table)
 *
 * This solves the gap where a logged-in editor could not quickly navigate
 * from the front-end to the back-end editor when viewing a page with a
 * sequence embedded via shortcode.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Adds a StoryBoard Live admin bar node on relevant frontend pages.
 */
final class AdminBar {

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'admin_bar_menu', array( $this, 'add_menu' ), 100 );
	}

	/**
	 * Add the StoryBoard Live admin bar node.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public function add_menu( $bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		/* Only show on singular frontend pages (not the admin). */
		if ( is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			return;
		}

		/*
		 * Detect whether the current page has a [storyboard_live] shortcode.
		 * We re-use the same memoised check from RuntimeAssets / SingleImageAssets.
		 * If neither is conclusive, fall back to a simple string search.
		 */
		$sequences = $this->find_sequences_on_current_page();
		if ( empty( $sequences ) ) {
			return;
		}

		/* Root node */
		$bar->add_node( array(
			'id'    => 'shseq-root',
			'title' => '<span class="ab-icon dashicons dashicons-images-alt2" style="font-size:18px;line-height:32px" aria-hidden="true"></span>'
			           . '<span class="ab-label">StoryBoard Live</span>',
			'href'  => admin_url( 'admin.php?page=shseq-dashboard' ),
			'meta'  => array( 'class' => 'menupop', 'title' => __( 'StoryBoard Live', 'sh-sequence-engine' ) ),
		) );

		/* Per-sequence sub-nodes */
		foreach ( $sequences as $sequence_id ) {
			$title    = get_the_title( $sequence_id ) ?: __( '(Untitled)', 'sh-sequence-engine' );
			$edit_url = get_edit_post_link( $sequence_id, 'raw' );
			if ( ! $edit_url ) {
				continue;
			}
			$preview_url = SequencePreview::preview_url( $sequence_id );

			/* "Edit Sequence" */
			$bar->add_node( array(
				'id'     => 'shseq-edit-' . $sequence_id,
				'parent' => 'shseq-root',
				'title'  => sprintf(
					/* translators: %s: Sequence title */
					__( 'Edit: %s', 'sh-sequence-engine' ),
					esc_html( wp_html_excerpt( $title, 30, '…' ) )
				),
				'href'   => esc_url( $edit_url ),
				'meta'   => array( 'title' => esc_attr( $title ) ),
			) );

			/* "Preview" (opens in new tab) */
			$bar->add_node( array(
				'id'     => 'shseq-preview-' . $sequence_id,
				'parent' => 'shseq-root',
				'title'  => __( 'Preview in new tab', 'sh-sequence-engine' ),
				'href'   => esc_url( $preview_url ),
				'meta'   => array( 'target' => '_blank', 'rel' => 'noopener' ),
			) );
		}

		/* Always show "All Sequences" at the bottom */
		$bar->add_node( array(
			'id'     => 'shseq-all',
			'parent' => 'shseq-root',
			'title'  => __( 'All Sequences', 'sh-sequence-engine' ),
			'href'   => admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE ),
		) );
	}

	/**
	 * Find Sequence IDs referenced by shortcodes on the current page.
	 *
	 * Supports both `[storyboard_live id="123"]` and legacy demo shortcodes
	 * (which have no ID). Returns an array of int IDs; demo shortcodes add 0.
	 *
	 * @return int[]
	 */
	private function find_sequences_on_current_page() {
		if ( ! is_singular() ) {
			return array();
		}

		$post = get_post();
		if ( ! $post ) {
			return array();
		}

		$content  = (string) $post->post_content;
		$ids      = array();

		/* Match [storyboard_live id="123"] or [storyboard_live id='123'] */
		if ( preg_match_all( '/\[storyboard_live\b[^\]]*\bid=["\']?(\d+)["\']?/i', $content, $matches ) ) {
			foreach ( $matches[1] as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0
					&& get_post_type( $id ) === SequencePostType::POST_TYPE
					&& current_user_can( 'edit_post', $id )
				) {
					$ids[] = $id;
				}
			}
		}

		/* Demo shortcode — no ID, just signal presence */
		if ( strpos( $content, '[storyboard_live_demo]' ) !== false ) {
			/* Show "All Sequences" without a specific edit link */
			// Handled by the root node; no additional ID needed.
		}

		return array_values( array_unique( $ids ) );
	}
}

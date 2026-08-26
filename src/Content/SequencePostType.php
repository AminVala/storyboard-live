<?php
/**
 * Sequence content entity.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Content;

/**
 * Registers the private Sequence post type.
 */
final class SequencePostType {

	const POST_TYPE = 'shseq_sequence';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register Sequence CPT.
	 *
	 * @return void
	 */
	public function register() {
		$labels = array(
			'name'                  => __( 'Sequences', 'sh-sequence-engine' ),
			'singular_name'         => __( 'Sequence', 'sh-sequence-engine' ),
			'menu_name'             => __( 'Sequences', 'sh-sequence-engine' ),
			'name_admin_bar'        => __( 'Sequence', 'sh-sequence-engine' ),
			'add_new'               => __( 'Add New', 'sh-sequence-engine' ),
			'add_new_item'          => __( 'Add New Sequence', 'sh-sequence-engine' ),
			'new_item'              => __( 'New Sequence', 'sh-sequence-engine' ),
			'edit_item'             => __( 'Edit Sequence', 'sh-sequence-engine' ),
			'view_item'             => __( 'View Sequence', 'sh-sequence-engine' ),
			'all_items'             => __( 'All Sequences', 'sh-sequence-engine' ),
			'search_items'          => __( 'Search Sequences', 'sh-sequence-engine' ),
			'not_found'             => __( 'No sequences found.', 'sh-sequence-engine' ),
			'not_found_in_trash'    => __( 'No sequences found in Trash.', 'sh-sequence-engine' ),
			'item_published'        => __( 'Sequence published.', 'sh-sequence-engine' ),
			'item_updated'          => __( 'Sequence updated.', 'sh-sequence-engine' ),
			'item_reverted_to_draft'=> __( 'Sequence reverted to draft.', 'sh-sequence-engine' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'shseq-dashboard',
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'author' ),
				'delete_with_user'    => false,
				'capability_type'     => array( 'shseq_sequence', 'shseq_sequences' ),
				'capabilities'        => self::capabilities(),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Full post type capability map.
	 *
	 * @return array<string,string>
	 */
	public static function capabilities() {
		return array(
			'edit_post'              => 'edit_shseq_sequence',
			'read_post'              => 'read_shseq_sequence',
			'delete_post'            => 'delete_shseq_sequence',
			'edit_posts'             => 'edit_shseq_sequences',
			'edit_others_posts'      => 'edit_others_shseq_sequences',
			'publish_posts'          => 'publish_shseq_sequences',
			'read_private_posts'     => 'read_private_shseq_sequences',
			'delete_posts'           => 'delete_shseq_sequences',
			'delete_private_posts'   => 'delete_private_shseq_sequences',
			'delete_published_posts' => 'delete_published_shseq_sequences',
			'delete_others_posts'    => 'delete_others_shseq_sequences',
			'edit_private_posts'     => 'edit_private_shseq_sequences',
			'edit_published_posts'   => 'edit_published_shseq_sequences',
			'create_posts'           => 'create_shseq_sequences',
		);
	}

	/**
	 * Capabilities that must be assigned directly to a role.
	 *
	 * Meta capabilities are resolved by map_meta_cap() and therefore are not
	 * stored on roles.
	 *
	 * @return string[]
	 */
	public static function primitive_capabilities() {
		return array(
			'edit_shseq_sequences',
			'edit_others_shseq_sequences',
			'publish_shseq_sequences',
			'read_private_shseq_sequences',
			'delete_shseq_sequences',
			'delete_private_shseq_sequences',
			'delete_published_shseq_sequences',
			'delete_others_shseq_sequences',
			'edit_private_shseq_sequences',
			'edit_published_shseq_sequences',
			'create_shseq_sequences',
		);
	}
}

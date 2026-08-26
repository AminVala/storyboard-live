<?php
/**
 * Internal immutable revision entity.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Content;

/**
 * Registers the private Revision post type used by later milestones.
 */
final class RevisionPostType {

	const POST_TYPE = 'shseq_revision';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register hidden revision entity.
	 *
	 * @return void
	 */
	public function register() {
		$blocked_capabilities = array(
			'edit_post'              => 'do_not_allow',
			'read_post'              => 'do_not_allow',
			'delete_post'            => 'do_not_allow',
			'edit_posts'             => 'do_not_allow',
			'edit_others_posts'      => 'do_not_allow',
			'publish_posts'          => 'do_not_allow',
			'read_private_posts'     => 'do_not_allow',
			'delete_posts'           => 'do_not_allow',
			'delete_private_posts'   => 'do_not_allow',
			'delete_published_posts' => 'do_not_allow',
			'delete_others_posts'    => 'do_not_allow',
			'edit_private_posts'     => 'do_not_allow',
			'edit_published_posts'   => 'do_not_allow',
			'create_posts'           => 'do_not_allow',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Sequence Revisions', 'sh-sequence-engine' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'author' ),
				'delete_with_user'    => false,
				'capabilities'        => $blocked_capabilities,
				'map_meta_cap'        => false,
			)
		);
	}
}

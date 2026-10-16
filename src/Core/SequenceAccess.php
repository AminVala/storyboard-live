<?php
namespace ShahreHonar\SequenceEngine\Core;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/** Object-level authorization shared by sequence endpoints. */
final class SequenceAccess {
	public static function can_edit( int $post_id ): bool {
		return $post_id > 0 && SequencePostType::POST_TYPE === get_post_type( $post_id )
			&& current_user_can( 'edit_post', $post_id );
	}

	/** Nonces and operation-specific capabilities are still checked by the caller. */
	public static function require_ajax( int $post_id, bool $allow_create = false ): void {
		if ( $allow_create && 0 === $post_id && current_user_can( 'create_shseq_sequences' ) ) {
			return;
		}
		if ( ! self::can_edit( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sequence not available.', 'sh-sequence-engine' ) ), 403 );
		}
	}
}

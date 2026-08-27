<?php
/**
 * Frame attachment manager.
 *
 * Stores and retrieves the ordered array of WordPress attachment IDs that form
 * a Sequence's real frame-sequence (like Scrollsequence). Each entry is an
 * attachment ID pointing to a WebP image in the media library.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frames;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * CRUD wrapper around the _shseq_frames post-meta array.
 */
final class FrameManager {

	const META_KEY    = '_shseq_frames';
	const MAX_FREE    = 24;
	const MAX_PRO     = 36;

	/**
	 * Return the ordered frame attachment IDs for a sequence.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return int[]
	 */
	public static function get_frames( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $stored ) ) );
	}

	/**
	 * Overwrite the entire frame list for a sequence.
	 *
	 * @param int   $post_id Sequence post ID.
	 * @param int[] $ids     Ordered attachment IDs.
	 * @return bool
	 */
	public static function set_frames( $post_id, array $ids ) {
		$clean = array_values( array_filter( array_map( 'absint', $ids ) ) );
		return (bool) update_post_meta( $post_id, self::META_KEY, $clean );
	}

	/**
	 * Return frame count for a sequence.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return int
	 */
	public static function count( $post_id ) {
		return count( self::get_frames( $post_id ) );
	}

	/**
	 * Append a single attachment to the end of the frame list.
	 *
	 * @param int $post_id       Sequence post ID.
	 * @param int $attachment_id Media library attachment ID.
	 * @return bool False when the attachment is already in the list.
	 */
	public static function add_frame( $post_id, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$frames        = self::get_frames( $post_id );

		if ( in_array( $attachment_id, $frames, true ) ) {
			return false;
		}

		$frames[] = $attachment_id;
		return self::set_frames( $post_id, $frames );
	}

	/**
	 * Remove a single attachment from the frame list.
	 *
	 * @param int $post_id       Sequence post ID.
	 * @param int $attachment_id Media library attachment ID.
	 * @return bool
	 */
	public static function remove_frame( $post_id, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$frames        = self::get_frames( $post_id );
		$filtered      = array_values( array_filter( $frames, static function ( $id ) use ( $attachment_id ) {
			return $id !== $attachment_id;
		} ) );
		return self::set_frames( $post_id, $filtered );
	}

	/**
	 * Delete all frames for a sequence.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return bool
	 */
	public static function clear( $post_id ) {
		return (bool) delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Maximum allowed frames for this sequence, based on license.
	 *
	 * @param bool $is_pro Whether the site has a Pro license.
	 * @return int
	 */
	public static function max_frames( $is_pro ) {
		return $is_pro ? self::MAX_PRO : self::MAX_FREE;
	}

	/**
	 * Build a compact runtime array describing each frame.
	 *
	 * Returns only the data needed by the frontend JS engine: URL, alt text,
	 * width and height. The internal attachment ID is intentionally excluded
	 * to avoid leaking the media-library enumeration sequence to visitors.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return array<int, array<string,mixed>>
	 */
	public static function build_runtime_frames( $post_id ) {
		$ids    = self::get_frames( $post_id );
		$result = array();

		foreach ( $ids as $attachment_id ) {
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$meta   = wp_get_attachment_image_src( $attachment_id, 'full' );
			$url    = $meta ? $meta[0] : wp_get_attachment_url( $attachment_id );
			$width  = $meta ? (int) $meta[1] : 0;
			$height = $meta ? (int) $meta[2] : 0;
			$alt    = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

			$result[] = array(
				'url'    => esc_url_raw( (string) $url ),
				'width'  => $width,
				'height' => $height,
				'alt'    => $alt,
			);
		}

		return $result;
	}
}

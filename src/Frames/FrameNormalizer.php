<?php
/**
 * Frame image normalizer.
 *
 * Validates and optionally resizes/converts uploaded frame images to a
 * consistent WebP format at 1280 × 720 (16:9) max-box. Runs server-side
 * via the GD or Imagick WordPress image editor.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frames;

/**
 * Validates frame attachments and normalises dimensions.
 */
final class FrameNormalizer {

	const TARGET_WIDTH    = 1280;
	const TARGET_HEIGHT   = 720;
	const MAX_FILE_BYTES  = 4 * 1024 * 1024; // 4 MB source limit per frame.
	const ALLOWED_MIMES   = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	/**
	 * Validate that an attachment is a suitable frame image.
	 *
	 * Returns null on success, or a WP_Error describing the problem.
	 *
	 * @param int $attachment_id WordPress media attachment ID.
	 * @return \WP_Error|null
	 */
	public static function validate( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error(
				'shseq_not_image',
				__( 'The attachment is not a valid image.', 'sh-sequence-engine' )
			);
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new \WP_Error(
				'shseq_bad_mime',
				sprintf(
					/* translators: %s: MIME type string. */
					__( 'Frame images must be JPEG, PNG, WebP, or AVIF. Got: %s', 'sh-sequence-engine' ),
					$mime
				)
			);
		}

		$path = get_attached_file( $attachment_id );
		if ( $path && file_exists( $path ) && filesize( $path ) > self::MAX_FILE_BYTES ) {
			return new \WP_Error(
				'shseq_too_large',
				sprintf(
					/* translators: %d: file size in megabytes. */
					__( 'Frame file is too large (%dMB). Maximum is 4MB per source frame.', 'sh-sequence-engine' ),
					(int) ceil( filesize( $path ) / 1024 / 1024 )
				)
			);
		}

		$meta = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $meta || 0 === (int) $meta[1] ) {
			return new \WP_Error(
				'shseq_no_dimensions',
				__( 'Could not read frame dimensions.', 'sh-sequence-engine' )
			);
		}

		return null;
	}

	/**
	 * Resize the attachment to the target bounding box via the WP image editor.
	 *
	 * Only scales DOWN. Does not upscale smaller images. The original file is
	 * replaced in-place and the attachment metadata is updated to reflect the
	 * new dimensions.
	 *
	 * @param int $attachment_id WordPress media attachment ID.
	 * @return true|\WP_Error
	 */
	public static function resize_to_target( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'shseq_no_file', __( 'Frame file not found on disk.', 'sh-sequence-engine' ) );
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$size = $editor->get_size();
		if ( $size['width'] <= self::TARGET_WIDTH && $size['height'] <= self::TARGET_HEIGHT ) {
			// Already within target — nothing to do.
			return true;
		}

		$resized = $editor->resize( self::TARGET_WIDTH, self::TARGET_HEIGHT, false );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$saved = $editor->save( $path );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Refresh WordPress attachment metadata.
		$meta = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $meta );

		return true;
	}
}

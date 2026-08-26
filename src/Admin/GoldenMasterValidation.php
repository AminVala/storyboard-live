<?php
/**
 * Golden Master file validation.
 *
 * G22: The existing GoldenMasterMetaBox accepts any attachment — including
 * enormous files (40MB+ images). For a plugin used by 1000+ users across
 * varied hosting environments, silent acceptance of very large images can:
 *   - Exhaust PHP memory during save and preview
 *   - Degrade page load performance severely
 *   - Cause silent failures in wp_get_attachment_image_srcset()
 *
 * This class provides a static validation method that:
 *   1. Checks file size against a configurable limit (default 8MB).
 *   2. Checks image dimensions against a configurable maximum (default 8000×8000px).
 *   3. Checks MIME type against the allowed list (JPEG, PNG, WebP, AVIF).
 *   4. Returns structured WP_Error messages that can be shown in admin notices.
 *
 * How to integrate:
 *   Call `GoldenMasterValidation::validate( $attachment_id )` from
 *   GoldenMasterMetaBox::save() immediately after the attachment type check,
 *   and bail early if a WP_Error is returned.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Validates Golden Master attachment files before saving.
 */
final class GoldenMasterValidation {

	/** Maximum file size in bytes (8 MB). Filterable. */
	const MAX_BYTES_DEFAULT = 8 * 1024 * 1024;

	/** Maximum pixel dimension (width or height). Filterable. */
	const MAX_PIXELS_DEFAULT = 8000;

	/** Allowed MIME types. */
	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/avif',
	);

	/**
	 * Validate a Golden Master attachment.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $variant       Variant key (desktop|tablet|mobile) — for error context.
	 * @return true|\WP_Error  True on success; WP_Error with all validation failures.
	 */
	public static function validate( $attachment_id, $variant = '' ) {
		$errors = new \WP_Error();

		/* ── MIME type ────────────────────────────────────────────── */
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			$errors->add(
				'shseq_invalid_mime',
				sprintf(
					/* translators: 1: variant name or empty, 2: detected MIME type, 3: allowed types */
					__( 'Golden Master%1$s: unsupported file type "%2$s". Allowed types: %3$s.', 'sh-sequence-engine' ),
					$variant ? ' (' . esc_html( $variant ) . ')' : '',
					esc_html( $mime ),
					implode( ', ', self::ALLOWED_MIME_TYPES )
				)
			);
		}

		/* ── File size ────────────────────────────────────────────── */
		$max_bytes = (int) apply_filters( 'shseq_golden_master_max_bytes', self::MAX_BYTES_DEFAULT );
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$size = filesize( $file_path );
			if ( $size > $max_bytes ) {
				$errors->add(
					'shseq_file_too_large',
					sprintf(
						/* translators: 1: variant, 2: actual size (human readable), 3: limit (human readable) */
						__( 'Golden Master%1$s: file size %2$s exceeds the %3$s limit. Optimise the image before uploading.', 'sh-sequence-engine' ),
						$variant ? ' (' . esc_html( $variant ) . ')' : '',
						size_format( $size ),
						size_format( $max_bytes )
					)
				);
			}
		}

		/* ── Image dimensions ─────────────────────────────────────── */
		$max_px    = (int) apply_filters( 'shseq_golden_master_max_pixels', self::MAX_PIXELS_DEFAULT );
		$meta      = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			$width  = isset( $meta['width'] )  ? (int) $meta['width']  : 0;
			$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $width > $max_px || $height > $max_px ) {
				$errors->add(
					'shseq_dimensions_too_large',
					sprintf(
						/* translators: 1: variant, 2: actual dimensions, 3: pixel limit */
						__( 'Golden Master%1$s: dimensions %2$s exceed the maximum of %3$spx on either side. Resize the image before uploading.', 'sh-sequence-engine' ),
						$variant ? ' (' . esc_html( $variant ) . ')' : '',
						"{$width}×{$height}",
						number_format_i18n( $max_px )
					)
				);
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	/**
	 * Store validation errors in a transient and show them as admin notices.
	 *
	 * Usage in GoldenMasterMetaBox::save():
	 *   $result = GoldenMasterValidation::validate( $id, $variant );
	 *   if ( is_wp_error( $result ) ) {
	 *       GoldenMasterValidation::store_errors( $result, $post_id );
	 *       $masters[ $variant ] = 0; // reject the attachment
	 *   }
	 *
	 * @param \WP_Error $error   Validation errors.
	 * @param int       $post_id Sequence post ID (for scoping the transient).
	 */
	public static function store_errors( \WP_Error $error, $post_id ) {
		$key     = 'shseq_gm_errors_' . get_current_user_id() . '_' . $post_id;
		$stored  = (array) get_transient( $key );
		$stored  = array_merge( $stored, $error->get_error_messages() );
		set_transient( $key, $stored, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Render stored Golden Master validation errors as admin notices.
	 *
	 * Hook to 'admin_notices' from GoldenMasterMetaBox::register_hooks().
	 *
	 * @param int $post_id Sequence post ID.
	 */
	public static function render_errors( $post_id ) {
		$key    = 'shseq_gm_errors_' . get_current_user_id() . '_' . $post_id;
		$errors = get_transient( $key );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $errors as $message ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo '<strong>' . esc_html__( 'StoryBoard Live — Golden Master', 'sh-sequence-engine' ) . ':</strong> ';
			echo esc_html( $message );
			echo '</p></div>';
		}
	}
}

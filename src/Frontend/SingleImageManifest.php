<?php
/**
 * Single-image runtime manifest builder.
 *
 * Turns a Sequence's stored Production Sheet structure plus one confirmed
 * Golden Master attachment into a compact runtime manifest for the
 * single-image scroll engine. No frame sequence is produced: the Production
 * Sheet rules (12-beat timeline, locked master frame, overlay reveal frames,
 * theme-header reveal, golden handoff) are applied to the single image.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Admin\SequenceStructureMetaBox;

/**
 * Builds the per-sequence single-image manifest.
 */
final class SingleImageManifest {

	const MANIFEST_SCHEMA = 'shseq.single.manifest';
	const MANIFEST_SCHEMA_VERSION = 10;

	/**
	 * Build a runtime manifest for one sequence and responsive variant.
	 *
	 * @param int    $post_id     Sequence post id.
	 * @param string $variant     Responsive variant key (desktop|tablet|mobile).
	 * @param string $instance_id Unique DOM instance id.
	 * @return array<string,mixed>|null Null when the variant has no confirmed master.
	 */
	public function build( $post_id, $variant, $instance_id ) {
		$variant = sanitize_key( $variant );
		if ( ! in_array( $variant, GoldenMasterMetaBox::VARIANTS, true ) ) {
			$variant = 'desktop';
		}

		$masters       = GoldenMasterMetaBox::get_masters( $post_id );
		$confirmations = GoldenMasterMetaBox::get_confirmations( $post_id );

		$attachment_id = isset( $masters[ $variant ] ) ? (int) $masters[ $variant ] : 0;

		// Desktop-first fallback: a non-desktop variant that is not confirmed
		// reuses the confirmed desktop master so the story still renders.
		if ( ( 0 === $attachment_id || empty( $confirmations[ $variant ] ) ) && 'desktop' !== $variant ) {
			if ( $masters['desktop'] > 0 && ! empty( $confirmations['desktop'] ) ) {
				$attachment_id = (int) $masters['desktop'];
			}
		}

		if ( 0 === $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$structure = get_post_meta( $post_id, SequenceStructureMetaBox::META_KEY, true );
		$structure = is_array( $structure ) ? $structure : array();

		$total     = isset( $structure['totalFrames'] ) ? (int) $structure['totalFrames'] : 120;
		$reference = isset( $structure['referenceFrame'] ) ? (int) $structure['referenceFrame'] : 70;
		$golden    = isset( $structure['goldenFrame'] ) ? (int) $structure['goldenFrame'] : $total;

		$image_meta = wp_get_attachment_image_src( $attachment_id, 'full' );
		$image_url  = $image_meta ? $image_meta[0] : wp_get_attachment_url( $attachment_id );
		$image_w    = $image_meta ? (int) $image_meta[1] : 0;
		$image_h    = $image_meta ? (int) $image_meta[2] : 0;
		$image_alt  = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		$srcset = wp_get_attachment_image_srcset( $attachment_id, 'full' );
		$sizes  = wp_get_attachment_image_sizes( $attachment_id, 'full' );

		$header  = isset( $structure['siteHeader'] ) && is_array( $structure['siteHeader'] ) ? $structure['siteHeader'] : array();
		$handoff = isset( $structure['handoff'] ) && is_array( $structure['handoff'] ) ? $structure['handoff'] : array();

		return array(
			'schema'         => self::MANIFEST_SCHEMA,
			'schemaVersion'  => self::MANIFEST_SCHEMA_VERSION,
			'instanceId'     => sanitize_key( $instance_id ),
			'runtimeVersion' => SHSEQ_VERSION,
			'variant'        => $variant,
			'totalFrames'    => max( 1, $total ),
			'referenceFrame' => max( 1, min( $reference, $total ) ),
			'goldenFrame'    => max( 1, min( $golden, $total ) ),
			'image'          => array(
				'id'     => $attachment_id,
				'url'    => esc_url_raw( (string) $image_url ),
				'width'  => $image_w,
				'height' => $image_h,
				'alt'    => $image_alt,
				'srcset' => is_string( $srcset ) ? $srcset : '',
				'sizes'  => is_string( $sizes ) ? $sizes : '',
			),
			// Cinematic entry transform applied ONLY up to the reference frame,
			// then locked (matches the 070 -> 120 LOCK rule).
			'camera'         => array(
				'fromScale'   => 1.12,
				'fromX'       => 0,
				'fromY'       => 4,
				'fromBlur'    => 6,
				'fromOpacity' => 0.55,
				'lockedFrom'  => max( 1, min( $reference, $total ) ),
			),
			'beats'          => $this->normalize_beats( isset( $structure['beats'] ) ? $structure['beats'] : array() ),
			'overlays'       => $this->normalize_overlays( isset( $structure['overlays'] ) ? $structure['overlays'] : array() ),
			'siteHeader'     => array(
				'enabled'          => ! empty( $header['enabled'] ) ? true : true,
				'startFrame'       => isset( $header['startFrame'] ) ? (int) $header['startFrame'] : (int) round( $total * 0.9 ),
				'interactiveFrame' => isset( $header['interactiveFrame'] ) ? (int) $header['interactiveFrame'] : (int) round( $total * 0.96 ),
				'completeFrame'    => isset( $header['completeFrame'] ) ? (int) $header['completeFrame'] : $total,
			),
			'handoff'        => array(
				'startProgress'    => 0.995,
				'reverseThreshold' => 0.985,
				'frame'            => isset( $handoff['frame'] ) ? (int) $handoff['frame'] : $golden,
				'reversible'       => true,
			),
			'motion'         => array( 'respectReducedMotion' => true, 'reducedMode' => 'golden-static' ),
			'scrollLengthVh' => 'mobile' === $variant ? 320 : ( 'tablet' === $variant ? 360 : 420 ),
		);
	}

	/**
	 * Normalize beats to a compact runtime shape.
	 *
	 * @param mixed $beats Stored beats.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_beats( $beats ) {
		$result = array();
		if ( ! is_array( $beats ) ) {
			return $result;
		}
		foreach ( array_slice( $beats, 0, 48 ) as $beat ) {
			if ( ! is_array( $beat ) ) {
				continue;
			}
			$result[] = array(
				'label'       => isset( $beat['label'] ) ? sanitize_text_field( $beat['label'] ) : '',
				'startFrame'  => isset( $beat['startFrame'] ) ? (int) $beat['startFrame'] : 1,
				'endFrame'    => isset( $beat['endFrame'] ) ? (int) $beat['endFrame'] : 1,
				'scrollStart' => isset( $beat['scrollStart'] ) ? (float) $beat['scrollStart'] : 0,
				'scrollEnd'   => isset( $beat['scrollEnd'] ) ? (float) $beat['scrollEnd'] : 0,
				'scene'       => isset( $beat['scene'] ) ? (int) $beat['scene'] : 1,
			);
		}
		return $result;
	}

	/**
	 * Normalize overlays for reveal timing.
	 *
	 * @param mixed $overlays Stored overlays.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_overlays( $overlays ) {
		$result = array();
		if ( ! is_array( $overlays ) ) {
			return $result;
		}
		foreach ( array_slice( $overlays, 0, 24 ) as $overlay ) {
			if ( ! is_array( $overlay ) ) {
				continue;
			}
			$result[] = array(
				'key'   => isset( $overlay['key'] ) ? sanitize_key( $overlay['key'] ) : '',
				'frame' => isset( $overlay['frame'] ) ? (int) $overlay['frame'] : 1,
				'type'  => 'html',
			);
		}
		return $result;
	}
}

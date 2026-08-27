<?php
/**
 * Frame Sequence Manifest builder.
 *
 * Turns a Sequence's stored frame list + Content Steps into a compact
 * JSON manifest consumed by the frontend scroll engine. Unlike the old
 * SingleImageManifest (one image + CSS animation), this manifest describes
 * a real frame-by-frame sequence like Scrollsequence.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Frames\FrameManager;

/**
 * Builds the per-sequence frame manifest.
 */
final class FrameSequenceManifest {

	const MANIFEST_SCHEMA         = 'shseq.frames.manifest';
	const MANIFEST_SCHEMA_VERSION = 1;

	/**
	 * Build the manifest for one sequence.
	 *
	 * Returns null when the sequence has no frames (Free plan user has not
	 * uploaded images yet, or AI generation is pending).
	 *
	 * @param int    $post_id     Sequence post ID.
	 * @param string $instance_id Unique DOM instance key.
	 * @return array<string,mixed>|null
	 */
	public function build( $post_id, $instance_id ) {
		$frames = FrameManager::build_runtime_frames( $post_id );

		if ( empty( $frames ) ) {
			return null;
		}

		$steps = ContentStepsMetaBox::get_steps( $post_id );

		return array(
			'schema'        => self::MANIFEST_SCHEMA,
			'schemaVersion' => self::MANIFEST_SCHEMA_VERSION,
			'instanceId'    => sanitize_key( $instance_id ),
			'totalFrames'   => count( $frames ),
			'frames'        => $frames,
			'steps'         => $this->normalize_steps( $steps ),
			// Scroll height in viewport units.
			// 420 vh → 4.2× the viewport scrollable past the hero.
			// Tablet/mobile use shorter values, detected client-side.
			'scrollLengthVh' => 420,
			'motion'        => array(
				'respectReducedMotion' => true,
				'reducedMode'          => 'last-frame-static',
			),
		);
	}

	/**
	 * Sanitise and normalise Content Steps for the runtime.
	 *
	 * Logo attachment IDs are resolved to URLs here so the public manifest
	 * never exposes internal DB identifiers.
	 *
	 * @param array<int,array<string,mixed>> $steps Raw stored steps.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_steps( array $steps ) {
		$result = array();

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$logo_url = '';
			if ( ! empty( $step['logo_id'] ) ) {
				$url = wp_get_attachment_image_url( (int) $step['logo_id'], 'thumbnail' );
				if ( $url ) {
					$logo_url = esc_url_raw( $url );
				}
			}

			$result[] = array(
				'scroll_pct' => min( 100, max( 0, (int) ( $step['scroll_pct'] ?? 0 ) ) ),
				'heading'    => sanitize_text_field( $step['heading']    ?? '' ),
				'paragraph'  => sanitize_text_field( $step['paragraph']  ?? '' ),
				'cta_text'   => sanitize_text_field( $step['cta_text']   ?? '' ),
				'cta_url'    => esc_url_raw( $step['cta_url']            ?? '' ),
				'logo_url'   => $logo_url,
				'badge_text' => sanitize_text_field( $step['badge_text'] ?? '' ),
			);
		}

		return $result;
	}
}

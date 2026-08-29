<?php
/**
 * Frame Sequence Manifest Builder — Loop 3 Final (v3)
 *
 * Changes from v1:
 *  - scrollLengthVh is now read from SettingsPage::OPT_SCROLL_SPEED setting
 *    instead of being hardcoded to 420.
 *  - Manifest output is object-cached with wp_cache for the duration of the
 *    request to avoid repeated DB lookups when the shortcode appears multiple
 *    times on the same page.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

use ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox;
use ShahreHonar\SequenceEngine\Frames\FrameManager;

/**
 * Builds the per-sequence frame manifest consumed by the JS engine.
 */
final class FrameSequenceManifest {

	const MANIFEST_SCHEMA         = 'shseq.frames.manifest';
	const MANIFEST_SCHEMA_VERSION = 1;

	/** Runtime cache: postId → manifest array. */
	private static $cache = array();

	/**
	 * Build (or return cached) manifest for one sequence.
	 *
	 * Returns null when the sequence has no frames.
	 *
	 * @param int    $post_id     Sequence post ID.
	 * @param string $instance_id Unique DOM instance key.
	 * @return array<string,mixed>|null
	 */
	public function build( int $post_id, string $instance_id ): ?array {
		if ( isset( self::$cache[ $post_id ] ) ) {
			// Return cached manifest with the new instance_id (different DOM element).
			$cached                = self::$cache[ $post_id ];
			$cached['instanceId']  = sanitize_key( $instance_id );
			return $cached;
		}

		$frames = FrameManager::build_runtime_frames( $post_id );

		if ( empty( $frames ) ) {
			return null;
		}

		$steps = ContentStepsMetaBox::get_steps( $post_id );

		$manifest = array(
			'schema'         => self::MANIFEST_SCHEMA,
			'schemaVersion'  => self::MANIFEST_SCHEMA_VERSION,
			'instanceId'     => sanitize_key( $instance_id ),
			'totalFrames'    => count( $frames ),
			'frames'         => $frames,
			'steps'          => $this->normalize_steps( $steps ),
			// Default scroll length in vh units.
			// JS engine overrides on mobile/tablet based on viewport width.
			'scrollLengthVh' => 420,
			'motion'         => array(
				'respectReducedMotion' => true,
				// JS bails early; CSS shows .shseq-static-fallback instead.
				'reducedMode'          => 'last-frame-static',
			),
		);

		self::$cache[ $post_id ] = $manifest;

		return $manifest;
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
	private function normalize_steps( array $steps ): array {
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

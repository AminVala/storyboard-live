<?php
/**
 * Built-in ready template catalog.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Templates;

/**
 * Provides immutable built-in template definitions.
 *
 * Templates are data only. They never load remote resources and never contain
 * site-specific branding. Selecting a template copies its structure into a
 * normal editable Sequence draft.
 */
final class TemplateCatalog {

	const CREATIVE_STUDIO = 'creative-studio-production-sheet';

	/** @return array<string,array<string,mixed>> */
	public function all() {
		return array(
			self::CREATIVE_STUDIO => $this->creative_studio(),
		);
	}

	/** @return array<string,mixed>|null */
	public function get( $template_id ) {
		$templates = $this->all();
		return isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;
	}

	/**
	 * Production-sheet style template based on the supplied structural reference.
	 *
	 * @return array<string,mixed>
	 */
	private function creative_studio() {
		$beats = array(
			array( 'label' => 'Blank Canvas', 'startFrame' => 1, 'endFrame' => 10, 'scrollStart' => 0.00, 'scrollEnd' => 8.33, 'scene' => 1 ),
			array( 'label' => 'First Mark', 'startFrame' => 11, 'endFrame' => 20, 'scrollStart' => 8.33, 'scrollEnd' => 16.66, 'scene' => 1 ),
			array( 'label' => 'Sketch & Main Idea', 'startFrame' => 21, 'endFrame' => 30, 'scrollStart' => 16.66, 'scrollEnd' => 25.00, 'scene' => 1 ),
			array( 'label' => 'Detail Build', 'startFrame' => 31, 'endFrame' => 40, 'scrollStart' => 25.00, 'scrollEnd' => 33.33, 'scene' => 2 ),
			array( 'label' => 'Color Layer', 'startFrame' => 41, 'endFrame' => 50, 'scrollStart' => 33.33, 'scrollEnd' => 41.66, 'scene' => 2 ),
			array( 'label' => 'Geometry & Tools', 'startFrame' => 51, 'endFrame' => 60, 'scrollStart' => 41.66, 'scrollEnd' => 50.00, 'scene' => 2 ),
			array( 'label' => 'Full Studio', 'startFrame' => 61, 'endFrame' => 70, 'scrollStart' => 50.00, 'scrollEnd' => 58.33, 'scene' => 3 ),
			array( 'label' => 'Story Mark', 'startFrame' => 71, 'endFrame' => 80, 'scrollStart' => 58.33, 'scrollEnd' => 66.66, 'scene' => 3 ),
			array( 'label' => 'Narrative Focus', 'startFrame' => 81, 'endFrame' => 90, 'scrollStart' => 66.66, 'scrollEnd' => 75.00, 'scene' => 3 ),
			array( 'label' => 'Live Message', 'startFrame' => 91, 'endFrame' => 100, 'scrollStart' => 75.00, 'scrollEnd' => 83.33, 'scene' => 4 ),
			array( 'label' => 'Action Path', 'startFrame' => 101, 'endFrame' => 110, 'scrollStart' => 83.33, 'scrollEnd' => 91.66, 'scene' => 4 ),
			array( 'label' => 'Golden Handoff', 'startFrame' => 111, 'endFrame' => 120, 'scrollStart' => 91.66, 'scrollEnd' => 100.00, 'scene' => 4 ),
		);

		return array(
			'id'          => self::CREATIVE_STUDIO,
			'version'     => 1,
			'name'        => 'Creative Studio Production Sheet',
			'description' => 'A reference-preserving 120-frame story with 12 beats, four scenes, a locked master frame, live HTML overlays, real theme-header reveal, and golden handoff.',
			'category'    => 'Cinematic Story',
			'structure'   => array(
				'totalFrames'    => 120,
				'referenceFrame' => 70,
				'goldenFrame'    => 120,
				'scenes'         => array(
					array( 'title' => 'Blank Workspace & Sketch', 'startFrame' => 1, 'endFrame' => 30 ),
					array( 'title' => 'Materials & Creation', 'startFrame' => 31, 'endFrame' => 60 ),
					array( 'title' => 'Full Studio Build', 'startFrame' => 61, 'endFrame' => 90 ),
					array( 'title' => 'Live Story & Handoff', 'startFrame' => 91, 'endFrame' => 120 ),
				),
				'beats'          => $beats,
				'keyframes'      => array(
					array( 'key' => 'A', 'frame' => 1, 'label' => 'Blank Canvas' ),
					array( 'key' => 'B', 'frame' => 30, 'label' => 'Drawing Stage' ),
					array( 'key' => 'C', 'frame' => 60, 'label' => 'Materials & Tools' ),
					array( 'key' => 'D', 'frame' => 70, 'label' => 'Master Studio' ),
					array( 'key' => 'E', 'frame' => 120, 'label' => 'Golden Final State' ),
				),
				'overlays'       => array(
					array( 'key' => 'story-mark', 'frame' => 71, 'type' => 'html' ),
					array( 'key' => 'eyebrow', 'frame' => 91, 'type' => 'html' ),
					array( 'key' => 'title', 'frame' => 94, 'type' => 'html' ),
					array( 'key' => 'subtitle', 'frame' => 101, 'type' => 'html' ),
					array( 'key' => 'actions', 'frame' => 104, 'type' => 'html' ),
					array( 'key' => 'trust', 'frame' => 107, 'type' => 'html' ),
				),
				'siteHeader'     => array(
					'enabled'          => true,
					'startFrame'       => 109,
					'interactiveFrame' => 116,
					'completeFrame'    => 120,
					'mode'             => 'real-theme-header',
				),
				'handoff'        => array(
					'frame'              => 120,
					'requireGoldenMatch' => true,
					'reversible'         => true,
				),
				'variants'       => array(
					'desktop' => array( 'frames' => 120, 'width' => 1920, 'height' => 1080, 'format' => 'WEBP/AVIF' ),
					'mobile'  => array( 'frames' => 60, 'width' => 1080, 'height' => 1920, 'format' => 'WEBP/AVIF' ),
				),
				'productionRules' => array(
					'noBakedUi'             => true,
					'noBakedLogo'           => true,
					'noBakedText'           => true,
					'cameraLockedFrom'      => 70,
					'lightingLockedFrom'    => 70,
					'noCameraCuts'          => true,
					'scrollDriven'          => true,
					'reducedMotionFallback' => true,
				),
			),
		);
	}
}

<?php
/** Runtime manifest builder. @package StoryBoardLive */
namespace ShahreHonar\SequenceEngine\Frontend;

final class RuntimeManifest {
	const MANIFEST_SCHEMA_VERSION = 9;
	const DESKTOP_FRAME_SET = 'demo-desktop';
	const MOBILE_FRAME_SET  = 'demo-mobile';

	private function build_frame_urls( $prefix, $count ) {
		$urls = array();
		for ( $frame = 1; $frame <= $count; $frame++ ) {
			$urls[] = esc_url_raw( add_query_arg( 'ver', SHSEQ_VERSION, SHSEQ_URL . sprintf( $prefix, $frame ) ) );
		}
		return $urls;
	}

	private function build_frame_sets() {
		return array(
			self::DESKTOP_FRAME_SET => array(
				'width' => 1280, 'height' => 720,
				'urls' => $this->build_frame_urls( 'assets/demo/frames/storyboard-live-%04d.webp', 30 ),
			),
			self::MOBILE_FRAME_SET => array(
				'width' => 720, 'height' => 1280,
				'urls' => $this->build_frame_urls( 'assets/demo/mobile/storyboard-live-mobile-%04d.webp', 30 ),
			),
		);
	}

	private function build_variant( $id, $frame_set_id, $width, $height, $min_width, $max_width, $orientation, $priority, $scroll_length, $dpr_cap, $layout ) {
		$frames = $this->build_frame_sets();
		$urls = $frames[ $frame_set_id ]['urls'];
		return array(
			'id' => sanitize_key( $id ),
			'priority' => (int) $priority,
			'conditions' => array( 'minWidth'=>(int)$min_width, 'maxWidth'=>(int)$max_width, 'orientation'=>$orientation ),
			'frameSetId' => $frame_set_id,
			'frameCount' => count( $urls ),
			'posters' => array(
				'entry' => array( 'url'=>$urls[0], 'width'=>$width, 'height'=>$height ),
				'golden' => array( 'url'=>$urls[count($urls)-1], 'width'=>$width, 'height'=>$height ),
			),
			'handoffFrameIndex' => count( $urls ) - 1,
			'scrollLengthVh' => (int) $scroll_length,
			'dprCap' => (float) $dpr_cap,
			'fit' => 'cover',
			'liveLayout' => sanitize_key( $layout ),
		);
	}

	public function build_demo_manifest( $instance_id ) {
		$variants = array(
			$this->build_variant( 'desktop', self::DESKTOP_FRAME_SET, 1280, 720, 1180, 10000, 'any', 10, 500, 1.5, 'desktop' ),
			$this->build_variant( 'tablet', self::DESKTOP_FRAME_SET, 1280, 720, 768, 1179, 'any', 20, 240, 1.3, 'tablet' ),
			$this->build_variant( 'mobile-portrait', self::MOBILE_FRAME_SET, 720, 1280, 0, 767, 'portrait', 40, 150, 1.1, 'mobile' ),
			$this->build_variant( 'mobile-landscape', self::DESKTOP_FRAME_SET, 1280, 720, 0, 959, 'landscape', 50, 120, 1.1, 'mobile-landscape' ),
		);
		$variants[3]['conditions']['maxHeight'] = 600;

		return array(
			'schema' => 'shseq.runtime.manifest', 'schemaVersion' => self::MANIFEST_SCHEMA_VERSION,
			'instanceId' => sanitize_key( $instance_id ), 'runtimeVersion' => SHSEQ_VERSION,
			'frameSets' => $this->build_frame_sets(),
			'responsive' => array( 'defaultVariant'=>'desktop', 'switchDebounceMs'=>160 ),
			'variants' => $variants,
			'motion' => array( 'respectReducedMotion'=>true, 'reducedMode'=>'golden-poster', 'dynamicPreference'=>true ),
			'fallback' => array( 'mode'=>'golden-poster', 'removeScrollSpace'=>true, 'preserveLiveContent'=>true ),
			'handoff' => array( 'enabled'=>true, 'startProgress'=>0.995, 'reverseThreshold'=>0.985, 'preloadGoldenAtProgress'=>0.84, 'durationMs'=>320, 'releaseAfterMs'=>1200, 'requireGoldenMatchesFrame'=>true ),
			'overlays' => array(
				array( 'key'=>'eyebrow', 'startProgress'=>0.76, 'endProgress'=>0.82, 'from'=>array('opacity'=>0,'translateY'=>12,'blur'=>0,'scale'=>1), 'to'=>array('opacity'=>1,'translateY'=>0,'blur'=>0,'scale'=>1), 'easing'=>'ease-out', 'accessibility'=>'preserve' ),
				array( 'key'=>'title', 'startProgress'=>0.80, 'endProgress'=>0.88, 'from'=>array('opacity'=>0,'translateY'=>16,'blur'=>0,'scale'=>0.995), 'to'=>array('opacity'=>1,'translateY'=>0,'blur'=>0,'scale'=>1), 'easing'=>'ease-out', 'accessibility'=>'preserve' ),
				array( 'key'=>'subtitle', 'startProgress'=>0.85, 'endProgress'=>0.92, 'from'=>array('opacity'=>0,'translateY'=>12,'blur'=>0,'scale'=>1), 'to'=>array('opacity'=>1,'translateY'=>0,'blur'=>0,'scale'=>1), 'easing'=>'ease-out', 'accessibility'=>'preserve' ),
				array( 'key'=>'actions', 'startProgress'=>0.89, 'endProgress'=>0.96, 'from'=>array('opacity'=>0,'translateY'=>10,'blur'=>0,'scale'=>0.99), 'to'=>array('opacity'=>1,'translateY'=>0,'blur'=>0,'scale'=>1), 'easing'=>'ease-out', 'accessibility'=>'inert-when-hidden' ),
			),
			'siteHeader' => array( 'enabled'=>true, 'mode'=>'theme-header', 'startProgress'=>0.90, 'endProgress'=>1.0, 'easing'=>'ease-out', 'interactiveAt'=>0.65, 'pinIfNeeded'=>true, 'keepPinnedAfterHandoff'=>true ),
			'performance' => array( 'decodedMemoryBudgetMb'=>36, 'minDecodedMemoryBudgetMb'=>12, 'maxConcurrentLoads'=>2, 'preloadAhead'=>4, 'initialPreloadAhead'=>1, 'preloadBehind'=>2, 'maxRetries'=>1, 'warmupTimeoutMs'=>8000, 'activationMarginVh'=>100, 'cancelStaleLoads'=>true ),
			'debug' => array( 'enabled'=>false ),
		);
	}

	public function get_demo_preload_candidates() {
		$sets = $this->build_frame_sets();
		return array(
			array( 'url'=>$sets[self::MOBILE_FRAME_SET]['urls'][0], 'media'=>'(max-width: 767px) and (orientation: portrait) and (prefers-reduced-motion: no-preference)' ),
			array( 'url'=>$sets[self::DESKTOP_FRAME_SET]['urls'][0], 'media'=>'(min-width: 768px) and (prefers-reduced-motion: no-preference)' ),
			array( 'url'=>$sets[self::MOBILE_FRAME_SET]['urls'][29], 'media'=>'(max-width: 767px) and (orientation: portrait) and (prefers-reduced-motion: reduce)' ),
			array( 'url'=>$sets[self::DESKTOP_FRAME_SET]['urls'][29], 'media'=>'(min-width: 768px) and (prefers-reduced-motion: reduce)' ),
		);
	}
}

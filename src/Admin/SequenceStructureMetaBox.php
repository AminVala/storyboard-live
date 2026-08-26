<?php
/**
 * Editable sequence structure meta box.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Adds a no-JavaScript structured editor for template-derived sequence data.
 *
 * This is intentionally narrow: it edits the production-sheet structure only.
 * It is not the future visual timeline editor and therefore does not start M7.
 */
final class SequenceStructureMetaBox {

	const META_KEY = '_shseq_structure';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . SequencePostType::POST_TYPE, array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . SequencePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_created_notice' ) );
	}

	/** Register meta box. */
	public function register_meta_box() {
		add_meta_box(
			'shseq-structure',
			__( 'Story Structure', 'sh-sequence-engine' ),
			array( $this, 'render' ),
			SequencePostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	/** Render editable fields. */
	public function render( $post ) {
		$structure = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $structure ) || empty( $structure ) ) {
			echo '<p>' . esc_html__( 'This sequence does not have a template structure yet. Create one from Ready Templates to start from a complete production sheet.', 'sh-sequence-engine' ) . '</p>';
			return;
		}

		wp_nonce_field( 'shseq_save_structure', '_shseq_structure_nonce' );
		$scenes    = isset( $structure['scenes'] ) && is_array( $structure['scenes'] ) ? $structure['scenes'] : array();
		$beats     = isset( $structure['beats'] ) && is_array( $structure['beats'] ) ? $structure['beats'] : array();
		$keyframes = isset( $structure['keyframes'] ) && is_array( $structure['keyframes'] ) ? $structure['keyframes'] : array();
		$header    = isset( $structure['siteHeader'] ) && is_array( $structure['siteHeader'] ) ? $structure['siteHeader'] : array();
		$handoff   = isset( $structure['handoff'] ) && is_array( $structure['handoff'] ) ? $structure['handoff'] : array();
		$overlays  = isset( $structure['overlays'] ) && is_array( $structure['overlays'] ) ? $structure['overlays'] : array();
		$variants  = isset( $structure['variants'] ) && is_array( $structure['variants'] ) ? $structure['variants'] : array();
		$rules     = isset( $structure['productionRules'] ) && is_array( $structure['productionRules'] ) ? $structure['productionRules'] : array();
		?>
		<div class="shseq-structure-editor">
			<p class="description"><?php echo esc_html__( 'This draft is a copy of the selected ready template. Editing these fields changes this Sequence only; the built-in template remains immutable.', 'sh-sequence-engine' ); ?></p>

			<h3><?php echo esc_html__( 'Production frame contract', 'sh-sequence-engine' ); ?></h3>
			<div class="shseq-structure-editor__metrics">
				<?php $this->number_field( 'totalFrames', __( 'Total frames', 'sh-sequence-engine' ), isset( $structure['totalFrames'] ) ? $structure['totalFrames'] : 120, 1, 10000 ); ?>
				<?php $this->number_field( 'referenceFrame', __( 'Master reference frame', 'sh-sequence-engine' ), isset( $structure['referenceFrame'] ) ? $structure['referenceFrame'] : 70, 1, 10000 ); ?>
				<?php $this->number_field( 'goldenFrame', __( 'Golden handoff frame', 'sh-sequence-engine' ), isset( $structure['goldenFrame'] ) ? $structure['goldenFrame'] : 120, 1, 10000 ); ?>
			</div>

			<h3><?php echo esc_html__( 'Scenes', 'sh-sequence-engine' ); ?></h3>
			<table class="widefat striped shseq-structure-table">
				<thead><tr><th>#</th><th><?php echo esc_html__( 'Title', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Start', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'End', 'sh-sequence-engine' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $scenes as $index => $scene ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td><input class="regular-text" type="text" name="shseq_structure[scenes][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( isset( $scene['title'] ) ? $scene['title'] : '' ); ?>"></td>
						<td><input class="small-text" type="number" min="1" name="shseq_structure[scenes][<?php echo esc_attr( (string) $index ); ?>][startFrame]" value="<?php echo esc_attr( (string) ( isset( $scene['startFrame'] ) ? $scene['startFrame'] : 1 ) ); ?>"></td>
						<td><input class="small-text" type="number" min="1" name="shseq_structure[scenes][<?php echo esc_attr( (string) $index ); ?>][endFrame]" value="<?php echo esc_attr( (string) ( isset( $scene['endFrame'] ) ? $scene['endFrame'] : 1 ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( '12-beat scroll timeline', 'sh-sequence-engine' ); ?></h3>
			<table class="widefat striped shseq-structure-table">
				<thead><tr><th>#</th><th><?php echo esc_html__( 'Beat', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Frames', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Scroll %', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Scene', 'sh-sequence-engine' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $beats as $index => $beat ) : ?>
					<tr>
						<td><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></td>
						<td><input type="text" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( isset( $beat['label'] ) ? $beat['label'] : '' ); ?>"></td>
						<td class="shseq-inline-fields">
							<input class="small-text" type="number" min="1" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][startFrame]" value="<?php echo esc_attr( (string) ( isset( $beat['startFrame'] ) ? $beat['startFrame'] : 1 ) ); ?>">
							<span>→</span>
							<input class="small-text" type="number" min="1" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][endFrame]" value="<?php echo esc_attr( (string) ( isset( $beat['endFrame'] ) ? $beat['endFrame'] : 1 ) ); ?>">
						</td>
						<td class="shseq-inline-fields">
							<input class="small-text" type="number" min="0" max="100" step="0.01" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][scrollStart]" value="<?php echo esc_attr( (string) ( isset( $beat['scrollStart'] ) ? $beat['scrollStart'] : 0 ) ); ?>">
							<span>→</span>
							<input class="small-text" type="number" min="0" max="100" step="0.01" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][scrollEnd]" value="<?php echo esc_attr( (string) ( isset( $beat['scrollEnd'] ) ? $beat['scrollEnd'] : 0 ) ); ?>">
						</td>
						<td><input class="small-text" type="number" min="1" max="24" name="shseq_structure[beats][<?php echo esc_attr( (string) $index ); ?>][scene]" value="<?php echo esc_attr( (string) ( isset( $beat['scene'] ) ? $beat['scene'] : 1 ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Reference keyframes', 'sh-sequence-engine' ); ?></h3>
			<div class="shseq-keyframe-editor">
				<?php foreach ( $keyframes as $index => $keyframe ) : ?>
					<label>
						<strong><?php echo esc_html( isset( $keyframe['key'] ) ? $keyframe['key'] : (string) ( $index + 1 ) ); ?></strong>
						<input class="small-text" type="number" min="1" name="shseq_structure[keyframes][<?php echo esc_attr( (string) $index ); ?>][frame]" value="<?php echo esc_attr( (string) ( isset( $keyframe['frame'] ) ? $keyframe['frame'] : 1 ) ); ?>">
						<input type="text" name="shseq_structure[keyframes][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( isset( $keyframe['label'] ) ? $keyframe['label'] : '' ); ?>">
						<input type="hidden" name="shseq_structure[keyframes][<?php echo esc_attr( (string) $index ); ?>][key]" value="<?php echo esc_attr( isset( $keyframe['key'] ) ? $keyframe['key'] : '' ); ?>">
					</label>
				<?php endforeach; ?>
			</div>

			<h3><?php echo esc_html__( 'HTML overlay timeline', 'sh-sequence-engine' ); ?></h3>
			<table class="widefat striped shseq-structure-table">
				<thead><tr><th>#</th><th><?php echo esc_html__( 'Overlay key', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Reveal frame', 'sh-sequence-engine' ); ?></th><th><?php echo esc_html__( 'Type', 'sh-sequence-engine' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $overlays as $index => $overlay ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td><input type="text" name="shseq_structure[overlays][<?php echo esc_attr( (string) $index ); ?>][key]" value="<?php echo esc_attr( isset( $overlay['key'] ) ? $overlay['key'] : '' ); ?>"></td>
						<td><input class="small-text" type="number" min="1" name="shseq_structure[overlays][<?php echo esc_attr( (string) $index ); ?>][frame]" value="<?php echo esc_attr( (string) ( isset( $overlay['frame'] ) ? $overlay['frame'] : 1 ) ); ?>"></td>
						<td><code>HTML</code><input type="hidden" name="shseq_structure[overlays][<?php echo esc_attr( (string) $index ); ?>][type]" value="html"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Responsive outputs', 'sh-sequence-engine' ); ?></h3>
			<div class="shseq-variant-editor">
				<?php foreach ( $variants as $variant_key => $variant ) : ?>
					<fieldset>
						<legend><?php echo esc_html( ucfirst( sanitize_key( $variant_key ) ) ); ?></legend>
						<label><span><?php echo esc_html__( 'Frames', 'sh-sequence-engine' ); ?></span><input type="number" min="1" name="shseq_structure[variants][<?php echo esc_attr( sanitize_key( $variant_key ) ); ?>][frames]" value="<?php echo esc_attr( (string) ( isset( $variant['frames'] ) ? $variant['frames'] : 1 ) ); ?>"></label>
						<label><span><?php echo esc_html__( 'Width', 'sh-sequence-engine' ); ?></span><input type="number" min="1" name="shseq_structure[variants][<?php echo esc_attr( sanitize_key( $variant_key ) ); ?>][width]" value="<?php echo esc_attr( (string) ( isset( $variant['width'] ) ? $variant['width'] : 1 ) ); ?>"></label>
						<label><span><?php echo esc_html__( 'Height', 'sh-sequence-engine' ); ?></span><input type="number" min="1" name="shseq_structure[variants][<?php echo esc_attr( sanitize_key( $variant_key ) ); ?>][height]" value="<?php echo esc_attr( (string) ( isset( $variant['height'] ) ? $variant['height'] : 1 ) ); ?>"></label>
						<label><span><?php echo esc_html__( 'Format', 'sh-sequence-engine' ); ?></span><input type="text" name="shseq_structure[variants][<?php echo esc_attr( sanitize_key( $variant_key ) ); ?>][format]" value="<?php echo esc_attr( isset( $variant['format'] ) ? $variant['format'] : 'WEBP/AVIF' ); ?>"></label>
					</fieldset>
				<?php endforeach; ?>
			</div>

			<h3><?php echo esc_html__( 'Locked production rules', 'sh-sequence-engine' ); ?></h3>
			<ul class="shseq-rule-list">
				<li><?php echo ! empty( $rules['noBakedUi'] ) ? '✓' : '–'; ?> <?php echo esc_html__( 'No baked UI in image frames', 'sh-sequence-engine' ); ?></li>
				<li><?php echo ! empty( $rules['noBakedLogo'] ) ? '✓' : '–'; ?> <?php echo esc_html__( 'No baked logo in image frames', 'sh-sequence-engine' ); ?></li>
				<li><?php echo ! empty( $rules['noBakedText'] ) ? '✓' : '–'; ?> <?php echo esc_html__( 'No baked text in image frames', 'sh-sequence-engine' ); ?></li>
				<li><?php echo ! empty( $rules['noCameraCuts'] ) ? '✓' : '–'; ?> <?php echo esc_html__( 'No camera cuts across the sequence', 'sh-sequence-engine' ); ?></li>
				<li><?php echo ! empty( $rules['scrollDriven'] ) ? '✓' : '–'; ?> <?php echo esc_html__( 'Native scroll remains the time axis', 'sh-sequence-engine' ); ?></li>
			</ul>


			<h3><?php echo esc_html__( 'Theme header and golden handoff', 'sh-sequence-engine' ); ?></h3>
			<div class="shseq-structure-editor__metrics">
				<?php $this->nested_number_field( 'siteHeader', 'startFrame', __( 'Header reveal starts', 'sh-sequence-engine' ), isset( $header['startFrame'] ) ? $header['startFrame'] : 109 ); ?>
				<?php $this->nested_number_field( 'siteHeader', 'interactiveFrame', __( 'Header becomes interactive', 'sh-sequence-engine' ), isset( $header['interactiveFrame'] ) ? $header['interactiveFrame'] : 116 ); ?>
				<?php $this->nested_number_field( 'siteHeader', 'completeFrame', __( 'Header reveal completes', 'sh-sequence-engine' ), isset( $header['completeFrame'] ) ? $header['completeFrame'] : 120 ); ?>
				<?php $this->nested_number_field( 'handoff', 'frame', __( 'Handoff frame', 'sh-sequence-engine' ), isset( $handoff['frame'] ) ? $handoff['frame'] : 120 ); ?>
			</div>
		</div>
		<?php
	}

	/** Save structured fields safely. */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_structure_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_shseq_structure_nonce'] ) ), 'shseq_save_structure' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || SequencePostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['shseq_structure'] ) || ! is_array( $_POST['shseq_structure'] ) ) {
			return;
		}

		$existing = get_post_meta( $post_id, self::META_KEY, true );
		$existing = is_array( $existing ) ? $existing : array();
		$input    = wp_unslash( $_POST['shseq_structure'] );

		$existing['totalFrames']    = $this->bounded_int( isset( $input['totalFrames'] ) ? $input['totalFrames'] : 120, 1, 10000 );
		$existing['referenceFrame'] = $this->bounded_int( isset( $input['referenceFrame'] ) ? $input['referenceFrame'] : 70, 1, $existing['totalFrames'] );
		$existing['goldenFrame']    = $this->bounded_int( isset( $input['goldenFrame'] ) ? $input['goldenFrame'] : $existing['totalFrames'], 1, $existing['totalFrames'] );
		$existing['scenes']         = $this->sanitize_scenes( isset( $input['scenes'] ) ? $input['scenes'] : array(), $existing['totalFrames'] );
		$existing['beats']          = $this->sanitize_beats( isset( $input['beats'] ) ? $input['beats'] : array(), $existing['totalFrames'] );
		$existing['keyframes']      = $this->sanitize_keyframes( isset( $input['keyframes'] ) ? $input['keyframes'] : array(), $existing['totalFrames'] );
		$existing['overlays']       = $this->sanitize_overlays( isset( $input['overlays'] ) ? $input['overlays'] : array(), $existing['totalFrames'] );
		$existing['variants']       = $this->sanitize_variants( isset( $input['variants'] ) ? $input['variants'] : array() );

		$existing['siteHeader'] = isset( $existing['siteHeader'] ) && is_array( $existing['siteHeader'] ) ? $existing['siteHeader'] : array();
		$header_input = isset( $input['siteHeader'] ) && is_array( $input['siteHeader'] ) ? $input['siteHeader'] : array();
		$existing['siteHeader']['enabled']          = true;
		$existing['siteHeader']['mode']             = 'real-theme-header';
		$existing['siteHeader']['startFrame']       = $this->bounded_int( isset( $header_input['startFrame'] ) ? $header_input['startFrame'] : 109, 1, $existing['totalFrames'] );
		$existing['siteHeader']['interactiveFrame'] = $this->bounded_int( isset( $header_input['interactiveFrame'] ) ? $header_input['interactiveFrame'] : 116, $existing['siteHeader']['startFrame'], $existing['totalFrames'] );
		$existing['siteHeader']['completeFrame']    = $this->bounded_int( isset( $header_input['completeFrame'] ) ? $header_input['completeFrame'] : $existing['totalFrames'], $existing['siteHeader']['interactiveFrame'], $existing['totalFrames'] );

		$existing['handoff'] = isset( $existing['handoff'] ) && is_array( $existing['handoff'] ) ? $existing['handoff'] : array();
		$handoff_input = isset( $input['handoff'] ) && is_array( $input['handoff'] ) ? $input['handoff'] : array();
		$existing['handoff']['frame']               = $this->bounded_int( isset( $handoff_input['frame'] ) ? $handoff_input['frame'] : $existing['goldenFrame'], 1, $existing['totalFrames'] );
		$existing['handoff']['requireGoldenMatch']  = true;
		$existing['handoff']['reversible']          = true;

		update_post_meta( $post_id, self::META_KEY, $existing );
	}

	/** Show success after creating from template. */
	public function render_created_notice() {
		if ( ! isset( $_GET['shseq_template_created'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['shseq_template_created'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag after nonce-protected action.
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || SequencePostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ready template copied. You can now edit this sequence structure without changing the original template.', 'sh-sequence-engine' ) . '</p></div>';
	}

	private function number_field( $key, $label, $value, $min, $max ) {
		printf( '<label><span>%1$s</span><input type="number" min="%2$d" max="%3$d" name="shseq_structure[%4$s]" value="%5$d"></label>', esc_html( $label ), (int) $min, (int) $max, esc_attr( $key ), (int) $value );
	}

	private function nested_number_field( $group, $key, $label, $value ) {
		printf( '<label><span>%1$s</span><input type="number" min="1" name="shseq_structure[%2$s][%3$s]" value="%4$d"></label>', esc_html( $label ), esc_attr( $group ), esc_attr( $key ), (int) $value );
	}

	private function sanitize_scenes( $scenes, $max_frame ) {
		$result = array();
		if ( ! is_array( $scenes ) ) {
			return $result;
		}
		foreach ( array_slice( $scenes, 0, 24 ) as $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			$start = $this->bounded_int( isset( $scene['startFrame'] ) ? $scene['startFrame'] : 1, 1, $max_frame );
			$end   = $this->bounded_int( isset( $scene['endFrame'] ) ? $scene['endFrame'] : $start, $start, $max_frame );
			$result[] = array( 'title' => sanitize_text_field( isset( $scene['title'] ) ? $scene['title'] : '' ), 'startFrame' => $start, 'endFrame' => $end );
		}
		return $result;
	}

	private function sanitize_beats( $beats, $max_frame ) {
		$result = array();
		if ( ! is_array( $beats ) ) {
			return $result;
		}
		foreach ( array_slice( $beats, 0, 48 ) as $beat ) {
			if ( ! is_array( $beat ) ) {
				continue;
			}
			$start = $this->bounded_int( isset( $beat['startFrame'] ) ? $beat['startFrame'] : 1, 1, $max_frame );
			$end   = $this->bounded_int( isset( $beat['endFrame'] ) ? $beat['endFrame'] : $start, $start, $max_frame );
			$scroll_start = $this->bounded_float( isset( $beat['scrollStart'] ) ? $beat['scrollStart'] : 0, 0, 100 );
			$scroll_end   = $this->bounded_float( isset( $beat['scrollEnd'] ) ? $beat['scrollEnd'] : $scroll_start, $scroll_start, 100 );
			$result[] = array(
				'label'       => sanitize_text_field( isset( $beat['label'] ) ? $beat['label'] : '' ),
				'startFrame'  => $start,
				'endFrame'    => $end,
				'scrollStart' => $scroll_start,
				'scrollEnd'   => $scroll_end,
				'scene'       => $this->bounded_int( isset( $beat['scene'] ) ? $beat['scene'] : 1, 1, 24 ),
			);
		}
		return $result;
	}

	private function sanitize_keyframes( $keyframes, $max_frame ) {
		$result = array();
		if ( ! is_array( $keyframes ) ) {
			return $result;
		}
		foreach ( array_slice( $keyframes, 0, 12 ) as $keyframe ) {
			if ( ! is_array( $keyframe ) ) {
				continue;
			}
			$result[] = array(
				'key'   => sanitize_key( isset( $keyframe['key'] ) ? $keyframe['key'] : '' ),
				'frame' => $this->bounded_int( isset( $keyframe['frame'] ) ? $keyframe['frame'] : 1, 1, $max_frame ),
				'label' => sanitize_text_field( isset( $keyframe['label'] ) ? $keyframe['label'] : '' ),
			);
		}
		return $result;
	}

	private function sanitize_overlays( $overlays, $max_frame ) {
		$result = array();
		if ( ! is_array( $overlays ) ) {
			return $result;
		}
		foreach ( array_slice( $overlays, 0, 24 ) as $overlay ) {
			if ( ! is_array( $overlay ) ) {
				continue;
			}
			$result[] = array(
				'key'   => sanitize_key( isset( $overlay['key'] ) ? $overlay['key'] : '' ),
				'frame' => $this->bounded_int( isset( $overlay['frame'] ) ? $overlay['frame'] : 1, 1, $max_frame ),
				'type'  => 'html',
			);
		}
		return $result;
	}

	private function sanitize_variants( $variants ) {
		$result = array();
		if ( ! is_array( $variants ) ) {
			return $result;
		}
		foreach ( array_slice( $variants, 0, 8, true ) as $variant_key => $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			$key = sanitize_key( $variant_key );
			if ( '' === $key ) {
				continue;
			}
			$result[ $key ] = array(
				'frames' => $this->bounded_int( isset( $variant['frames'] ) ? $variant['frames'] : 1, 1, 10000 ),
				'width'  => $this->bounded_int( isset( $variant['width'] ) ? $variant['width'] : 1, 1, 16384 ),
				'height' => $this->bounded_int( isset( $variant['height'] ) ? $variant['height'] : 1, 1, 16384 ),
				'format' => sanitize_text_field( isset( $variant['format'] ) ? $variant['format'] : 'WEBP/AVIF' ),
			);
		}
		return $result;
	}


	private function bounded_int( $value, $min, $max ) {
		$value = absint( $value );
		return min( (int) $max, max( (int) $min, $value ) );
	}

	private function bounded_float( $value, $min, $max ) {
		$value = (float) $value;
		return min( (float) $max, max( (float) $min, $value ) );
	}
}

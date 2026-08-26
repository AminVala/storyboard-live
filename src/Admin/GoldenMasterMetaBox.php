<?php
/**
 * Golden Master upload meta box.
 *
 * The site administrator supplies a single confirmed GOLDEN MASTER image
 * (the last frame, frame 120) per responsive variant. The plugin then applies
 * the Storyboard Production Sheet rules to that one image at runtime: there is
 * no multi-frame rendering. Tablet and mobile masters stay locked until the
 * desktop master is confirmed, matching the production workflow.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Adds a single-image Golden Master picker with a desktop-first confirm gate.
 */
final class GoldenMasterMetaBox {

	const META_MASTERS   = '_shseq_golden_master';
	const META_CONFIRMED = '_shseq_variant_confirmed';

	/** Ordered responsive variants. Desktop is the gate for the others. */
	const VARIANTS = array( 'desktop', 'tablet', 'mobile' );

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . SequencePostType::POST_TYPE, array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . SequencePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/** Register meta box. */
	public function register_meta_box() {
		add_meta_box(
			'shseq-golden-master',
			__( 'Golden Master image', 'sh-sequence-engine' ),
			array( $this, 'render' ),
			SequencePostType::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Read stored master attachment ids.
	 *
	 * @param int $post_id Sequence id.
	 * @return array<string,int>
	 */
	public static function get_masters( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_MASTERS, true );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();
		foreach ( self::VARIANTS as $variant ) {
			$result[ $variant ] = isset( $stored[ $variant ] ) ? absint( $stored[ $variant ] ) : 0;
		}
		return $result;
	}

	/**
	 * Read stored confirmation flags.
	 *
	 * @param int $post_id Sequence id.
	 * @return array<string,bool>
	 */
	public static function get_confirmations( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_CONFIRMED, true );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();
		foreach ( self::VARIANTS as $variant ) {
			$result[ $variant ] = ! empty( $stored[ $variant ] );
		}
		return $result;
	}

	/** Render meta box. */
	public function render( $post ) {
		wp_nonce_field( 'shseq_save_golden_master', '_shseq_master_nonce' );

		$masters       = self::get_masters( $post->ID );
		$confirmations = self::get_confirmations( $post->ID );
		$desktop_ready = $masters['desktop'] > 0 && $confirmations['desktop'];
		?>
		<div class="shseq-master-box">
			<p class="description">
				<?php echo esc_html__( 'Upload one confirmed Golden Master (the final frame). The plugin applies the Production Sheet motion, overlays, header reveal and golden handoff to this single image.', 'sh-sequence-engine' ); ?>
			</p>

			<?php
			foreach ( self::VARIANTS as $variant ) :
				$attachment_id = $masters[ $variant ];
				$confirmed     = $confirmations[ $variant ];
				$locked        = 'desktop' !== $variant && ! $desktop_ready;
				$thumb         = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
				?>
				<fieldset
					class="shseq-master-variant<?php echo $locked ? ' is-locked' : ''; ?>"
					data-shseq-variant="<?php echo esc_attr( $variant ); ?>"
					data-shseq-picker-title="<?php echo esc_attr__( 'Select Golden Master', 'sh-sequence-engine' ); ?>"
					data-shseq-picker-button="<?php echo esc_attr__( 'Use this image', 'sh-sequence-engine' ); ?>"
				>
					<legend>
						<?php echo esc_html( $this->variant_label( $variant ) ); ?>
						<?php if ( $confirmed && $attachment_id ) : ?>
							<span class="shseq-master-badge shseq-master-badge--ok"><?php echo esc_html__( 'Confirmed', 'sh-sequence-engine' ); ?></span>
						<?php endif; ?>
					</legend>

					<?php if ( $locked ) : ?>
						<p class="shseq-master-lock"><?php echo esc_html__( 'Confirm the desktop Golden Master first. The same structure is then applied here.', 'sh-sequence-engine' ); ?></p>
					<?php endif; ?>

					<div class="shseq-master-preview" data-shseq-master-preview data-shseq-empty="<?php echo esc_attr__( 'No image selected', 'sh-sequence-engine' ); ?>">
						<?php if ( $thumb ) : ?>
							<img src="<?php echo esc_url( $thumb ); ?>" alt="">
						<?php else : ?>
							<span class="shseq-master-empty"><?php echo esc_html__( 'No image selected', 'sh-sequence-engine' ); ?></span>
						<?php endif; ?>
					</div>

					<input
						type="hidden"
						class="shseq-master-input"
						name="shseq_master[<?php echo esc_attr( $variant ); ?>]"
						value="<?php echo esc_attr( (string) $attachment_id ); ?>"
						data-shseq-master-field="<?php echo esc_attr( $variant ); ?>"
					>

					<p class="shseq-master-actions">
						<button type="button" class="button shseq-master-select" data-shseq-master-select="<?php echo esc_attr( $variant ); ?>" <?php disabled( $locked ); ?>>
							<?php echo esc_html__( 'Select image', 'sh-sequence-engine' ); ?>
						</button>
						<button type="button" class="button-link shseq-master-remove" data-shseq-master-remove="<?php echo esc_attr( $variant ); ?>" <?php disabled( $locked ); ?>>
							<?php echo esc_html__( 'Remove', 'sh-sequence-engine' ); ?>
						</button>
					</p>

					<label class="shseq-master-confirm">
						<input
							type="checkbox"
							name="shseq_master_confirm[<?php echo esc_attr( $variant ); ?>]"
							value="1"
							<?php checked( $confirmed ); ?>
							<?php disabled( $locked ); ?>
						>
						<?php echo esc_html__( 'This Golden Master is final and confirmed', 'sh-sequence-engine' ); ?>
					</label>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Persist masters and confirmations safely.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_master_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_shseq_master_nonce'] ) ), 'shseq_save_golden_master' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || SequencePostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$master_input  = isset( $_POST['shseq_master'] ) && is_array( $_POST['shseq_master'] ) ? wp_unslash( $_POST['shseq_master'] ) : array();
		$confirm_input = isset( $_POST['shseq_master_confirm'] ) && is_array( $_POST['shseq_master_confirm'] ) ? wp_unslash( $_POST['shseq_master_confirm'] ) : array();

		$masters       = array();
		$confirmations = array();

		foreach ( self::VARIANTS as $variant ) {
			$attachment_id = isset( $master_input[ $variant ] ) ? absint( $master_input[ $variant ] ) : 0;

			// Only accept real image attachments.
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id ) ) {
				$masters[ $variant ] = $attachment_id;
			} else {
				$masters[ $variant ] = 0;
			}

			$confirmations[ $variant ] = ! empty( $confirm_input[ $variant ] ) && $masters[ $variant ] > 0;
		}

		// Desktop-first gate: tablet/mobile cannot be confirmed until desktop is.
		if ( ! $confirmations['desktop'] ) {
			$confirmations['tablet'] = false;
			$confirmations['mobile'] = false;
		}

		update_post_meta( $post_id, self::META_MASTERS, $masters );
		update_post_meta( $post_id, self::META_CONFIRMED, $confirmations );
	}

	/**
	 * Human label for a variant.
	 *
	 * @param string $variant Variant key.
	 * @return string
	 */
	private function variant_label( $variant ) {
		switch ( $variant ) {
			case 'desktop':
				return __( 'Desktop Golden Master (confirm first)', 'sh-sequence-engine' );
			case 'tablet':
				return __( 'Tablet Golden Master', 'sh-sequence-engine' );
			case 'mobile':
				return __( 'Mobile Golden Master', 'sh-sequence-engine' );
			default:
				return ucfirst( sanitize_key( $variant ) );
		}
	}
}

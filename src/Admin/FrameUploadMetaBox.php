<?php
/**
 * Frame Upload meta box.
 *
 * Displays the list of frame images currently attached to the Sequence and
 * lets the administrator manage them:
 *
 *   • Free plan: select up to 24 images from the Media Library (manual frames).
 *   • Pro plan:  show AI-generation progress OR the resulting 24–36 frames.
 *
 * This meta box is purely a viewer/manager for the _shseq_frames array.
 * The actual AI generation pipeline lives in Jobs/FrameGenerationJob.php
 * (Sprint 3).
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\Frames\FrameNormalizer;
use ShahreHonar\SequenceEngine\License\LicenseManager;

/**
 * Manages the Frame list meta box and AJAX upload handler.
 */
final class FrameUploadMetaBox {

	const META_KEY_PROMPT = '_shseq_ai_prompt';
	const ACTION_SAVE     = 'shseq_save_frames';
	const NONCE_ACTION    = 'shseq_frames_nonce';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . SequencePostType::POST_TYPE, array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . SequencePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/** Register meta box. */
	public function register_meta_box() {
		add_meta_box(
			'shseq-frames',
			__( 'Frames', 'sh-sequence-engine' ),
			array( $this, 'render' ),
			SequencePostType::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue Media Library for the frame picker.
	 *
	 * @param string $hook_suffix Current page hook.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || SequencePostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
	}

	/** Render meta box. */
	public function render( $post ) {
		$frames   = FrameManager::get_frames( $post->ID );
		$is_pro   = LicenseManager::is_pro();
		$max      = LicenseManager::max_frames();
		$prompt   = get_post_meta( $post->ID, self::META_KEY_PROMPT, true );

		wp_nonce_field( self::NONCE_ACTION, '_shseq_frames_nonce' );

		// Stored frame IDs for the hidden input.
		$frame_ids_value = implode( ',', $frames );
		?>
		<div class="shseq-frames-box">
			<p class="description">
				<?php
				if ( $is_pro ) {
					esc_html_e( 'Upload your End Frame, enter a prompt, and click Generate to let AI build the frame sequence. Or upload frames manually below.', 'sh-sequence-engine' );
				} else {
					printf(
						/* translators: %d: max frames. */
						esc_html__( 'Select up to %d images from the Media Library to form the scroll sequence. Free plan — no AI generation.', 'sh-sequence-engine' ),
						(int) $max
					);
				}
				?>
			</p>

			<?php if ( $is_pro ) : ?>
			<div class="shseq-ai-section">
				<h4><?php esc_html_e( 'AI Generation (Pro)', 'sh-sequence-engine' ); ?></h4>
				<label>
					<span><?php esc_html_e( 'Prompt (describe the starting frame)', 'sh-sequence-engine' ); ?></span>
					<textarea name="shseq_ai_prompt" rows="3" class="widefat"
						placeholder="<?php esc_attr_e( 'e.g. Camera far away, product tiny in the centre of a clean white studio, soft shadows...', 'sh-sequence-engine' ); ?>"><?php echo esc_textarea( (string) $prompt ); ?></textarea>
				</label>
				<p class="description"><?php esc_html_e( 'AI generation will be available in the next sprint. Your prompt is saved for when the feature launches.', 'sh-sequence-engine' ); ?></p>
			</div>
			<?php endif; ?>

			<div class="shseq-frame-manager">
				<h4>
					<?php
					printf(
						/* translators: 1: current count, 2: max. */
						esc_html__( 'Frame sequence (%1$d / %2$d)', 'sh-sequence-engine' ),
						count( $frames ),
						(int) $max
					);
					?>
				</h4>

				<div class="shseq-frame-thumbnails" id="shseq-frame-thumbnails">
					<?php foreach ( $frames as $att_id ) : ?>
						<?php
						$thumb = wp_get_attachment_image_url( $att_id, array( 80, 45 ) );
						$fname = basename( get_attached_file( $att_id ) );
						?>
						<div class="shseq-frame-thumb" data-id="<?php echo (int) $att_id; ?>">
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>"
									alt="<?php echo esc_attr( $fname ); ?>"
									width="80" height="45">
							<?php endif; ?>
							<button type="button" class="shseq-frame-remove" aria-label="<?php esc_attr_e( 'Remove frame', 'sh-sequence-engine' ); ?>">&times;</button>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="shseq-frame-actions">
					<button type="button" class="button" id="shseq-add-frames"
						<?php echo count( $frames ) >= $max ? 'disabled' : ''; ?>>
						<?php esc_html_e( '+ Add Frames', 'sh-sequence-engine' ); ?>
					</button>
					<span class="shseq-frame-count-notice description" id="shseq-frame-count-note">
						<?php echo count( $frames ) >= $max ? esc_html__( 'Maximum frames reached.', 'sh-sequence-engine' ) : ''; ?>
					</span>
				</div>

				<input type="hidden" name="shseq_frame_ids" id="shseq-frame-ids"
					value="<?php echo esc_attr( $frame_ids_value ); ?>">
			</div>
		</div>

		<style>
		.shseq-frames-box h4 { margin: 14px 0 6px; font-size: 13px; }
		.shseq-ai-section { background: #f0f6fc; border: 1px solid #b3d4f5; border-radius: 4px; padding: 12px 14px; margin-bottom: 14px; }
		.shseq-ai-section label span { display: block; font-size: 12px; color: #50575e; margin-bottom: 4px; }
		.shseq-frame-thumbnails { display: flex; flex-wrap: wrap; gap: 6px; min-height: 52px; border: 1px dashed #c3c4c7; border-radius: 4px; padding: 8px; margin-bottom: 10px; }
		.shseq-frame-thumb { position: relative; width: 80px; height: 52px; background: #f0f0f1; border-radius: 3px; overflow: hidden; }
		.shseq-frame-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
		.shseq-frame-remove { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,0.55); color: #fff; border: none; border-radius: 50%; width: 16px; height: 16px; font-size: 11px; line-height: 1; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; }
		.shseq-frame-count-notice { margin-left: 8px; color: #b32d2e; }
		</style>

		<script>
		(function(){
			const thumbs  = document.getElementById('shseq-frame-thumbnails');
			const idsInput = document.getElementById('shseq-frame-ids');
			const addBtn  = document.getElementById('shseq-add-frames');
			const note    = document.getElementById('shseq-frame-count-note');
			const max     = <?php echo (int) $max; ?>;

			function getIds() {
				return Array.from(thumbs.querySelectorAll('.shseq-frame-thumb')).map(el => el.dataset.id);
			}

			function syncInput() {
				const ids = getIds();
				idsInput.value = ids.join(',');
				const full = ids.length >= max;
				if (addBtn) addBtn.disabled = full;
				if (note) note.textContent = full ? '<?php echo esc_js( __( 'Maximum frames reached.', 'sh-sequence-engine' ) ); ?>' : '';
			}

			function makeThumb(att) {
				const wrap = document.createElement('div');
				wrap.className = 'shseq-frame-thumb';
				wrap.dataset.id = att.id;
				if (att.url) {
					const img = document.createElement('img');
					img.src = att.url; img.alt = att.filename || ''; img.width = 80; img.height = 45;
					wrap.appendChild(img);
				}
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'shseq-frame-remove';
				btn.setAttribute('aria-label', '<?php echo esc_js( __( 'Remove frame', 'sh-sequence-engine' ) ); ?>');
				btn.textContent = '×';
				wrap.appendChild(btn);
				return wrap;
			}

			if (addBtn) {
				addBtn.addEventListener('click', function() {
					const remaining = max - getIds().length;
					if (remaining <= 0) return;
					const frame = wp.media({
						title: '<?php echo esc_js( __( 'Select Frame Images', 'sh-sequence-engine' ) ); ?>',
						multiple: 'add',
						library: { type: 'image' }
					});
					frame.on('select', function() {
						const selected = frame.state().get('selection').toJSON();
						selected.slice(0, remaining).forEach(att => thumbs.appendChild(makeThumb(att)));
						syncInput();
					});
					frame.open();
				});
			}

			thumbs.addEventListener('click', function(e) {
				const btn = e.target.closest('.shseq-frame-remove');
				if (!btn) return;
				btn.closest('.shseq-frame-thumb').remove();
				syncInput();
			});
		}());
		</script>
		<?php
	}

	/**
	 * Save frames and prompt from $_POST.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_frames_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_shseq_frames_nonce'] ) ),
				self::NONCE_ACTION
			)
		) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id )
			|| SequencePostType::POST_TYPE !== $post->post_type
		) {
			return;
		}

		// Save AI prompt (Pro only, but always save so it is ready for Sprint 3).
		if ( isset( $_POST['shseq_ai_prompt'] ) ) {
			update_post_meta( $post_id, self::META_KEY_PROMPT, sanitize_textarea_field( wp_unslash( $_POST['shseq_ai_prompt'] ) ) );
		}

		// Save ordered frame IDs.
		if ( isset( $_POST['shseq_frame_ids'] ) ) {
			$raw  = sanitize_text_field( wp_unslash( $_POST['shseq_frame_ids'] ) );
			$ids  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
			$max  = LicenseManager::max_frames();
			$ids  = array_slice( array_values( $ids ), 0, $max );
			FrameManager::set_frames( $post_id, $ids );
		}
	}
}

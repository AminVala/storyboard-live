<?php
/**
 * Content Steps meta box.
 *
 * Replaces LiveContentMetaBox. Each "step" maps a scroll-position percentage
 * to a rich overlay that appears at that point in the Hero animation:
 *
 *   heading    (h1/h2/h3)
 *   paragraph  (p)
 *   CTA button (text + URL)
 *   logo/icon  (media attachment)
 *   badge/price (text label)
 *
 * Free plan: up to 3 steps. Pro plan: up to 10 steps.
 * Steps are stored as a JSON-encoded array in _shseq_content_steps.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\License\LicenseManager;

/**
 * Manages the Content Steps editor meta box.
 */
final class ContentStepsMetaBox {

	const META_KEY = '_shseq_content_steps';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . SequencePostType::POST_TYPE, array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . SequencePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/** Register meta box. */
	public function register_meta_box() {
		add_meta_box(
			'shseq-content-steps',
			__( 'Content Steps', 'sh-sequence-engine' ),
			array( $this, 'render' ),
			SequencePostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue admin JS/CSS for the meta box.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || SequencePostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Media uploader for logo/icon picker.
		wp_enqueue_media();
	}

	/**
	 * Read stored steps for a sequence.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_steps( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/** Render meta box HTML. */
	public function render( $post ) {
		$steps   = self::get_steps( $post->ID );
		$max     = LicenseManager::max_steps();
		$is_pro  = LicenseManager::is_pro();

		wp_nonce_field( 'shseq_save_content_steps', '_shseq_content_steps_nonce' );
		?>
		<div class="shseq-content-steps" id="shseq-content-steps">
			<p class="description">
				<?php
				printf(
					/* translators: 1: step count, 2: plan name. */
					esc_html__( 'Define up to %1$d content overlays (%2$s plan). Each step appears at the scroll % you choose.', 'sh-sequence-engine' ),
					(int) $max,
					$is_pro ? esc_html__( 'Pro', 'sh-sequence-engine' ) : esc_html__( 'Free', 'sh-sequence-engine' )
				);
				?>
			</p>

			<div class="shseq-steps-list" id="shseq-steps-list">
				<?php foreach ( $steps as $index => $step ) : ?>
					<?php $this->render_step( $index, $step ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( count( $steps ) < $max ) : ?>
				<button type="button" class="button shseq-add-step" id="shseq-add-step">
					<?php esc_html_e( '+ Add Step', 'sh-sequence-engine' ); ?>
				</button>
			<?php else : ?>
				<p class="description shseq-limit-notice">
					<?php
					printf(
						/* translators: %d: max steps. */
						esc_html__( 'Maximum %d steps reached.', 'sh-sequence-engine' ),
						(int) $max
					);
					if ( ! $is_pro ) {
						echo ' <a href="' . esc_url( admin_url( 'admin.php?page=shseq-settings' ) ) . '">' . esc_html__( 'Upgrade to Pro for 10 steps.', 'sh-sequence-engine' ) . '</a>';
					}
					?>
				</p>
			<?php endif; ?>

			<?php $this->render_step_template( $max ); ?>
		</div>

		<style>
		.shseq-content-steps { margin-top: 0; }
		.shseq-step-row { border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin-bottom: 12px; background: #fafafa; }
		.shseq-step-row__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
		.shseq-step-row__number { font-weight: 600; color: #1d2327; }
		.shseq-step-row__remove { color: #b32d2e; cursor: pointer; text-decoration: none; font-size: 12px; }
		.shseq-step-grid { display: grid; grid-template-columns: 80px 1fr 1fr; gap: 10px; align-items: start; }
		.shseq-step-grid label { display: block; font-size: 12px; color: #50575e; margin-bottom: 3px; }
		.shseq-step-grid input[type="text"],
		.shseq-step-grid input[type="url"],
		.shseq-step-grid input[type="number"] { width: 100%; }
		.shseq-step-extras { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
		.shseq-step-media-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
		.shseq-step-media-preview img { max-height: 40px; max-width: 80px; object-fit: contain; border-radius: 2px; }
		.shseq-add-step { margin-top: 4px; }
		.shseq-limit-notice { margin-top: 8px; color: #666; font-style: italic; }
		</style>

		<script>
		(function(){
			const list   = document.getElementById('shseq-steps-list');
			const addBtn = document.getElementById('shseq-add-step');
			const tplEl  = document.getElementById('shseq-step-template');
			const max    = <?php echo (int) $max; ?>;

			function reindex() {
				list.querySelectorAll('.shseq-step-row').forEach(function(row, i) {
					row.querySelector('.shseq-step-row__number').textContent = 'Step ' + (i + 1);
					row.querySelectorAll('[name]').forEach(function(el) {
						el.name = el.name.replace(/shseq_steps\[\d+\]/, 'shseq_steps[' + i + ']');
					});
				});
				if (addBtn) addBtn.style.display = list.querySelectorAll('.shseq-step-row').length >= max ? 'none' : '';
			}

			if (addBtn && tplEl) {
				addBtn.addEventListener('click', function() {
					const count = list.querySelectorAll('.shseq-step-row').length;
					if (count >= max) return;
					const html  = tplEl.innerHTML.replace(/__IDX__/g, count);
					list.insertAdjacentHTML('beforeend', html);
					reindex();
					bindMediaButtons();
				});
			}

			list.addEventListener('click', function(e) {
				const btn = e.target.closest('.shseq-step-row__remove');
				if (!btn) return;
				btn.closest('.shseq-step-row').remove();
				reindex();
			});

			function bindMediaButtons() {
				list.querySelectorAll('.shseq-logo-pick:not([data-bound])').forEach(function(btn) {
					btn.dataset.bound = '1';
					btn.addEventListener('click', function() {
						const row     = btn.closest('.shseq-step-row');
						const idInput = row.querySelector('.shseq-logo-id');
						const preview = row.querySelector('.shseq-logo-preview');
						const frame   = wp.media({ title: 'Select Logo / Icon', multiple: false, library: { type: 'image' } });
						frame.on('select', function() {
							const att = frame.state().get('selection').first().toJSON();
							idInput.value = att.id;
							preview.innerHTML = att.url ? '<img src="' + att.url + '" alt="">' : '';
						});
						frame.open();
					});
				});
			}

			bindMediaButtons();
		}());
		</script>
		<?php
	}

	/**
	 * Render one step row.
	 *
	 * @param int                  $index Zero-based step index.
	 * @param array<string,mixed>  $step  Step data.
	 */
	private function render_step( $index, $step ) {
		$scroll_pct  = isset( $step['scroll_pct'] )  ? (int) $step['scroll_pct']           : 0;
		$heading     = isset( $step['heading'] )     ? $step['heading']                     : '';
		$paragraph   = isset( $step['paragraph'] )   ? $step['paragraph']                   : '';
		$cta_text    = isset( $step['cta_text'] )    ? $step['cta_text']                    : '';
		$cta_url     = isset( $step['cta_url'] )     ? $step['cta_url']                     : '';
		$logo_id     = isset( $step['logo_id'] )     ? (int) $step['logo_id']               : 0;
		$badge_text  = isset( $step['badge_text'] )  ? $step['badge_text']                  : '';
		$logo_url    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		?>
		<div class="shseq-step-row">
			<div class="shseq-step-row__header">
				<span class="shseq-step-row__number"><?php echo esc_html( sprintf( __( 'Step %d', 'sh-sequence-engine' ), $index + 1 ) ); ?></span>
				<a href="#" class="shseq-step-row__remove"><?php esc_html_e( 'Remove', 'sh-sequence-engine' ); ?></a>
			</div>

			<div class="shseq-step-grid">
				<div>
					<label><?php esc_html_e( 'Scroll %', 'sh-sequence-engine' ); ?></label>
					<input type="number" name="shseq_steps[<?php echo (int) $index; ?>][scroll_pct]"
						value="<?php echo (int) $scroll_pct; ?>" min="0" max="100" class="small-text">
				</div>
				<div>
					<label><?php esc_html_e( 'Heading', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo (int) $index; ?>][heading]"
						value="<?php echo esc_attr( $heading ); ?>" class="widefat">
				</div>
				<div>
					<label><?php esc_html_e( 'Paragraph', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo (int) $index; ?>][paragraph]"
						value="<?php echo esc_attr( $paragraph ); ?>" class="widefat">
				</div>
			</div>

			<div class="shseq-step-extras">
				<div>
					<label><?php esc_html_e( 'CTA Button text', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo (int) $index; ?>][cta_text]"
						value="<?php echo esc_attr( $cta_text ); ?>" class="widefat">
					<label style="margin-top:5px"><?php esc_html_e( 'CTA URL', 'sh-sequence-engine' ); ?></label>
					<input type="url" name="shseq_steps[<?php echo (int) $index; ?>][cta_url]"
						value="<?php echo esc_attr( $cta_url ); ?>" class="widefat" placeholder="https://">
				</div>
				<div>
					<label><?php esc_html_e( 'Price / Badge', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo (int) $index; ?>][badge_text]"
						value="<?php echo esc_attr( $badge_text ); ?>" class="widefat"
						placeholder="<?php esc_attr_e( 'e.g. From $49', 'sh-sequence-engine' ); ?>">
				</div>
			</div>

			<div class="shseq-step-media-row">
				<input type="hidden" name="shseq_steps[<?php echo (int) $index; ?>][logo_id]"
					class="shseq-logo-id" value="<?php echo (int) $logo_id; ?>">
				<button type="button" class="button button-small shseq-logo-pick">
					<?php esc_html_e( 'Logo / Icon', 'sh-sequence-engine' ); ?>
				</button>
				<div class="shseq-logo-preview shseq-step-media-preview">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline HTML template used by JS to create new step rows.
	 *
	 * @param int $max Max steps.
	 */
	private function render_step_template( $max ) {
		ob_start();
		$this->render_step( '__IDX__', array() );
		$html = ob_get_clean();
		// Encode for safe injection into JS string.
		echo '<script type="text/html" id="shseq-step-template">';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template rendered by our own render_step, all values escaped inside.
		echo '</script>';
	}

	/**
	 * Save Content Steps from $_POST.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_content_steps_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_shseq_content_steps_nonce'] ) ),
				'shseq_save_content_steps'
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

		if ( ! isset( $_POST['shseq_steps'] ) || ! is_array( $_POST['shseq_steps'] ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$input  = wp_unslash( $_POST['shseq_steps'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$max    = LicenseManager::max_steps();
		$result = array();

		foreach ( array_slice( (array) $input, 0, $max ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$result[] = array(
				'scroll_pct' => min( 100, max( 0, (int) ( $raw['scroll_pct'] ?? 0 ) ) ),
				'heading'    => sanitize_text_field( $raw['heading']    ?? '' ),
				'paragraph'  => sanitize_text_field( $raw['paragraph']  ?? '' ),
				'cta_text'   => sanitize_text_field( $raw['cta_text']   ?? '' ),
				'cta_url'    => esc_url_raw( $raw['cta_url']            ?? '' ),
				'logo_id'    => absint( $raw['logo_id']                 ?? 0 ),
				'badge_text' => sanitize_text_field( $raw['badge_text'] ?? '' ),
			);
		}

		// Sort steps by scroll position for predictable rendering.
		usort( $result, static function ( $a, $b ) {
			return $a['scroll_pct'] <=> $b['scroll_pct'];
		} );

		update_post_meta( $post_id, self::META_KEY, $result );
	}
}

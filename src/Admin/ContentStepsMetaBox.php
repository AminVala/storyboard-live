<?php
/**
 * Content Steps meta box — Fixed.
 *
 * BUG FIX line 95: printf format string contained bare `%` before `y`
 * ("scroll % you choose") which PHP 8 treats as unknown format specifier `%y`.
 * Fixed by escaping to `%%`.
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

	/** Enqueue media uploader for logo picker. */
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

	/**
	 * Get stored steps.
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

	/** Render meta box. */
	public function render( $post ) {
		$steps  = self::get_steps( $post->ID );
		$max    = LicenseManager::max_steps();
		$is_pro = LicenseManager::is_pro();

		wp_nonce_field( 'shseq_save_content_steps', '_shseq_content_steps_nonce' );
		?>
		<div class="shseq-content-steps" id="shseq-content-steps">
			<p class="description">
				<?php
				// BUG FIX: %% escapes the literal percent sign so PHP 8 does not treat
				// "% you" as a format specifier `%y` (unknown → ValueError).
				printf(
					/* translators: 1: step count, 2: plan name. */
					esc_html__( 'Define up to %1$d content overlays (%2$s plan). Each step appears at the scroll %% position you choose.', 'sh-sequence-engine' ),
					(int) $max,
					$is_pro
						? esc_html__( 'Pro', 'sh-sequence-engine' )
						: esc_html__( 'Free', 'sh-sequence-engine' )
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
						echo ' <a href="' . esc_url( admin_url( 'admin.php?page=shseq-settings' ) ) . '">'
							. esc_html__( 'Upgrade to Pro for 10 steps.', 'sh-sequence-engine' ) . '</a>';
					}
					?>
				</p>
			<?php endif; ?>

			<?php $this->render_step_template( $max ); ?>
		</div>
		<style>
		.shseq-content-steps{margin-top:0}
		.shseq-step-row{border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;margin-bottom:12px;background:#fafafa}
		.shseq-step-row__header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
		.shseq-step-row__number{font-weight:600;color:#1d2327}
		.shseq-step-row__remove{color:#b32d2e;cursor:pointer;text-decoration:none;font-size:12px}
		.shseq-step-grid{display:grid;grid-template-columns:80px 1fr 1fr;gap:10px;align-items:start}
		.shseq-step-grid label{display:block;font-size:12px;color:#50575e;margin-bottom:3px}
		.shseq-step-grid input[type=text],.shseq-step-grid input[type=url],.shseq-step-grid input[type=number]{width:100%}
		.shseq-step-extras{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
		.shseq-add-step{margin-top:4px}
		.shseq-limit-notice{margin-top:8px;color:#666;font-style:italic}
		#shseq-step-template{display:none}
		</style>
		<script>
		(function(){
			var list = document.getElementById('shseq-steps-list');
			var addBtn = document.getElementById('shseq-add-step');
			var templateEl = document.getElementById('shseq-step-template');
			if (!list || !templateEl) return;

			var counter = list.querySelectorAll('.shseq-step-row').length;

			function attachRemove(row) {
				var btn = row.querySelector('.shseq-step-row__remove');
				if (btn) {
					btn.addEventListener('click', function() {
						row.remove();
						renumber();
					});
				}
			}

			function renumber() {
				var rows = list.querySelectorAll('.shseq-step-row');
				rows.forEach(function(row, i) {
					var num = row.querySelector('.shseq-step-row__number');
					if (num) num.textContent = <?php echo esc_js( __( 'Step', 'sh-sequence-engine' ) ); ?> + ' ' + (i + 1);
				});
			}

			list.querySelectorAll('.shseq-step-row').forEach(attachRemove);

			if (addBtn) {
				addBtn.addEventListener('click', function() {
					var html = templateEl.innerHTML.replace(/__IDX__/g, counter);
					var tmp = document.createElement('div');
					tmp.innerHTML = html;
					var row = tmp.firstElementChild;
					list.appendChild(row);
					attachRemove(row);
					counter++;
					renumber();
				});
			}
		}());
		</script>
		<?php
	}

	/**
	 * Render a single step row.
	 *
	 * @param int|string             $index Step index.
	 * @param array<string,mixed>    $step  Step data.
	 */
	private function render_step( $index, array $step ) {
		$scroll    = min( 100, max( 0, (int) ( $step['scroll_pct'] ?? 0 ) ) );
		$heading   = sanitize_text_field( $step['heading']   ?? '' );
		$paragraph = sanitize_text_field( $step['paragraph'] ?? '' );
		$cta_text  = sanitize_text_field( $step['cta_text']  ?? '' );
		$cta_url   = esc_url_raw( $step['cta_url']           ?? '' );
		$badge     = sanitize_text_field( $step['badge_text'] ?? '' );
		?>
		<div class="shseq-step-row">
			<div class="shseq-step-row__header">
				<span class="shseq-step-row__number">
					<?php echo esc_html( sprintf( __( 'Step %d', 'sh-sequence-engine' ), (int) $index + 1 ) ); ?>
				</span>
				<button type="button" class="button-link shseq-step-row__remove">
					<?php esc_html_e( 'Remove', 'sh-sequence-engine' ); ?>
				</button>
			</div>

			<div class="shseq-step-grid">
				<div>
					<label><?php esc_html_e( 'Scroll %%', 'sh-sequence-engine' ); ?></label>
					<input type="number" name="shseq_steps[<?php echo esc_attr( $index ); ?>][scroll_pct]" min="0" max="100" value="<?php echo esc_attr( $scroll ); ?>">
				</div>
				<div>
					<label><?php esc_html_e( 'Heading', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo esc_attr( $index ); ?>][heading]" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php esc_attr_e( 'Main headline…', 'sh-sequence-engine' ); ?>">
				</div>
				<div>
					<label><?php esc_html_e( 'Paragraph', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo esc_attr( $index ); ?>][paragraph]" value="<?php echo esc_attr( $paragraph ); ?>" placeholder="<?php esc_attr_e( 'Subtext…', 'sh-sequence-engine' ); ?>">
				</div>
			</div>
			<div class="shseq-step-extras">
				<div>
					<label><?php esc_html_e( 'CTA Text', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo esc_attr( $index ); ?>][cta_text]" value="<?php echo esc_attr( $cta_text ); ?>" placeholder="<?php esc_attr_e( 'Get started', 'sh-sequence-engine' ); ?>">
				</div>
				<div>
					<label><?php esc_html_e( 'CTA URL', 'sh-sequence-engine' ); ?></label>
					<input type="url" name="shseq_steps[<?php echo esc_attr( $index ); ?>][cta_url]" value="<?php echo esc_attr( $cta_url ); ?>" placeholder="https://">
				</div>
				<div>
					<label><?php esc_html_e( 'Badge / Price', 'sh-sequence-engine' ); ?></label>
					<input type="text" name="shseq_steps[<?php echo esc_attr( $index ); ?>][badge_text]" value="<?php echo esc_attr( $badge ); ?>" placeholder="<?php esc_attr_e( 'Sale 20% off', 'sh-sequence-engine' ); ?>">
				</div>
			</div>
		</div>
		<?php
	}

	/** Render JS template for dynamic step insertion. */
	private function render_step_template( $max ) {
		?>
		<div id="shseq-step-template">
			<?php $this->render_step( '__IDX__', array() ); ?>
		</div>
		<?php
	}

	/** Save steps from post meta. */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_content_steps_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_shseq_content_steps_nonce'] ) ), 'shseq_save_content_steps' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw  = isset( $_POST['shseq_steps'] ) && is_array( $_POST['shseq_steps'] ) ? wp_unslash( $_POST['shseq_steps'] ) : array();
		$max  = LicenseManager::max_steps();
		$data = array();

		foreach ( array_slice( $raw, 0, $max ) as $step ) {
			$data[] = array(
				'scroll_pct' => min( 100, max( 0, (int) ( $step['scroll_pct'] ?? 0 ) ) ),
				'heading'    => sanitize_text_field( $step['heading']    ?? '' ),
				'paragraph'  => sanitize_text_field( $step['paragraph']  ?? '' ),
				'cta_text'   => sanitize_text_field( $step['cta_text']   ?? '' ),
				'cta_url'    => esc_url_raw( $step['cta_url']            ?? '' ),
				'badge_text' => sanitize_text_field( $step['badge_text'] ?? '' ),
			);
		}

		update_post_meta( $post_id, self::META_KEY, $data );
	}
}

<?php
/**
 * Sequence creation wizard — replaces the broken meta-box soup.
 *
 * Architecture:
 *   Step 1 — Name & Template   (title + choose a template or blank)
 *   Step 2 — Upload Frames     (drag-drop or multi-select from Media Library)
 *   Step 3 — Content Steps     (headings, CTAs, badges per scroll-%)
 *   Step 4 — Golden Master     (confirm End Frame per responsive variant)
 *   Step 5 — Preview & Publish (live preview with signed token + publish)
 *
 * The wizard is rendered as a custom page (not a standard post editor)
 * to avoid the complexity of the raw WP meta-box layout.
 * A standard save_post hook still fires for existing code compatibility.
 *
 * URL pattern:
 *   Create new:  admin.php?page=shseq-create
 *   Edit:        admin.php?page=shseq-create&id=123&step=2
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

/**
 * Renders the step-by-step sequence creation wizard.
 */
final class SequenceWizard {

	const PAGE_SLUG = 'shseq-create';
	const STEPS     = 5;

	/** @var TemplateCatalog */
	private $catalog;

	/** @param TemplateCatalog $catalog Template catalog. */
	public function __construct( TemplateCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'admin_menu',            array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_shseq_wizard_save', array( $this, 'handle_save' ) );
	}

	/** Register the wizard admin page (hidden from menu). */
	public function register_page() {
		add_submenu_page(
			'shseq-dashboard',
			__( 'Create Sequence', 'sh-sequence-engine' ),
			__( 'Create New', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Enqueue wizard assets. */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'shseq-admin-dashboard' );
	}

	/** Handle step save via POST. */
	public function handle_save() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}

		check_admin_referer( 'shseq_wizard_save' );

		$step    = isset( $_POST['shseq_step'] )    ? absint( $_POST['shseq_step'] ) : 1;
		$post_id = isset( $_POST['shseq_post_id'] ) ? absint( $_POST['shseq_post_id'] ) : 0;

		// ── Create or update the post ─────────────────────────────────────
		if ( 1 === $step ) {
			$title = isset( $_POST['shseq_title'] )
				? sanitize_text_field( wp_unslash( $_POST['shseq_title'] ) )
				: __( '(Untitled Sequence)', 'sh-sequence-engine' );

			if ( $post_id > 0 ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
			} else {
				$post_id = wp_insert_post( array(
					'post_type'   => SequencePostType::POST_TYPE,
					'post_status' => 'draft',
					'post_title'  => $title,
					'post_author' => get_current_user_id(),
				), true );
				if ( is_wp_error( $post_id ) ) {
					wp_die( esc_html( $post_id->get_error_message() ) );
				}
			}

			// Apply template if selected.
			$template_id = isset( $_POST['shseq_template_id'] )
				? sanitize_key( wp_unslash( $_POST['shseq_template_id'] ) )
				: '';
			if ( $template_id ) {
				$tpl = $this->catalog->get( $template_id );
				if ( $tpl ) {
					update_post_meta( $post_id, '_shseq_structure', $tpl['structure'] );
					update_post_meta( $post_id, '_shseq_template_id', $template_id );
				}
			}
		}

		// ── Step 2: Frames ────────────────────────────────────────────────
		if ( 2 === $step && $post_id > 0 ) {
			$raw_ids = isset( $_POST['shseq_frame_ids'] )
				? sanitize_text_field( wp_unslash( $_POST['shseq_frame_ids'] ) )
				: '';
			$ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
			if ( ! empty( $ids ) ) {
				FrameManager::set_frames( $post_id, $ids );
			}

			// Save AI prompt.
			if ( LicenseManager::can_use_ai() && isset( $_POST['shseq_ai_prompt'] ) ) {
				update_post_meta( $post_id, '_shseq_ai_prompt',
					sanitize_textarea_field( wp_unslash( $_POST['shseq_ai_prompt'] ) ) );
			}
		}

		// ── Step 3: Content steps ─────────────────────────────────────────
		if ( 3 === $step && $post_id > 0 ) {
			$raw   = isset( $_POST['shseq_steps'] ) && is_array( $_POST['shseq_steps'] )
				? wp_unslash( $_POST['shseq_steps'] ) : array();
			$max   = LicenseManager::max_steps();
			$steps = array();
			foreach ( array_slice( $raw, 0, $max ) as $s ) {
				$steps[] = array(
					'scroll_pct' => min( 100, max( 0, (int) ( $s['scroll_pct'] ?? 0 ) ) ),
					'heading'    => sanitize_text_field( $s['heading']   ?? '' ),
					'paragraph'  => sanitize_text_field( $s['paragraph'] ?? '' ),
					'cta_text'   => sanitize_text_field( $s['cta_text']  ?? '' ),
					'cta_url'    => esc_url_raw( $s['cta_url']           ?? '' ),
					'badge_text' => sanitize_text_field( $s['badge_text'] ?? '' ),
				);
			}
			update_post_meta( $post_id, '_shseq_content_steps', $steps );
		}

		// ── Step 4: Golden Master ─────────────────────────────────────────
		if ( 4 === $step && $post_id > 0 ) {
			$master_input  = isset( $_POST['shseq_master'] ) && is_array( $_POST['shseq_master'] )
				? wp_unslash( $_POST['shseq_master'] ) : array();
			$confirm_input = isset( $_POST['shseq_master_confirm'] ) && is_array( $_POST['shseq_master_confirm'] )
				? wp_unslash( $_POST['shseq_master_confirm'] ) : array();

			$masters = array();
			$confirms = array();
			foreach ( GoldenMasterMetaBox::VARIANTS as $variant ) {
				$att_id = absint( $master_input[ $variant ] ?? 0 );
				if ( $att_id > 0 && 'attachment' === get_post_type( $att_id ) && wp_attachment_is_image( $att_id ) ) {
					$masters[ $variant ] = $att_id;
				} else {
					$masters[ $variant ] = 0;
				}
				$confirms[ $variant ] = ! empty( $confirm_input[ $variant ] ) && $masters[ $variant ] > 0;
			}
			if ( ! $confirms['desktop'] ) {
				$confirms['tablet'] = false;
				$confirms['mobile'] = false;
			}
			update_post_meta( $post_id, GoldenMasterMetaBox::META_MASTERS,   $masters );
			update_post_meta( $post_id, GoldenMasterMetaBox::META_CONFIRMED, $confirms );
		}

		// ── Step 5: Publish ───────────────────────────────────────────────
		if ( 5 === $step && $post_id > 0 ) {
			$action = isset( $_POST['shseq_publish_action'] )
				? sanitize_key( wp_unslash( $_POST['shseq_publish_action'] ) )
				: '';
			if ( 'publish' === $action && current_user_can( 'publish_shseq_sequences' ) ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			}
		}

		// ── Redirect to next step ─────────────────────────────────────────
		$next_step = min( self::STEPS, $step + 1 );
		wp_safe_redirect( add_query_arg( array(
			'page'   => self::PAGE_SLUG,
			'id'     => $post_id,
			'step'   => $next_step,
			'saved'  => $step,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Render the wizard page. */
	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}

		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$step    = isset( $_GET['step'] ) ? min( self::STEPS, max( 1, absint( $_GET['step'] ) ) ) : 1;
		$saved   = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( $post && SequencePostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid sequence.', 'sh-sequence-engine' ) );
		}
		?>
		<div class="wrap shseq-admin shseq-wizard">
			<div class="shseq-wizard__header">
				<h1>
					<?php echo $post_id
						? esc_html( sprintf( __( 'Edit Sequence: %s', 'sh-sequence-engine' ), get_the_title( $post_id ) ?: __( '(Untitled)', 'sh-sequence-engine' ) ) )
						: esc_html__( 'Create New Sequence', 'sh-sequence-engine' ); ?>
				</h1>
				<?php if ( $post_id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>" class="button">
						<?php esc_html_e( 'Full editor →', 'sh-sequence-engine' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php $this->render_step_bar( $step, $post_id ); ?>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php
					printf(
						/* translators: %d: completed step number. */
						esc_html__( 'Step %d saved.', 'sh-sequence-engine' ),
						(int) $saved
					);
				?></p></div>
			<?php endif; ?>

			<div class="shseq-wizard__body">
				<?php
				switch ( $step ) {
					case 1: $this->render_step1( $post_id, $post ); break;
					case 2: $this->render_step2( $post_id ); break;
					case 3: $this->render_step3( $post_id ); break;
					case 4: $this->render_step4( $post_id ); break;
					case 5: $this->render_step5( $post_id ); break;
				}
				?>
			</div>
		</div>
		<?php $this->render_styles(); ?>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step bar
	// ─────────────────────────────────────────────────────────────────────

	private function render_step_bar( $current, $post_id ) {
		$labels = array(
			1 => __( 'Name & Template', 'sh-sequence-engine' ),
			2 => __( 'Upload Frames',   'sh-sequence-engine' ),
			3 => __( 'Content Steps',   'sh-sequence-engine' ),
			4 => __( 'Golden Master',   'sh-sequence-engine' ),
			5 => __( 'Preview & Publish','sh-sequence-engine' ),
		);
		echo '<nav class="shseq-step-bar" aria-label="' . esc_attr__( 'Wizard steps', 'sh-sequence-engine' ) . '">';
		foreach ( $labels as $n => $label ) {
			$class = 'shseq-step-bar__item';
			if ( $n < $current ) $class .= ' is-done';
			if ( $n === $current ) $class .= ' is-active';
			if ( $n > $current )  $class .= ' is-upcoming';

			if ( $post_id && $n < $current ) {
				$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'id' => $post_id, 'step' => $n ), admin_url( 'admin.php' ) );
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">';
			} else {
				echo '<span class="' . esc_attr( $class ) . '">';
			}

			echo '<span class="shseq-step-bar__num">' . esc_html( $n ) . '</span>';
			echo '<span class="shseq-step-bar__label">' . esc_html( $label ) . '</span>';

			if ( $post_id && $n < $current ) echo '</a>';
			else echo '</span>';
		}
		echo '</nav>';
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step 1 — Name & Template
	// ─────────────────────────────────────────────────────────────────────

	private function render_step1( $post_id, $post ) {
		$title       = $post ? get_the_title( $post ) : '';
		$template_id = $post_id ? (string) get_post_meta( $post_id, '_shseq_template_id', true ) : '';
		$templates   = $this->catalog->all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'shseq_wizard_save' ); ?>
			<input type="hidden" name="action" value="shseq_wizard_save">
			<input type="hidden" name="shseq_step" value="1">
			<input type="hidden" name="shseq_post_id" value="<?php echo esc_attr( $post_id ); ?>">

			<div class="shseq-wizard__field">
				<label for="shseq_title" class="shseq-wizard__label">
					<?php esc_html_e( 'Sequence name', 'sh-sequence-engine' ); ?>
				</label>
				<input id="shseq_title" type="text" name="shseq_title"
					value="<?php echo esc_attr( $title ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Homepage Hero', 'sh-sequence-engine' ); ?>"
					class="shseq-wizard__input large-text" required>
				<p class="description"><?php esc_html_e( 'Internal name — not shown to visitors.', 'sh-sequence-engine' ); ?></p>
			</div>

			<div class="shseq-wizard__field">
				<p class="shseq-wizard__label"><?php esc_html_e( 'Start from a template (optional)', 'sh-sequence-engine' ); ?></p>
				<p class="description"><?php esc_html_e( 'Choose a production-ready structure, or leave blank to start fresh.', 'sh-sequence-engine' ); ?></p>

				<div class="shseq-template-picker">
					<label class="shseq-template-card <?php echo '' === $template_id ? 'is-selected' : ''; ?>">
						<input type="radio" name="shseq_template_id" value=""
							<?php checked( '', $template_id ); ?>>
						<span class="shseq-template-card__icon">＋</span>
						<span class="shseq-template-card__title"><?php esc_html_e( 'Blank', 'sh-sequence-engine' ); ?></span>
						<span class="shseq-template-card__desc"><?php esc_html_e( 'Start with no template.', 'sh-sequence-engine' ); ?></span>
					</label>
					<?php foreach ( $templates as $tpl ) : ?>
						<label class="shseq-template-card <?php echo $tpl['id'] === $template_id ? 'is-selected' : ''; ?>">
							<input type="radio" name="shseq_template_id" value="<?php echo esc_attr( $tpl['id'] ); ?>"
								<?php checked( $tpl['id'], $template_id ); ?>>
							<span class="shseq-template-card__badge"><?php echo esc_html( $tpl['category'] ); ?></span>
							<span class="shseq-template-card__title"><?php echo esc_html( $tpl['name'] ); ?></span>
							<span class="shseq-template-card__desc"><?php echo esc_html( $tpl['description'] ); ?></span>
							<span class="shseq-template-card__meta">
								<?php echo esc_html( $tpl['structure']['totalFrames'] ); ?> <?php esc_html_e( 'frames', 'sh-sequence-engine' ); ?>
								&nbsp;·&nbsp;
								<?php echo esc_html( count( $tpl['structure']['scenes'] ) ); ?> <?php esc_html_e( 'scenes', 'sh-sequence-engine' ); ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<?php $this->render_nav( false, true ); ?>
		</form>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step 2 — Upload Frames
	// ─────────────────────────────────────────────────────────────────────

	private function render_step2( $post_id ) {
		$frames   = $post_id ? FrameManager::get_frames( $post_id ) : array();
		$is_pro   = LicenseManager::is_pro();
		$max      = LicenseManager::max_frames();
		$prompt   = $post_id ? (string) get_post_meta( $post_id, '_shseq_ai_prompt', true ) : '';
		$frame_ids_value = implode( ',', $frames );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'shseq_wizard_save' ); ?>
			<input type="hidden" name="action" value="shseq_wizard_save">
			<input type="hidden" name="shseq_step" value="2">
			<input type="hidden" name="shseq_post_id" value="<?php echo esc_attr( $post_id ); ?>">
			<input type="hidden" name="shseq_frame_ids" id="shseq-frame-ids" value="<?php echo esc_attr( $frame_ids_value ); ?>">

			<?php if ( $is_pro ) : ?>
			<div class="shseq-wizard__field shseq-ai-box">
				<p class="shseq-wizard__label"><?php esc_html_e( 'AI Frame Generation (Pro)', 'sh-sequence-engine' ); ?></p>
				<p class="description"><?php esc_html_e( 'Describe the opening scene. The AI will generate the Start Frame (DALL·E 3) and interpolate all frames (Replicate FILM). Your Golden Master becomes the End Frame.', 'sh-sequence-engine' ); ?></p>
				<textarea name="shseq_ai_prompt" rows="3" class="large-text"
					placeholder="<?php esc_attr_e( 'Camera far away, product tiny in centre, clean white studio, soft shadows…', 'sh-sequence-engine' ); ?>"><?php echo esc_textarea( $prompt ); ?></textarea>
				<p class="description" style="color:#d63638"><?php esc_html_e( 'AI generation runs in the background after saving — come back in a few minutes.', 'sh-sequence-engine' ); ?></p>
			</div>
			<div class="shseq-wizard__divider"><?php esc_html_e( '— or upload frames manually —', 'sh-sequence-engine' ); ?></div>
			<?php endif; ?>

			<div class="shseq-wizard__field">
				<p class="shseq-wizard__label">
					<?php printf(
						/* translators: 1: current count, 2: max. */
						esc_html__( 'Frames (%1$d / %2$d) — WebP recommended', 'sh-sequence-engine' ),
						count( $frames ),
						(int) $max
					); ?>
				</p>
				<div class="shseq-frame-drop" id="shseq-frame-drop">
					<div class="shseq-frame-thumbnails" id="shseq-frame-thumbnails">
						<?php foreach ( $frames as $att_id ) :
							$thumb = wp_get_attachment_image_url( $att_id, array( 80, 45 ) );
							?>
							<div class="shseq-frame-thumb" data-id="<?php echo (int) $att_id; ?>">
								<?php if ( $thumb ) : ?>
									<img src="<?php echo esc_url( $thumb ); ?>" alt="" width="80" height="45">
								<?php endif; ?>
								<button type="button" class="shseq-frame-remove" aria-label="<?php esc_attr_e( 'Remove', 'sh-sequence-engine' ); ?>">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button shseq-frame-pick" id="shseq-add-frames"
						<?php echo count( $frames ) >= $max ? 'disabled' : ''; ?>>
						<?php esc_html_e( '+ Add Frames from Media Library', 'sh-sequence-engine' ); ?>
					</button>
					<p class="description"><?php printf(
						esc_html__( 'Upload %d WebP images (1280×720 recommended) numbered in order. They play frame-by-frame as the visitor scrolls.', 'sh-sequence-engine' ),
						(int) $max
					); ?></p>
				</div>
			</div>

			<?php $this->render_nav( true, true ); ?>
		</form>
		<script>
		(function(){
			var fieldEl  = document.getElementById('shseq-frame-ids');
			var thumbEl  = document.getElementById('shseq-frame-thumbnails');
			var addBtn   = document.getElementById('shseq-add-frames');
			var maxFrames= <?php echo (int) $max; ?>;

			function getIds(){
				return Array.from(thumbEl.querySelectorAll('.shseq-frame-thumb')).map(function(el){
					return parseInt(el.getAttribute('data-id'),10);
				}).filter(Boolean);
			}
			function syncField(){ fieldEl.value = getIds().join(','); }
			function removeThumb(el){
				el.remove();
				syncField();
				addBtn.disabled = getIds().length >= maxFrames;
			}

			thumbEl.querySelectorAll('.shseq-frame-remove').forEach(function(btn){
				btn.addEventListener('click',function(){ removeThumb(btn.closest('.shseq-frame-thumb')); });
			});

			if(addBtn && typeof wp!=='undefined' && wp.media){
				addBtn.addEventListener('click',function(){
					var frame=wp.media({title:<?php echo wp_json_encode( __( 'Select Frames', 'sh-sequence-engine' ) ); ?>,button:{text:<?php echo wp_json_encode( __( 'Add frames', 'sh-sequence-engine' ) ); ?>},multiple:true,library:{type:'image'}});
					frame.on('select',function(){
						var attachments=frame.state().get('selection').toJSON();
						var current=getIds();
						attachments.forEach(function(att){
							if(current.length>=maxFrames||current.indexOf(att.id)!==-1)return;
							var div=document.createElement('div');
							div.className='shseq-frame-thumb';
							div.setAttribute('data-id',att.id);
							var src=att.sizes&&att.sizes.thumbnail?att.sizes.thumbnail.url:att.url;
							div.innerHTML='<img src="'+src+'" alt="" width="80" height="45"><button type="button" class="shseq-frame-remove" aria-label="Remove">&times;</button>';
							div.querySelector('.shseq-frame-remove').addEventListener('click',function(){removeThumb(div);});
							thumbEl.appendChild(div);
							current.push(att.id);
						});
						syncField();
						addBtn.disabled=getIds().length>=maxFrames;
					});
					frame.open();
				});
			}
		}());
		</script>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step 3 — Content Steps
	// ─────────────────────────────────────────────────────────────────────

	private function render_step3( $post_id ) {
		$steps  = $post_id ? \ShahreHonar\SequenceEngine\Admin\ContentStepsMetaBox::get_steps( $post_id ) : array();
		$max    = LicenseManager::max_steps();
		$is_pro = LicenseManager::is_pro();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'shseq_wizard_save' ); ?>
			<input type="hidden" name="action" value="shseq_wizard_save">
			<input type="hidden" name="shseq_step" value="3">
			<input type="hidden" name="shseq_post_id" value="<?php echo esc_attr( $post_id ); ?>">

			<div class="shseq-wizard__field">
				<p class="shseq-wizard__label">
					<?php printf(
						/* translators: 1: max steps, 2: plan. */
						esc_html__( 'Content overlays — up to %1$d (%2$s plan). Each step fades in at the scroll %% position you set.', 'sh-sequence-engine' ),
						(int) $max,
						$is_pro ? esc_html__( 'Pro', 'sh-sequence-engine' ) : esc_html__( 'Free', 'sh-sequence-engine' )
					); ?>
				</p>

				<div class="shseq-steps-list" id="shseq-steps-list">
					<?php foreach ( $steps as $i => $step ) : ?>
						<?php $this->render_step_row( $i, $step ); ?>
					<?php endforeach; ?>
				</div>

				<?php if ( count( $steps ) < $max ) : ?>
					<button type="button" class="button" id="shseq-add-step">
						<?php esc_html_e( '+ Add overlay step', 'sh-sequence-engine' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php $this->render_nav( true, true ); ?>
		</form>
		<div id="shseq-step-tpl" style="display:none"><?php $this->render_step_row( '__IDX__', array() ); ?></div>
		<script>
		(function(){
			var list=document.getElementById('shseq-steps-list');
			var tpl=document.getElementById('shseq-step-tpl');
			var addBtn=document.getElementById('shseq-add-step');
			var idx=list?list.querySelectorAll('.shseq-wiz-step').length:0;
			function attachRemove(row){
				var btn=row.querySelector('.shseq-wiz-step__remove');
				if(btn)btn.addEventListener('click',function(){row.remove();renumber();});
			}
			function renumber(){
				list.querySelectorAll('.shseq-wiz-step').forEach(function(row,i){
					var h=row.querySelector('.shseq-wiz-step__num');
					if(h)h.textContent='<?php echo esc_js( __( 'Step', 'sh-sequence-engine' ) ); ?> '+(i+1);
				});
			}
			if(list)list.querySelectorAll('.shseq-wiz-step').forEach(attachRemove);
			if(addBtn&&tpl){
				addBtn.addEventListener('click',function(){
					var html=tpl.innerHTML.replace(/__IDX__/g,idx++);
					var tmp=document.createElement('div');tmp.innerHTML=html;
					var row=tmp.firstElementChild;
					list.appendChild(row);attachRemove(row);renumber();
				});
			}
		}());
		</script>
		<?php
	}

	private function render_step_row( $index, array $step ) {
		$scroll  = min( 100, max( 0, (int) ( $step['scroll_pct'] ?? 0 ) ) );
		$heading = esc_attr( sanitize_text_field( $step['heading']    ?? '' ) );
		$para    = esc_attr( sanitize_text_field( $step['paragraph']  ?? '' ) );
		$ctatxt  = esc_attr( sanitize_text_field( $step['cta_text']   ?? '' ) );
		$ctaurl  = esc_attr( esc_url_raw( $step['cta_url']           ?? '' ) );
		$badge   = esc_attr( sanitize_text_field( $step['badge_text'] ?? '' ) );
		$n       = esc_attr( $index );
		?>
		<div class="shseq-wiz-step">
			<div class="shseq-wiz-step__head">
				<strong class="shseq-wiz-step__num"><?php printf( esc_html__( 'Step %s', 'sh-sequence-engine' ), is_numeric($index) ? (int)$index+1 : $index ); ?></strong>
				<button type="button" class="button-link shseq-wiz-step__remove"><?php esc_html_e( 'Remove', 'sh-sequence-engine' ); ?></button>
			</div>
			<div class="shseq-wiz-step__body">
				<div class="shseq-wiz-step__row">
					<div class="shseq-wiz-step__field shseq-wiz-step__field--sm">
						<label><?php esc_html_e( 'Scroll %', 'sh-sequence-engine' ); ?></label>
						<input type="number" name="shseq_steps[<?php echo $n; ?>][scroll_pct]" min="0" max="100" value="<?php echo $scroll; ?>">
					</div>
					<div class="shseq-wiz-step__field shseq-wiz-step__field--lg">
						<label><?php esc_html_e( 'Heading', 'sh-sequence-engine' ); ?></label>
						<input type="text" name="shseq_steps[<?php echo $n; ?>][heading]" value="<?php echo $heading; ?>" placeholder="<?php esc_attr_e( 'Main headline…', 'sh-sequence-engine' ); ?>">
					</div>
					<div class="shseq-wiz-step__field shseq-wiz-step__field--lg">
						<label><?php esc_html_e( 'Paragraph', 'sh-sequence-engine' ); ?></label>
						<input type="text" name="shseq_steps[<?php echo $n; ?>][paragraph]" value="<?php echo $para; ?>" placeholder="<?php esc_attr_e( 'Subtext…', 'sh-sequence-engine' ); ?>">
					</div>
				</div>
				<div class="shseq-wiz-step__row">
					<div class="shseq-wiz-step__field">
						<label><?php esc_html_e( 'CTA Text', 'sh-sequence-engine' ); ?></label>
						<input type="text" name="shseq_steps[<?php echo $n; ?>][cta_text]" value="<?php echo $ctatxt; ?>" placeholder="<?php esc_attr_e( 'Get started', 'sh-sequence-engine' ); ?>">
					</div>
					<div class="shseq-wiz-step__field">
						<label><?php esc_html_e( 'CTA URL', 'sh-sequence-engine' ); ?></label>
						<input type="url" name="shseq_steps[<?php echo $n; ?>][cta_url]" value="<?php echo $ctaurl; ?>" placeholder="https://">
					</div>
					<div class="shseq-wiz-step__field">
						<label><?php esc_html_e( 'Badge / Price', 'sh-sequence-engine' ); ?></label>
						<input type="text" name="shseq_steps[<?php echo $n; ?>][badge_text]" value="<?php echo $badge; ?>" placeholder="<?php esc_attr_e( 'e.g. &#x1F525; 20% off', 'sh-sequence-engine' ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step 4 — Golden Master
	// ─────────────────────────────────────────────────────────────────────

	private function render_step4( $post_id ) {
		$masters  = $post_id ? GoldenMasterMetaBox::get_masters( $post_id ) : array( 'desktop' => 0, 'tablet' => 0, 'mobile' => 0 );
		$confirms = $post_id ? GoldenMasterMetaBox::get_confirmations( $post_id ) : array();
		$desktop_ready = $masters['desktop'] > 0 && ! empty( $confirms['desktop'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'shseq_wizard_save' ); ?>
			<input type="hidden" name="action" value="shseq_wizard_save">
			<input type="hidden" name="shseq_step" value="4">
			<input type="hidden" name="shseq_post_id" value="<?php echo esc_attr( $post_id ); ?>">

			<div class="shseq-wizard__field">
				<p class="shseq-wizard__label"><?php esc_html_e( 'Golden Master — End Frame (the final frame of your animation)', 'sh-sequence-engine' ); ?></p>
				<p class="description"><?php esc_html_e( 'Upload the single confirmed "last frame" image for each device size. Desktop must be confirmed first. Tablet and Mobile fall back to Desktop if not set.', 'sh-sequence-engine' ); ?></p>

				<?php foreach ( GoldenMasterMetaBox::VARIANTS as $variant ) :
					$att_id    = $masters[ $variant ] ?? 0;
					$confirmed = ! empty( $confirms[ $variant ] );
					$locked    = 'desktop' !== $variant && ! $desktop_ready;
					$thumb     = $att_id ? wp_get_attachment_image_url( $att_id, 'medium' ) : '';
					$labels    = array(
						'desktop' => __( 'Desktop (confirm first)', 'sh-sequence-engine' ),
						'tablet'  => __( 'Tablet', 'sh-sequence-engine' ),
						'mobile'  => __( 'Mobile', 'sh-sequence-engine' ),
					);
				?>
					<div class="shseq-master-panel <?php echo $locked ? 'is-locked' : ''; ?>"
						data-shseq-variant="<?php echo esc_attr( $variant ); ?>"
						data-shseq-picker-title="<?php esc_attr_e( 'Select Golden Master', 'sh-sequence-engine' ); ?>"
						data-shseq-picker-button="<?php esc_attr_e( 'Use this image', 'sh-sequence-engine' ); ?>">

						<div class="shseq-master-panel__label">
							<strong><?php echo esc_html( $labels[ $variant ] ?? $variant ); ?></strong>
							<?php if ( $confirmed && $att_id ) : ?>
								<span class="shseq-master-badge shseq-master-badge--ok"><?php esc_html_e( 'Confirmed', 'sh-sequence-engine' ); ?></span>
							<?php endif; ?>
							<?php if ( $locked ) : ?>
								<span class="shseq-master-lock-note"><?php esc_html_e( '(Confirm desktop first)', 'sh-sequence-engine' ); ?></span>
							<?php endif; ?>
						</div>

						<div class="shseq-master-panel__preview" data-shseq-master-preview>
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="">
							<?php else : ?>
								<span class="shseq-master-empty"><?php esc_html_e( 'No image selected', 'sh-sequence-engine' ); ?></span>
							<?php endif; ?>
						</div>

						<input type="hidden" class="shseq-master-input"
							name="shseq_master[<?php echo esc_attr( $variant ); ?>]"
							value="<?php echo esc_attr( $att_id ); ?>"
							data-shseq-master-field="<?php echo esc_attr( $variant ); ?>">

						<div class="shseq-master-panel__actions">
							<button type="button" class="button shseq-master-select"
								data-shseq-master-select="<?php echo esc_attr( $variant ); ?>"
								<?php disabled( $locked ); ?>>
								<?php esc_html_e( 'Select image', 'sh-sequence-engine' ); ?>
							</button>
							<button type="button" class="button-link shseq-master-remove"
								data-shseq-master-remove="<?php echo esc_attr( $variant ); ?>"
								<?php disabled( $locked ); ?>>
								<?php esc_html_e( 'Remove', 'sh-sequence-engine' ); ?>
							</button>
						</div>

						<label class="shseq-master-panel__confirm">
							<input type="checkbox"
								name="shseq_master_confirm[<?php echo esc_attr( $variant ); ?>]"
								value="1"
								<?php checked( $confirmed ); ?>
								<?php disabled( $locked ); ?>>
							<?php esc_html_e( 'This Golden Master is final and confirmed', 'sh-sequence-engine' ); ?>
						</label>
					</div>
				<?php endforeach; ?>
			</div>

			<?php $this->render_nav( true, true ); ?>
		</form>
		<script>
		(function(){
			document.querySelectorAll('[data-shseq-master-select]').forEach(function(btn){
				btn.addEventListener('click',function(){
					if(btn.disabled)return;
					var v=btn.getAttribute('data-shseq-master-select');
					var panel=btn.closest('[data-shseq-variant="'+v+'"]');
					var input=panel.querySelector('[data-shseq-master-field="'+v+'"]');
					var preview=panel.querySelector('[data-shseq-master-preview]');
					if(typeof wp==='undefined'||!wp.media)return;
					var frame=wp.media({title:panel.getAttribute('data-shseq-picker-title'),button:{text:panel.getAttribute('data-shseq-picker-button')},multiple:false,library:{type:'image'}});
					frame.on('select',function(){
						var att=frame.state().get('selection').first().toJSON();
						if(!att)return;
						input.value=att.id;
						var src=att.sizes&&att.sizes.medium?att.sizes.medium.url:att.url;
						preview.innerHTML='<img src="'+src+'" alt="">';
					});
					frame.open();
				});
			});
			document.querySelectorAll('[data-shseq-master-remove]').forEach(function(btn){
				btn.addEventListener('click',function(){
					if(btn.disabled)return;
					var v=btn.getAttribute('data-shseq-master-remove');
					var panel=btn.closest('[data-shseq-variant="'+v+'"]');
					panel.querySelector('[data-shseq-master-field="'+v+'"]').value='';
					panel.querySelector('[data-shseq-master-preview]').innerHTML='<span class="shseq-master-empty"><?php echo esc_js( __( 'No image selected', 'sh-sequence-engine' ) ); ?></span>';
				});
			});
		}());
		</script>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Step 5 — Preview & Publish
	// ─────────────────────────────────────────────────────────────────────

	private function render_step5( $post_id ) {
		$post        = $post_id ? get_post( $post_id ) : null;
		$status      = $post ? $post->post_status : 'draft';
		$frames      = $post_id ? FrameManager::get_frames( $post_id ) : array();
		$frame_count = count( $frames );
		$masters     = $post_id ? GoldenMasterMetaBox::get_masters( $post_id ) : array();
		$confirms    = $post_id ? GoldenMasterMetaBox::get_confirmations( $post_id ) : array();
		$desktop_ok  = ! empty( $masters['desktop'] ) && ! empty( $confirms['desktop'] );
		$preview_url = $post_id ? SequencePreview::preview_url( $post_id ) : '';
		$shortcode   = $post_id ? '[storyboard_live id="' . $post_id . '"]' : '';

		$can_publish = $frame_count > 0 && $desktop_ok;
		?>
		<div class="shseq-wizard__field">
			<p class="shseq-wizard__label"><?php esc_html_e( 'Ready to publish?', 'sh-sequence-engine' ); ?></p>

			<!-- Checklist -->
			<ul class="shseq-preflight">
				<?php $this->preflight_item( $frame_count > 0, sprintf( _n( '%d frame uploaded', '%d frames uploaded', $frame_count, 'sh-sequence-engine' ), $frame_count ) ); ?>
				<?php $this->preflight_item( $desktop_ok, __( 'Desktop Golden Master confirmed', 'sh-sequence-engine' ) ); ?>
				<?php $this->preflight_item( 'publish' === $status, __( 'Sequence is published', 'sh-sequence-engine' ) ); ?>
			</ul>

			<!-- Preview -->
			<?php if ( $preview_url ) : ?>
			<div class="shseq-preflight__preview">
				<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener" class="button button-large">
					<?php esc_html_e( '👁 Open Preview in new tab', 'sh-sequence-engine' ); ?>
				</a>
				<p class="description"><?php esc_html_e( 'The preview runs at full fidelity — same frames and overlays as the live version, protected by a short-lived token.', 'sh-sequence-engine' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- Shortcode -->
			<?php if ( $shortcode ) : ?>
			<div class="shseq-preflight__shortcode">
				<p class="shseq-wizard__label"><?php esc_html_e( 'Embed shortcode', 'sh-sequence-engine' ); ?></p>
				<code><?php echo esc_html( $shortcode ); ?></code>
				<p class="description"><?php esc_html_e( 'Paste this into any page or post where you want the hero animation.', 'sh-sequence-engine' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- Publish -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px">
				<?php wp_nonce_field( 'shseq_wizard_save' ); ?>
				<input type="hidden" name="action" value="shseq_wizard_save">
				<input type="hidden" name="shseq_step" value="5">
				<input type="hidden" name="shseq_post_id" value="<?php echo esc_attr( $post_id ); ?>">

				<?php if ( 'publish' !== $status ) : ?>
					<input type="hidden" name="shseq_publish_action" value="publish">
					<button type="submit" class="button button-primary button-hero"
						<?php disabled( ! $can_publish ); ?>>
						<?php esc_html_e( 'Publish Sequence', 'sh-sequence-engine' ); ?>
					</button>
					<?php if ( ! $can_publish ) : ?>
						<p class="description" style="color:#d63638;margin-top:6px">
							<?php esc_html_e( 'Upload at least one frame and confirm the Desktop Golden Master before publishing.', 'sh-sequence-engine' ); ?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<p style="color:#00a32a;font-weight:600">
						<?php esc_html_e( '✓ Sequence is live!', 'sh-sequence-engine' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'All Sequences →', 'sh-sequence-engine' ); ?>
					</a>
				<?php endif; ?>
			</form>
		</div>

		<?php $this->render_nav( true, false ); ?>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	private function preflight_item( $ok, $label ) {
		$icon  = $ok ? '✓' : '○';
		$class = $ok ? 'shseq-preflight__item--ok' : 'shseq-preflight__item--pending';
		printf(
			'<li class="shseq-preflight__item %s"><span class="shseq-preflight__icon">%s</span> %s</li>',
			esc_attr( $class ),
			esc_html( $icon ),
			esc_html( $label )
		);
	}

	private function render_nav( $show_back, $show_next ) {
		echo '<div class="shseq-wizard__nav">';
		if ( $show_back ) {
			echo '<button type="button" class="button" onclick="history.back()">'
				. esc_html__( '← Back', 'sh-sequence-engine' ) . '</button>';
		}
		if ( $show_next ) {
			echo '<button type="submit" class="button button-primary">'
				. esc_html__( 'Save & Continue →', 'sh-sequence-engine' ) . '</button>';
		}
		echo '</div>';
	}

	private function render_styles() { ?>
<style>
/* ── Wizard layout ───────────────────────────────── */
.shseq-wizard__header{display:flex;align-items:center;justify-content:space-between;padding:16px 0 12px;border-bottom:1px solid #dcdcde;margin-bottom:20px}
.shseq-wizard__header h1{margin:0;font-size:20px}
.shseq-wizard__body{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:28px 32px;max-width:900px;margin-top:20px}
.shseq-wizard__field{margin-bottom:24px}
.shseq-wizard__label{font-weight:600;font-size:14px;color:#1d2327;margin:0 0 6px}
.shseq-wizard__input{max-width:480px}
.shseq-wizard__nav{display:flex;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid #f0f0f1}
.shseq-wizard__divider{text-align:center;color:#787c82;font-size:12px;margin:16px 0;position:relative}
.shseq-wizard__divider::before,.shseq-wizard__divider::after{content:'';position:absolute;top:50%;width:40%;height:1px;background:#dcdcde}
.shseq-wizard__divider::before{left:0}.shseq-wizard__divider::after{right:0}

/* ── Step bar ────────────────────────────────────── */
.shseq-step-bar{display:flex;gap:0;margin-bottom:0;padding:0;list-style:none}
.shseq-step-bar__item{display:flex;align-items:center;gap:6px;padding:10px 20px;font-size:13px;border-bottom:3px solid #dcdcde;color:#787c82;text-decoration:none;flex:1;justify-content:center}
.shseq-step-bar__item.is-done{color:#1d2327;border-color:#72aee6}
.shseq-step-bar__item.is-active{color:#2271b1;border-color:#2271b1;font-weight:600}
.shseq-step-bar__num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#dcdcde;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.shseq-step-bar__item.is-done .shseq-step-bar__num{background:#72aee6}
.shseq-step-bar__item.is-active .shseq-step-bar__num{background:#2271b1}

/* ── Template picker ─────────────────────────────── */
.shseq-template-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:10px}
.shseq-template-card{display:flex;flex-direction:column;gap:4px;border:2px solid #dcdcde;border-radius:6px;padding:16px;cursor:pointer;position:relative;transition:border-color .15s}
.shseq-template-card:hover{border-color:#72aee6}
.shseq-template-card.is-selected,.shseq-template-card input:checked+~*,.shseq-template-card:has(input:checked){border-color:#2271b1;background:#f0f6fc}
.shseq-template-card input[type=radio]{position:absolute;opacity:0;width:0;height:0}
.shseq-template-card__icon{font-size:28px;line-height:1}
.shseq-template-card__badge{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#0a4b78}
.shseq-template-card__title{font-weight:600;font-size:14px;color:#1d2327}
.shseq-template-card__desc{font-size:12px;color:#50575e;line-height:1.4}
.shseq-template-card__meta{font-size:11px;color:#787c82;margin-top:4px}

/* ── Frame thumbnails ────────────────────────────── */
.shseq-frame-drop{border:2px dashed #c3c4c7;border-radius:6px;padding:20px;min-height:80px}
.shseq-frame-thumbnails{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.shseq-frame-thumb{position:relative;border:1px solid #c3c4c7;border-radius:3px;overflow:hidden;width:80px;height:45px;background:#f0f0f1}
.shseq-frame-thumb img{width:100%;height:100%;object-fit:cover}
.shseq-frame-remove{position:absolute;top:1px;right:1px;background:rgba(0,0,0,.5);color:#fff;border:0;border-radius:2px;cursor:pointer;line-height:1;padding:0 4px;font-size:14px}

/* ── Content steps wizard ─────────────────────────── */
.shseq-wiz-step{border:1px solid #dcdcde;border-radius:4px;padding:14px 16px;margin-bottom:12px;background:#fafafa}
.shseq-wiz-step__head{display:flex;justify-content:space-between;margin-bottom:10px}
.shseq-wiz-step__remove{color:#b32d2e;font-size:12px}
.shseq-wiz-step__row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.shseq-wiz-step__field{flex:1;min-width:140px}
.shseq-wiz-step__field--sm{max-width:90px}
.shseq-wiz-step__field--lg{flex:2}
.shseq-wiz-step__field label{display:block;font-size:12px;color:#50575e;margin-bottom:3px}
.shseq-wiz-step__field input{width:100%}

/* ── AI box ──────────────────────────────────────── */
.shseq-ai-box{background:#f0f6fc;border:1px solid #72aee6;border-radius:6px;padding:16px}

/* ── Golden master panels ─────────────────────────── */
.shseq-master-panel{border:1px solid #dcdcde;border-radius:6px;padding:16px;margin-bottom:14px;background:#fafafa}
.shseq-master-panel.is-locked{opacity:.5;pointer-events:none}
.shseq-master-panel__label{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:14px}
.shseq-master-lock-note{font-size:11px;color:#787c82;font-style:italic}
.shseq-master-panel__preview{min-height:60px;background:#f0f0f1;border:1px dashed #c3c4c7;border-radius:3px;display:flex;align-items:center;justify-content:center;padding:8px;margin-bottom:10px}
.shseq-master-panel__preview img{max-width:100%;max-height:120px;object-fit:contain}
.shseq-master-panel__actions{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.shseq-master-panel__confirm{display:flex;align-items:center;gap:6px;font-size:13px}
.shseq-master-empty{font-size:12px;color:#787c82}

/* ── Preflight checklist ─────────────────────────── */
.shseq-preflight{list-style:none;margin:0 0 20px;padding:0}
.shseq-preflight__item{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f1;font-size:14px}
.shseq-preflight__icon{font-size:18px;width:24px;text-align:center}
.shseq-preflight__item--ok .shseq-preflight__icon{color:#00a32a}
.shseq-preflight__item--pending .shseq-preflight__icon{color:#787c82}
.shseq-preflight__preview,.shseq-preflight__shortcode{margin-bottom:20px}
.shseq-preflight__shortcode code{display:inline-block;background:#f0f0f1;padding:6px 12px;border-radius:3px;font-size:14px;margin:6px 0}
</style>
	<?php
	}
}

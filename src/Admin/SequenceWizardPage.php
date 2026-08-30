<?php
/**
 * Sequence Wizard — صفحه ایجاد سکانس جدید (Loop 3 Final — v3)
 *
 * ۵ مرحله:
 *  Step 1 — نام و قالب
 *  Step 2 — تصویر پایانی / آپلود فریم
 *  Step 3 — مراحل محتوا (Canvas Overlay Editor)
 *  Step 4 — ساخت فریم‌ها (فقط Pro)
 *  Step 5 — پیش‌نمایش و انتشار
 *
 * امنیت:
 *  - Nonce جداگانه برای هر Step
 *  - Rate‌limit روی AI generation
 *  - MIME/size/dimension validation روی آپلود
 *  - ABAC capability check در هر endpoint
 *  - Output escaping همه‌جا
 *  - No serialized user input stored in meta
 *
 * دوزبانه:
 *  - زبان WP بررسی می‌شود: فارسی = fa_IR → همه چیز فارسی
 *  - غیر فارسی → انگلیسی
 *
 * @package StoryBoardLive
 */

declare( strict_types = 1 );

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\Jobs\FrameGenerationJob;
use ShahreHonar\SequenceEngine\License\LicenseManager;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

final class SequenceWizardPage {

	// ── Constants ─────────────────────────────────────────────────────────
	const PAGE_SLUG = 'shseq-create';

	// Meta keys (canonical — همان‌طور که در معماری تعریف شد)
	const META_STEP         = '_shseq_wizard_step';
	const META_MODE         = '_shseq_wizard_mode';
	const META_TEMPLATE_ID  = '_shseq_template_id';
	const META_FRAME_COUNT  = '_shseq_frame_count';
	const META_FINAL_IMG    = '_shseq_end_frame_id';
	const META_CLEAN_BG     = '_shseq_clean_bg_id';
	const META_AI_PROMPT    = '_shseq_ai_prompt';
	const META_OVERLAY      = '_shseq_overlay_data';
	const META_FRAMES       = '_shseq_frames';
	const META_GEN_STATUS   = '_shseq_gen_status';
	const META_GEN_PROGRESS = '_shseq_gen_progress';
	const META_GEN_JOBID    = '_shseq_gen_job_id';
	const META_ERROR        = '_shseq_wizard_error';

	// Nonce actions (post_id تبدیل به suffix می‌شود در PHP)
	const NONCE_S1          = 'shseq_wiz_step1';
	const NONCE_S2          = 'shseq_wiz_step2';
	const NONCE_S3          = 'shseq_wiz_step3';
	const NONCE_S4          = 'shseq_wiz_step4';
	const NONCE_PUBLISH     = 'shseq_wiz_publish';

	// Upload limits
	const MAX_UPLOAD_MB     = 20;         // MB per file
	const MAX_FRAME_FILES   = 40;         // max bulk frames
	const ALLOWED_MIMES     = array( 'image/webp', 'image/jpeg', 'image/png' );
	const MIN_DIM           = 400;        // px minimum width/height
	const MAX_DIM           = 7680;       // px maximum

	// AI rate limit (per‌user per‌day)
	const AI_RATE_OPTION    = 'shseq_ai_rate_%d';   // %d = user_id
	const AI_RATE_MAX       = 3;                     // free regenerations/day
	const AI_RATE_WINDOW    = DAY_IN_SECONDS;

	/** @var TemplateCatalog */
	private TemplateCatalog $catalog;

	public function __construct( TemplateCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	// ── Hooks ──────────────────────────────────────────────────────────────

	public function register_hooks(): void {
		add_action( 'admin_menu',            array( $this, 'register_page'  ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_shseq_wiz_step1',         array( $this, 'ajax_step1'         ) );
		add_action( 'wp_ajax_shseq_wiz_step2_upload',  array( $this, 'ajax_step2_upload'  ) );
		add_action( 'wp_ajax_shseq_wiz_step2_ai',      array( $this, 'ajax_step2_ai'      ) );
		add_action( 'wp_ajax_shseq_wiz_step2_status',  array( $this, 'ajax_step2_ai_status') );
		add_action( 'wp_ajax_shseq_wiz_step2_confirm_frames', array( $this, 'ajax_step2_confirm_frames' ) );
		add_action( 'wp_ajax_shseq_wiz_step3_extract', array( $this, 'ajax_step3_extract' ) );
		add_action( 'wp_ajax_shseq_wiz_step3_save',    array( $this, 'ajax_step3_save'    ) );
		add_action( 'wp_ajax_shseq_wiz_step4_start',   array( $this, 'ajax_step4_start'   ) );
		add_action( 'wp_ajax_shseq_wiz_step4_status',  array( $this, 'ajax_step4_status'  ) );
		add_action( 'wp_ajax_shseq_wiz_publish',       array( $this, 'ajax_publish'       ) );
		add_action( 'wp_ajax_shseq_wiz_back',          array( $this, 'ajax_back'          ) );
		add_action( 'wp_ajax_shseq_wiz_check_name',    array( $this, 'ajax_check_name'    ) );
	}

	public function register_page(): void {
		add_submenu_page(
			null,
			__( 'New Sequence — StoryBoard Live', 'sh-sequence-engine' ),
			__( 'New Sequence', 'sh-sequence-engine' ),
			'edit_shseq_sequences',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		$is_wizard = ( strpos( $hook, self::PAGE_SLUG ) !== false )
			|| ( isset( $_GET['page'] ) && $_GET['page'] === self::PAGE_SLUG );
		if ( ! $is_wizard ) {
			return;
		}
		$ver = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		$url = plugin_dir_url( dirname( __DIR__ ) );

		wp_enqueue_media();

		// Main wizard CSS + Canvas overlay CSS
		wp_enqueue_style(
			'shseq-wizard-v3',
			$url . 'assets/admin/wizard-v3.css',
			array(),
			$ver
		);
		wp_enqueue_style(
			'shseq-wizard-canvas',
			$url . 'assets/admin/wizard-canvas.css',
			array( 'shseq-wizard-v3' ),
			$ver
		);

		// Main wizard JS
		wp_enqueue_script(
			'shseq-wizard-v3',
			$url . 'assets/admin/wizard-v3.js',
			array(),
			$ver,
			true
		);
		// Canvas overlay + Step4 fix JS (loads after main)
		wp_enqueue_script(
			'shseq-wizard-overlay',
			$url . 'assets/admin/wizard-v3-overlay.js',
			array( 'shseq-wizard-v3' ),
			$ver,
			true
		);
		// Hotfix: canvas MutationObserver init, AI error filter, free badge, img meta
		wp_enqueue_script(
			'shseq-wizard-hotfix',
			$url . 'assets/admin/wizard-v3-hotfix.js',
			array( 'shseq-wizard-overlay' ),
			$ver,
			true
		);

		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		// ── Existing data for edit mode ──────────────────────────────────────
		$existing_mode      = $post_id ? (string) get_post_meta( $post_id, self::META_MODE, true ) : '';
		$existing_final_id  = $post_id ? (int) get_post_meta( $post_id, self::META_FINAL_IMG, true ) : 0;
		$existing_final_url = $existing_final_id ? wp_get_attachment_url( $existing_final_id ) : '';
		$existing_frames_raw= $post_id ? get_post_meta( $post_id, self::META_FRAMES, true ) : '';
		$existing_frame_ids = array();
		if ( $existing_frames_raw ) {
			$decoded = json_decode( $existing_frames_raw, true );
			if ( is_array( $decoded ) ) {
				$existing_frame_ids = array_map( 'absint', $decoded );
			}
		}
		$existing_frame_thumbs = array();
		foreach ( $existing_frame_ids as $fid ) {
			$existing_frame_thumbs[] = array(
				'id'    => $fid,
				'url'   => wp_get_attachment_url( $fid ),
				'thumb' => wp_get_attachment_image_url( $fid, 'thumbnail' ),
			);
		}

		wp_localize_script( 'shseq-wizard-v3', 'shseqWizard', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'isPro'        => LicenseManager::is_pro(),
			'postId'       => $post_id,
			'preselectedTemplate' => sanitize_key( isset( $_GET['template_id'] ) ? $_GET['template_id'] : '' ),
			'currentStep'  => $post_id ? (int) get_post_meta( $post_id, self::META_STEP, true ) : 1,
			'restUrl'      => get_rest_url( null, 'shseq/v1' ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'locale'       => $this->locale_code(),
			'maxUploadMb'  => self::MAX_UPLOAD_MB,
			'maxFrames'    => self::MAX_FRAME_FILES,
			'allowedMimes' => self::ALLOWED_MIMES,
			'nonces'       => array(
				's1'      => wp_create_nonce( self::NONCE_S1 ),
				's2'      => wp_create_nonce( self::NONCE_S2 ),
				's3'      => wp_create_nonce( self::NONCE_S3 ),
				's4'      => wp_create_nonce( self::NONCE_S4 ),
				'publish' => wp_create_nonce( self::NONCE_PUBLISH ),
				'back'    => wp_create_nonce( 'shseq_wiz_back' ),
				'name'    => wp_create_nonce( 'shseq_wiz_check_name' ),
			),
			'i18n'         => $this->i18n_strings(),
			'existingData' => array(
				'mode'        => $existing_mode,
				'finalImgId'  => $existing_final_id,
				'finalImgUrl' => $existing_final_url,
				'frames'      => $existing_frame_thumbs,
			),
		) );
	}

	// ── Render ─────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html( $this->t( 'permission_denied' ) ) );
		}

		$post_id     = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$from_tpl    = isset( $_GET['from_template'] ) && (int) $_GET['from_template'] === 1;
		$is_pro      = LicenseManager::is_pro();
		$is_rtl      = is_rtl();
		$step        = $post_id ? max( 1, (int) get_post_meta( $post_id, self::META_STEP, true ) ) : 1;

		// اگر کاربر از صفحه قالب‌های آماده وارد شده: step = 2
		if ( $from_tpl && $post_id ) {
			$step = max( 2, $step );
		}

		// Templates for step 1
		$all_templates  = $this->catalog->all();
		$free_templates = array_filter( $all_templates, fn( $t ) => ! ( $t['isPro'] ?? false ) );
		$pro_templates  = array_filter( $all_templates, fn( $t ) => ( $t['isPro'] ?? false ) );
		$show_templates = $is_pro ? $all_templates : $free_templates;

		// Step titles
		$step_labels = $this->step_labels( $is_pro );
		$total_steps = $is_pro ? 5 : 4; // Pro shows Step 4 (frame gen); Free skips it
		?>
		<div class="wrap shseq-admin shseq-wizard-wrap" dir="<?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">

			<!-- ── Wizard Header ── -->
			<div class="shseq-wiz-header">
				<div class="shseq-wiz-header__brand">
					<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
					<h1>StoryBoard Live — <?php echo esc_html( $this->t( 'new_sequence' ) ); ?></h1>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AllSequencesPage::PAGE_SLUG ) ); ?>" class="shseq-wiz-close" aria-label="<?php echo esc_attr( $this->t( 'close' ) ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</a>
			</div>

			<!-- ── Progress Bar ── -->
			<nav class="shseq-wiz-progress" role="progressbar" aria-label="<?php echo esc_attr( $this->t( 'wizard_progress' ) ); ?>" aria-valuenow="<?php echo (int) $step; ?>" aria-valuemin="1" aria-valuemax="<?php echo (int) $total_steps; ?>">
				<?php foreach ( $step_labels as $n => $label ) : ?>
					<div class="shseq-wiz-step <?php echo $n < $step ? 'is-done' : ( $n === $step ? 'is-active' : '' ); ?>" data-step="<?php echo (int) $n; ?>">
						<div class="shseq-wiz-step__dot">
							<?php if ( $n < $step ) : ?>
								<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php else : ?>
								<span><?php echo (int) $n; ?></span>
							<?php endif; ?>
						</div>
						<span class="shseq-wiz-step__label"><?php echo esc_html( $label ); ?></span>
						<?php if ( $n < $total_steps ) : ?>
							<div class="shseq-wiz-step__connector" aria-hidden="true"></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</nav>

			<!-- ── Main Panel ── -->
			<div class="shseq-wiz-main">

				<!-- SR live region for announcements -->
				<div id="shseq-wiz-sr" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

				<!-- Global error banner -->
				<div id="shseq-wiz-error" class="shseq-wiz-error-banner" role="alert" aria-live="assertive" hidden></div>

				<!-- ═════════════════════════════════════════════════════════
				     STEP 1 — نام و قالب
				     ═════════════════════════════════════════════════════════ -->
				<section
					id="shseq-step-1"
					class="shseq-wiz-panel <?php echo $step === 1 ? 'is-active' : ''; ?>"
					aria-labelledby="shseq-s1-title"
					aria-hidden="<?php echo $step === 1 ? 'false' : 'true'; ?>"
					data-step="1"
				>
					<h2 id="shseq-s1-title" class="shseq-wiz-panel__title">
						<?php echo esc_html( $this->t( 'step1_title' ) ); ?>
					</h2>
					<p class="shseq-wiz-panel__desc"><?php echo esc_html( $this->t( 'step1_desc' ) ); ?></p>

					<!-- Sequence name -->
					<div class="shseq-wiz-field">
						<label for="shseq-seq-name" class="shseq-wiz-label">
							<?php echo esc_html( $this->t( 'sequence_name' ) ); ?>
							<span class="shseq-wiz-required" aria-hidden="true">*</span>
						</label>
						<div class="shseq-name-wrap">
							<input
								type="text"
								id="shseq-seq-name"
								name="sequence_name"
								class="shseq-wiz-input"
								maxlength="200"
								placeholder="<?php echo esc_attr( $this->t( 'name_placeholder' ) ); ?>"
								autocomplete="off"
								aria-required="true"
								aria-describedby="shseq-name-hint shseq-name-status"
								value="<?php echo $post_id ? esc_attr( get_the_title( $post_id ) ) : ''; ?>"
							>
							<span id="shseq-name-status" class="shseq-name-status" aria-live="polite"></span>
						</div>
						<p id="shseq-name-hint" class="shseq-wiz-hint">
							<?php echo esc_html( $this->t( 'name_hint' ) ); ?>
						</p>
					</div>

					<!-- Template picker -->
					<div class="shseq-wiz-field">
						<label class="shseq-wiz-label"><?php echo esc_html( $this->t( 'choose_template' ) ); ?></label>
						<p class="shseq-wiz-hint"><?php echo esc_html( $this->t( 'template_hint' ) ); ?></p>

						<div class="shseq-tpl-picker" role="radiogroup" aria-label="<?php echo esc_attr( $this->t( 'choose_template' ) ); ?>">

							<!-- Blank / بدون قالب -->
							<div
								class="shseq-tpl-card shseq-tpl-card--blank is-selected"
								role="radio"
								aria-checked="true"
								tabindex="0"
								data-template-id="blank"
								data-frame-count="24"
							>
								<div class="shseq-tpl-card__thumb shseq-tpl-card__thumb--blank">
									<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								</div>
								<div class="shseq-tpl-card__body">
									<span class="shseq-tpl-card__name"><?php echo esc_html( $this->t( 'blank_template' ) ); ?></span>
									<span class="shseq-tpl-card__desc"><?php echo esc_html( $this->t( 'blank_template_desc' ) ); ?></span>
								</div>
								<span class="shseq-tpl-card__check" aria-hidden="true"></span>
							</div>

							<!-- Templates list -->
							<?php foreach ( $show_templates as $tpl ) :
								$is_tpl_pro  = $tpl['isPro'] ?? false;
								$locked      = $is_tpl_pro && ! $is_pro;
								$palette     = $tpl['palette'] ?? array( 'bg' => '#1d2327', 'accent' => '#f5a623' );
								$frame_count = $tpl['structure']['totalFrames'] ?? 24;
							?>
							<div
								class="shseq-tpl-card <?php echo $locked ? 'is-locked' : ''; ?>"
								role="radio"
								aria-checked="false"
								tabindex="<?php echo $locked ? '-1' : '0'; ?>"
								data-template-id="<?php echo esc_attr( $tpl['id'] ); ?>"
								data-frame-count="<?php echo (int) $frame_count; ?>"
								aria-disabled="<?php echo $locked ? 'true' : 'false'; ?>"
							>
								<div class="shseq-tpl-card__thumb" style="background:<?php echo esc_attr( $palette['bg'] ); ?>">
									<span class="shseq-tpl-thumb-accent" style="background:<?php echo esc_attr( $palette['accent'] ?? '#f5a623' ); ?>"></span>
									<?php if ( $is_tpl_pro ) : ?>
										<span class="shseq-tpl-pro-badge">Pro</span>
									<?php endif; ?>
								</div>
								<div class="shseq-tpl-card__body">
									<span class="shseq-tpl-card__name"><?php echo esc_html( $tpl['name'] ); ?></span>
									<span class="shseq-tpl-card__meta"><?php echo (int) $frame_count; ?> <?php echo esc_html( $this->t( 'frames' ) ); ?></span>
								</div>
								<span class="shseq-tpl-card__check" aria-hidden="true"></span>
								<?php if ( $locked ) : ?>
									<div class="shseq-tpl-card__lock" aria-hidden="true">
										<span class="dashicons dashicons-lock"></span>
									</div>
								<?php endif; ?>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="shseq-wiz-actions">
						<button type="button" id="shseq-s1-save" class="button button-primary shseq-btn-primary">
							<?php echo esc_html( $this->t( 'save_continue' ) ); ?>
							<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
						</button>
					</div>

					<input type="hidden" id="shseq-selected-template" value="<?php echo esc_attr( isset( $_GET['template_id'] ) && $_GET['template_id'] ? sanitize_key( $_GET['template_id'] ) : 'blank' ); ?>">
					<input type="hidden" id="shseq-post-id" value="<?php echo (int) $post_id; ?>">
				</section>

				<!-- ═════════════════════════════════════════════════════════
				     STEP 2 — تصویر پایانی / آپلود فریم
				     ═════════════════════════════════════════════════════════ -->
				<section
					id="shseq-step-2"
					class="shseq-wiz-panel <?php echo $step === 2 ? 'is-active' : ''; ?>"
					aria-labelledby="shseq-s2-title"
					aria-hidden="<?php echo $step === 2 ? 'false' : 'true'; ?>"
					data-step="2"
				>
					<h2 id="shseq-s2-title" class="shseq-wiz-panel__title">
						<?php echo esc_html( $this->t( $is_pro ? 'step2_title_pro' : 'step2_title_free' ) ); ?>
					</h2>
					<p class="shseq-wiz-panel__desc">
						<?php echo esc_html( $this->t( $is_pro ? 'step2_desc_pro' : 'step2_desc_free' ) ); ?>
					</p>

					<?php if ( ! $is_pro ) : ?>
					<!-- ── FREE: Upload ordered frames ── -->
					<div class="shseq-wiz-mode shseq-mode-free">
						<div class="shseq-upload-zone" id="shseq-frames-dropzone" role="region" aria-label="<?php echo esc_attr( $this->t( 'upload_zone_label' ) ); ?>">
							<div class="shseq-upload-zone__icon" aria-hidden="true">
								<span class="dashicons dashicons-upload"></span>
							</div>
							<p class="shseq-upload-zone__title"><?php echo esc_html( $this->t( 'drop_frames_here' ) ); ?></p>
							<p class="shseq-upload-zone__sub">
								<?php printf(
									esc_html( $this->t( 'upload_limits' ) ),
									self::MAX_UPLOAD_MB,
									self::MAX_FRAME_FILES
								); ?>
							</p>
							<label for="shseq-frames-input" class="button shseq-upload-btn">
								<?php echo esc_html( $this->t( 'choose_frames' ) ); ?>
							</label>
							<input
								type="file"
								id="shseq-frames-input"
								accept="image/webp,image/jpeg,image/png"
								multiple
								class="screen-reader-text"
								aria-label="<?php echo esc_attr( $this->t( 'choose_frames' ) ); ?>"
							>
						</div>

						<!-- Sortable frame grid -->
						<div id="shseq-frames-grid" class="shseq-frames-grid" aria-label="<?php echo esc_attr( $this->t( 'uploaded_frames' ) ); ?>"></div>

						<p class="shseq-wiz-hint" id="shseq-frames-count-hint" aria-live="polite"></p>

						<div class="shseq-upload-progress" id="shseq-upload-progress" hidden>
							<div class="shseq-upload-progress__bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
								<div class="shseq-upload-progress__fill" id="shseq-upload-fill"></div>
							</div>
							<span class="shseq-upload-progress__label" id="shseq-upload-label"></span>
						</div>
					</div>

					<?php else : ?>
					<!-- ── PRO: Tab selector ── -->
					<div class="shseq-wiz-mode shseq-mode-pro">
						<div class="shseq-mode-tabs" role="tablist" aria-label="<?php echo esc_attr( $this->t( 'upload_mode' ) ); ?>">
							<button
								class="shseq-mode-tab shseq-mode-tab--active"
								role="tab"
								aria-selected="true"
								aria-controls="shseq-mode-upload"
								data-mode="upload"
							>
								<span class="dashicons dashicons-upload" aria-hidden="true"></span>
								<?php echo esc_html( $this->t( 'mode_upload' ) ); ?>
							</button>
							<button
								class="shseq-mode-tab"
								role="tab"
								aria-selected="false"
								aria-controls="shseq-mode-ai"
								data-mode="ai"
							>
								<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
								<?php echo esc_html( $this->t( 'mode_ai_generate' ) ); ?>
							</button>
						</div>

						<!-- Mode A: Upload PNG/JPG -->
						<div id="shseq-mode-upload" class="shseq-mode-panel is-active" role="tabpanel">
							<div class="shseq-upload-zone shseq-upload-zone--single" id="shseq-final-dropzone">
								<div class="shseq-upload-zone__icon" aria-hidden="true">
									<span class="dashicons dashicons-format-image"></span>
								</div>
								<p class="shseq-upload-zone__title"><?php echo esc_html( $this->t( 'upload_final_image' ) ); ?></p>
								<p class="shseq-upload-zone__sub">
									<?php printf( esc_html( $this->t( 'upload_final_hint' ) ), self::MAX_UPLOAD_MB ); ?>
								</p>
								<label for="shseq-final-input" class="button shseq-upload-btn">
									<?php echo esc_html( $this->t( 'choose_image' ) ); ?>
								</label>
								<input
									type="file"
									id="shseq-final-input"
									accept="image/jpeg,image/png"
									class="screen-reader-text"
									aria-label="<?php echo esc_attr( $this->t( 'choose_image' ) ); ?>"
								>
							</div>
							<div id="shseq-final-preview" class="shseq-final-preview" hidden>
								<img id="shseq-final-img" src="" alt="" class="shseq-final-img">
								<button type="button" id="shseq-final-remove" class="button shseq-remove-btn" aria-label="<?php echo esc_attr( $this->t( 'remove_image' ) ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								</button>
							</div>
						</div>

						<!-- Mode B: AI generate -->
						<div id="shseq-mode-ai" class="shseq-mode-panel" role="tabpanel" aria-hidden="true">
							<div class="shseq-ai-panel">
								<div class="shseq-wiz-field">
									<label for="shseq-ai-prompt" class="shseq-wiz-label">
										<?php echo esc_html( $this->t( 'ai_prompt_label' ) ); ?>
									</label>
									<textarea
										id="shseq-ai-prompt"
										class="shseq-wiz-textarea"
										rows="4"
										maxlength="1000"
										placeholder="<?php echo esc_attr( $this->t( 'ai_prompt_placeholder' ) ); ?>"
										aria-describedby="shseq-ai-prompt-hint"
									></textarea>
									<p id="shseq-ai-prompt-hint" class="shseq-wiz-hint">
										<?php echo esc_html( $this->t( 'ai_prompt_hint' ) ); ?>
									</p>
								</div>

								<div class="shseq-ai-options">
									<label class="shseq-wiz-label"><?php echo esc_html( $this->t( 'ai_style' ) ); ?></label>
									<div class="shseq-ai-styles">
										<?php foreach ( $this->ai_styles() as $key => $label ) : ?>
										<label class="shseq-ai-style-opt">
											<input type="radio" name="shseq_ai_style" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, 'photorealistic' ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
										<?php endforeach; ?>
									</div>
								</div>

								<!-- Rate limit notice -->
								<div class="shseq-ai-rate-notice">
									<?php printf(
										esc_html( $this->t( 'ai_rate_notice' ) ),
										self::AI_RATE_MAX,
										$this->remaining_ai_calls()
									); ?>
								</div>

								<button type="button" id="shseq-ai-generate" class="button button-primary shseq-btn-primary" <?php echo $this->remaining_ai_calls() <= 0 ? 'disabled' : ''; ?>>
									<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
									<?php echo esc_html( $this->t( 'generate_image' ) ); ?>
								</button>

								<!-- AI generation progress -->
								<div id="shseq-ai-progress" class="shseq-ai-progress" hidden>
									<div class="shseq-spinner" aria-hidden="true"></div>
									<span id="shseq-ai-status-label" aria-live="polite">
										<?php echo esc_html( $this->t( 'generating' ) ); ?>
									</span>
								</div>

								<!-- AI result preview -->
								<div id="shseq-ai-result" class="shseq-ai-result" hidden>
									<img id="shseq-ai-img" src="" alt="" class="shseq-ai-preview-img">
									<div class="shseq-ai-result-actions">
										<button type="button" id="shseq-ai-accept" class="button button-primary">
											<?php echo esc_html( $this->t( 'use_this_image' ) ); ?>
										</button>
										<button type="button" id="shseq-ai-regenerate" class="button">
											<?php echo esc_html( $this->t( 'regenerate' ) ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<div class="shseq-wiz-actions">
						<button type="button" class="button shseq-btn-back" data-target="1">
							<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'back' ) ); ?>
						</button>
						<button type="button" id="shseq-s2-save" class="button button-primary shseq-btn-primary" disabled>
							<?php echo esc_html( $this->t( 'save_continue' ) ); ?>
							<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
						</button>
					</div>
				</section>

				<!-- ═════════════════════════════════════════════════════════
				     STEP 3 — مراحل محتوا / Canvas Overlay Editor
				     ═════════════════════════════════════════════════════════ -->
				<section
					id="shseq-step-3"
					class="shseq-wiz-panel <?php echo $step === 3 ? 'is-active' : ''; ?>"
					aria-labelledby="shseq-s3-title"
					aria-hidden="<?php echo $step === 3 ? 'false' : 'true'; ?>"
					data-step="3"
				>
					<h2 id="shseq-s3-title" class="shseq-wiz-panel__title">
						<?php echo esc_html( $this->t( 'step3_title' ) ); ?>
					</h2>
					<p class="shseq-wiz-panel__desc"><?php echo esc_html( $this->t( 'step3_desc' ) ); ?></p>

					<!-- Extraction loading state -->
					<div id="shseq-extract-loading" class="shseq-extract-loading">
						<div class="shseq-spinner" aria-hidden="true"></div>
						<p aria-live="polite"><?php echo esc_html( $this->t( 'extracting_text' ) ); ?></p>
					</div>

					<!-- Canvas area -->
					<div id="shseq-canvas-wrap" class="shseq-canvas-wrap" hidden>

						<!-- Viewport switcher -->
						<div class="shseq-viewport-tabs" role="tablist" aria-label="<?php echo esc_attr( $this->t( 'viewport' ) ); ?>">
							<button class="shseq-vp-tab shseq-vp-tab--active" role="tab" aria-selected="true" data-viewport="desktop" title="Desktop 1280px">
								<span class="dashicons dashicons-desktop" aria-hidden="true"></span>
								<span class="screen-reader-text">Desktop</span>
							</button>
							<button class="shseq-vp-tab" role="tab" aria-selected="false" data-viewport="tablet" title="Tablet 768px">
								<span class="dashicons dashicons-tablet" aria-hidden="true"></span>
								<span class="screen-reader-text">Tablet</span>
							</button>
							<button class="shseq-vp-tab" role="tab" aria-selected="false" data-viewport="mobile" title="Mobile 375px">
								<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
								<span class="screen-reader-text">Mobile</span>
							</button>
						</div>

						<!-- Canvas container -->
						<div id="shseq-canvas-container" class="shseq-canvas-container" data-viewport="desktop">
							<!-- Background image (no text) rendered here by JS -->
							<div id="shseq-canvas-bg" class="shseq-canvas-bg" aria-hidden="true"></div>

							<!-- Overlay elements rendered by JS (drag+resize+edit) -->
							<div id="shseq-canvas-overlays" class="shseq-canvas-overlays" role="group" aria-label="<?php echo esc_attr( $this->t( 'overlay_elements' ) ); ?>"></div>
						</div>

						<!-- Overlay toolbar -->
						<div class="shseq-overlay-toolbar" id="shseq-overlay-toolbar">
							<button type="button" class="button shseq-add-overlay" data-type="heading" aria-label="<?php echo esc_attr( $this->t( 'add_heading' ) ); ?>">
								<span class="dashicons dashicons-heading" aria-hidden="true"></span>
								<?php echo esc_html( $this->t( 'heading' ) ); ?>
							</button>
							<button type="button" class="button shseq-add-overlay" data-type="paragraph" aria-label="<?php echo esc_attr( $this->t( 'add_paragraph' ) ); ?>">
								<span class="dashicons dashicons-editor-paragraph" aria-hidden="true"></span>
								<?php echo esc_html( $this->t( 'paragraph' ) ); ?>
							</button>
							<button type="button" class="button shseq-add-overlay" data-type="button" aria-label="<?php echo esc_attr( $this->t( 'add_button' ) ); ?>">
								<span class="dashicons dashicons-button" aria-hidden="true"></span>
								CTA
							</button>
							<span class="shseq-toolbar-sep" aria-hidden="true"></span>
							<button type="button" class="button shseq-undo-btn" id="shseq-undo" title="Undo" aria-label="Undo (Ctrl+Z)" disabled>
								<span class="dashicons dashicons-undo" aria-hidden="true"></span>
							</button>
							<button type="button" class="button shseq-redo-btn" id="shseq-redo" title="Redo" aria-label="Redo (Ctrl+Y)" disabled>
								<span class="dashicons dashicons-redo" aria-hidden="true"></span>
							</button>
						</div>

						<!-- Selected element properties panel -->
						<div id="shseq-element-props" class="shseq-element-props" hidden aria-label="<?php echo esc_attr( $this->t( 'element_properties' ) ); ?>">
							<div class="shseq-props-row">
								<label for="shseq-prop-text"><?php echo esc_html( $this->t( 'text' ) ); ?></label>
								<input type="text" id="shseq-prop-text" class="shseq-prop-input">
							</div>
							<div class="shseq-props-row">
								<label for="shseq-prop-size"><?php echo esc_html( $this->t( 'font_size' ) ); ?></label>
								<input type="number" id="shseq-prop-size" class="shseq-prop-input shseq-prop-input--sm" min="10" max="200" value="24">
								<span>px</span>
							</div>
							<div class="shseq-props-row">
								<label for="shseq-prop-color"><?php echo esc_html( $this->t( 'color' ) ); ?></label>
								<input type="color" id="shseq-prop-color" value="#ffffff">
							</div>
							<div class="shseq-props-row">
								<label for="shseq-prop-align"><?php echo esc_html( $this->t( 'align' ) ); ?></label>
								<select id="shseq-prop-align" class="shseq-prop-input">
									<option value="left"><?php echo esc_html( $this->t( 'left' ) ); ?></option>
									<option value="center"><?php echo esc_html( $this->t( 'center' ) ); ?></option>
									<option value="right"><?php echo esc_html( $this->t( 'right' ) ); ?></option>
								</select>
							</div>
							<button type="button" id="shseq-prop-delete" class="button shseq-delete-element">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<?php echo esc_html( $this->t( 'delete_element' ) ); ?>
							</button>
						</div>
					</div>

					<div class="shseq-wiz-actions">
						<button type="button" class="button shseq-btn-back" data-target="2">
							<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'back' ) ); ?>
						</button>
						<button type="button" id="shseq-s3-save" class="button button-primary shseq-btn-primary">
							<?php echo esc_html( $this->t( 'save_continue' ) ); ?>
							<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
						</button>
					</div>
				</section>

				<!-- ═════════════════════════════════════════════════════════
				     STEP 4 — ساخت فریم‌ها (فقط Pro)
				     ═════════════════════════════════════════════════════════ -->
				<?php if ( $is_pro ) : ?>
				<section
					id="shseq-step-4"
					class="shseq-wiz-panel <?php echo $step === 4 ? 'is-active' : ''; ?>"
					aria-labelledby="shseq-s4-title"
					aria-hidden="<?php echo $step === 4 ? 'false' : 'true'; ?>"
					data-step="4"
				>
					<h2 id="shseq-s4-title" class="shseq-wiz-panel__title">
						<?php echo esc_html( $this->t( 'step4_title' ) ); ?>
					</h2>
					<p class="shseq-wiz-panel__desc"><?php echo esc_html( $this->t( 'step4_desc' ) ); ?></p>

					<!-- Frame generation summary -->
					<div class="shseq-gen-summary">
						<div class="shseq-gen-info">
							<div class="shseq-gen-info__item">
								<span class="shseq-gen-info__label"><?php echo esc_html( $this->t( 'total_frames' ) ); ?></span>
								<span class="shseq-gen-info__value" id="shseq-gen-frame-count">—</span>
							</div>
							<div class="shseq-gen-info__item">
								<span class="shseq-gen-info__label"><?php echo esc_html( $this->t( 'final_frame' ) ); ?></span>
								<span class="shseq-gen-info__value" id="shseq-gen-final-label">✓</span>
							</div>
							<div class="shseq-gen-info__item">
								<span class="shseq-gen-info__label"><?php echo esc_html( $this->t( 'to_generate' ) ); ?></span>
								<span class="shseq-gen-info__value" id="shseq-gen-remaining">—</span>
							</div>
						</div>

						<!-- Thumbnail of final frame -->
						<div id="shseq-gen-final-thumb" class="shseq-gen-final-thumb" hidden>
							<img id="shseq-gen-final-img" src="" alt="<?php echo esc_attr( $this->t( 'final_frame' ) ); ?>">
							<span class="shseq-gen-frame-label"><?php echo esc_html( $this->t( 'frame_n' ) ); ?></span>
						</div>
					</div>

					<!-- Generation progress -->
					<div id="shseq-gen-progress-wrap" class="shseq-gen-progress-wrap" hidden>
						<div
							class="shseq-gen-progress-bar"
							role="progressbar"
							aria-valuenow="0"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-label="<?php echo esc_attr( $this->t( 'generating_frames' ) ); ?>"
						>
							<div class="shseq-gen-progress-fill" id="shseq-gen-fill"></div>
						</div>
						<div class="shseq-gen-progress-info">
							<span id="shseq-gen-status-label" aria-live="polite"></span>
							<span id="shseq-gen-pct">0%</span>
						</div>
					</div>

					<!-- Frame thumbnail strip (filled as frames complete) -->
					<div id="shseq-gen-strip" class="shseq-gen-strip" aria-label="<?php echo esc_attr( $this->t( 'generated_frames' ) ); ?>"></div>

					<!-- Checkpoint resume notice -->
					<div id="shseq-gen-resume" class="shseq-notice shseq-notice--info" hidden>
						<span class="dashicons dashicons-info" aria-hidden="true"></span>
						<?php echo esc_html( $this->t( 'generation_resumable' ) ); ?>
					</div>

					<div class="shseq-wiz-actions">
						<button type="button" class="button shseq-btn-back" data-target="3">
							<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'back' ) ); ?>
						</button>
						<button type="button" id="shseq-s4-start" class="button button-primary shseq-btn-primary">
							<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'start_generation' ) ); ?>
						</button>
						<button type="button" id="shseq-s4-next" class="button button-primary shseq-btn-primary" hidden>
							<?php echo esc_html( $this->t( 'save_continue' ) ); ?>
							<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
						</button>
					</div>
				</section>
				<?php endif; ?>

				<!-- ═════════════════════════════════════════════════════════
				     STEP 5 — پیش‌نمایش و انتشار
				     ═════════════════════════════════════════════════════════ -->
				<section
					id="shseq-step-5"
					class="shseq-wiz-panel <?php echo $step === ( $is_pro ? 5 : 4 ) ? 'is-active' : ''; ?>"
					aria-labelledby="shseq-s5-title"
					aria-hidden="<?php echo $step === ( $is_pro ? 5 : 4 ) ? 'false' : 'true'; ?>"
					data-step="<?php echo $is_pro ? 5 : 4; ?>"
				>
					<h2 id="shseq-s5-title" class="shseq-wiz-panel__title">
						<?php echo esc_html( $this->t( 'step5_title' ) ); ?>
					</h2>

					<div class="shseq-review-layout">

						<!-- Summary cards -->
						<div class="shseq-review-summary">
							<div class="shseq-review-card">
								<h3><?php echo esc_html( $this->t( 'summary' ) ); ?></h3>
								<dl id="shseq-review-list" class="shseq-review-list"></dl>
							</div>
						</div>

						<!-- Preview iframe -->
						<div class="shseq-review-preview">
							<div class="shseq-preview-vp-tabs" role="tablist">
								<button class="shseq-pvp-tab shseq-pvp-tab--active" role="tab" aria-selected="true" data-pvp="desktop">
									<span class="dashicons dashicons-desktop" aria-hidden="true"></span>
								</button>
								<button class="shseq-pvp-tab" role="tab" aria-selected="false" data-pvp="tablet">
									<span class="dashicons dashicons-tablet" aria-hidden="true"></span>
								</button>
								<button class="shseq-pvp-tab" role="tab" aria-selected="false" data-pvp="mobile">
									<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
								</button>
							</div>
							<div id="shseq-preview-frame-wrap" class="shseq-preview-frame-wrap" data-pvp="desktop">
								<iframe id="shseq-preview-iframe" class="shseq-preview-iframe" title="<?php echo esc_attr( $this->t( 'sequence_preview' ) ); ?>" src=""></iframe>
							</div>

							<!-- Shortcode copy -->
							<div class="shseq-shortcode-row" id="shseq-shortcode-row" hidden>
								<label><?php echo esc_html( $this->t( 'shortcode' ) ); ?></label>
								<button type="button" class="shseq-copy-shortcode" id="shseq-copy-sc" aria-label="<?php echo esc_attr( $this->t( 'copy_shortcode' ) ); ?>">
									<code id="shseq-sc-code"></code>
									<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								</button>
							</div>
						</div>
					</div>

					<!-- Preflight checklist -->
					<div id="shseq-preflight" class="shseq-preflight" aria-live="polite">
						<h3><?php echo esc_html( $this->t( 'preflight' ) ); ?></h3>
						<ul class="shseq-preflight-list" id="shseq-preflight-list"></ul>
					</div>

					<div class="shseq-wiz-actions shseq-wiz-actions--publish">
						<button type="button" class="button shseq-btn-back" data-target="<?php echo $is_pro ? 4 : 3; ?>">
							<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'back' ) ); ?>
						</button>
						<a id="shseq-preview-btn" href="#" target="_blank" rel="noopener noreferrer" class="button shseq-preview-btn">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'preview' ) ); ?>
						</a>
						<button type="button" id="shseq-publish-btn" class="button button-primary shseq-btn-publish">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php echo esc_html( $this->t( 'publish' ) ); ?>
						</button>
					</div>

					<!-- Post-publish success -->
					<div id="shseq-publish-success" class="shseq-publish-success" hidden role="status">
						<span class="dashicons dashicons-yes-alt shseq-publish-success__icon" aria-hidden="true"></span>
						<h3><?php echo esc_html( $this->t( 'published_title' ) ); ?></h3>
						<p id="shseq-publish-msg"></p>
						<div class="shseq-publish-actions">
							<a id="shseq-view-btn" href="#" target="_blank" rel="noopener noreferrer" class="button button-primary">
								<?php echo esc_html( $this->t( 'view_sequence' ) ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AllSequencesPage::PAGE_SLUG ) ); ?>" class="button">
								<?php echo esc_html( $this->t( 'all_sequences' ) ); ?>
							</a>
						</div>
					</div>
				</section>

			</div><!-- .shseq-wiz-main -->

			<!-- Toast -->
			<div id="shseq-toast" class="shseq-toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>

		</div>
		<?php
	}

	// ── AJAX Handlers ──────────────────────────────────────────────────────

	/**
	 * Step 1: ذخیره نام + قالب → ایجاد یا بروزرسانی post draft
	 */
	public function ajax_step1(): void {
		check_ajax_referer( self::NONCE_S1, 'nonce' );
		if ( ! current_user_can( 'create_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'permission_denied' ) ), 403 );
		}

		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$template_id = sanitize_key( $_POST['template_id'] ?? 'blank' );
		$post_id     = (int) ( $_POST['post_id'] ?? 0 );

		// Validate name
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'name_required' ), 'field' => 'name' ), 422 );
		}
		if ( mb_strlen( $name ) > 200 ) {
			wp_send_json_error( array( 'message' => $this->t( 'name_too_long' ), 'field' => 'name' ), 422 );
		}

		// Uniqueness check (exclude current post_id)
		if ( $this->title_exists( $name, $post_id ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'name_duplicate' ), 'field' => 'name', 'code' => 'duplicate' ), 409 );
		}

		// Validate template
		$frame_count = 24; // default
		$template    = null;
		if ( $template_id !== 'blank' ) {
			$template = $this->catalog->get( $template_id );
			if ( ! $template ) {
				wp_send_json_error( array( 'message' => $this->t( 'invalid_template' ) ), 400 );
			}
			$frame_count = (int) ( $template['structure']['totalFrames'] ?? 24 );
		}

		// Create or update post
		if ( $post_id && get_post_type( $post_id ) === SequencePostType::POST_TYPE ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $name, 'post_status' => 'draft' ) );
		} else {
			$post_id = wp_insert_post( array(
				'post_type'   => SequencePostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $name,
				'post_author' => get_current_user_id(),
			), true );
			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
			}
		}

		// Save meta
		update_post_meta( $post_id, self::META_STEP,        2 );
		update_post_meta( $post_id, self::META_FRAME_COUNT, $frame_count );
		update_post_meta( $post_id, self::META_TEMPLATE_ID, $template_id );

		if ( $template ) {
			update_post_meta( $post_id, self::META_OVERLAY,
				wp_json_encode( $template['structure']['overlays'] ?? array() ) );
		}

		wp_send_json_success( array(
			'postId'     => $post_id,
			'nextStep'   => 2,
			'frameCount' => $frame_count,
		) );
	}

	/**
	 * بررسی آنی یکتایی نام (debounced از JS)
	 */
	public function ajax_check_name(): void {
		check_ajax_referer( 'shseq_wiz_check_name', 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}
		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		wp_send_json_success( array(
			'available' => ! $this->title_exists( $name, $post_id ),
		) );
	}

	/**
	 * Step 2 — Free: آپلود فریم‌ها (batch)
	 */
	public function ajax_step2_upload(): void {
		check_ajax_referer( self::NONCE_S2, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'permission_denied' ) ), 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== SequencePostType::POST_TYPE ) {
			wp_send_json_error( array( 'message' => $this->t( 'invalid_post' ) ), 400 );
		}

		$is_final = (bool) ( $_POST['is_final_image'] ?? false );

		if ( ! isset( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'no_file' ) ), 400 );
		}

		// Validate file
		$file = $_FILES['file'];
		$validation = $this->validate_upload( $file, $is_final );
		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'message' => $validation->get_error_message() ), 422 );
		}

		// Upload via WP media
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 500 );
		}

		if ( $is_final ) {
			// Pro mode A: final image
			update_post_meta( $post_id, self::META_FINAL_IMG, $attachment_id );
			update_post_meta( $post_id, self::META_MODE, 'upload' );
			update_post_meta( $post_id, self::META_STEP, 3 );
		}

		$url = wp_get_attachment_url( $attachment_id );
		wp_send_json_success( array(
			'attachmentId' => $attachment_id,
			'url'          => $url,
			'thumb'        => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		) );
	}

	/**
	 * Step 2 — Free: confirm frame order + advance to Step 3
	 */
	public function ajax_step2_confirm_frames(): void {
		check_ajax_referer( self::NONCE_S2, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}
		$post_id    = (int) ( $_POST['post_id'] ?? 0 );
		// frame_ids is sent as JSON string from JS
		$raw_ids   = isset( $_POST['frame_ids'] ) ? wp_unslash( $_POST['frame_ids'] ) : '[]';
		$decoded   = json_decode( $raw_ids, true );
		$frame_ids = is_array( $decoded ) ? array_map( 'absint', $decoded ) : array();
		if ( empty( $frame_ids ) ) {
			// Fallback: try frame_ids[] (multi-value POST)
			$frame_ids = isset( $_POST['frame_ids'] ) ? array_map( 'absint', (array) $_POST['frame_ids'] ) : array();
		}

		if ( ! $post_id || empty( $frame_ids ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'no_frames' ) ), 400 );
		}

		// Validate ownership of each attachment
		foreach ( $frame_ids as $fid ) {
			if ( get_post_type( $fid ) !== 'attachment' ) {
				wp_send_json_error( array( 'message' => $this->t( 'invalid_attachment' ) ), 400 );
			}
		}

		// Free: last frame is the final image
		$final_id = end( $frame_ids );
		update_post_meta( $post_id, self::META_FRAMES,    wp_json_encode( $frame_ids ) );
		update_post_meta( $post_id, self::META_FINAL_IMG, $final_id );
		update_post_meta( $post_id, self::META_MODE,      'frames' );
		update_post_meta( $post_id, self::META_STEP,      3 );

		wp_send_json_success( array( 'nextStep' => 3, 'finalImageUrl' => wp_get_attachment_url( $final_id ) ) );
	}

	/**
	 * Step 2 Pro — Mode B: kick off AI image generation
	 * Uses Action Scheduler for async; returns job ID for polling.
	 */
	public function ajax_step2_ai(): void {
		check_ajax_referer( self::NONCE_S2, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'permission_denied' ) ), 403 );
		}
		if ( ! LicenseManager::is_pro() ) {
			wp_send_json_error( array( 'message' => $this->t( 'pro_only' ) ), 403 );
		}

		// Rate limit
		if ( $this->remaining_ai_calls() <= 0 ) {
			wp_send_json_error( array( 'message' => $this->t( 'ai_rate_exceeded' ) ), 429 );
		}

		$post_id    = (int) ( $_POST['post_id'] ?? 0 );
		$raw_prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$style      = sanitize_key( $_POST['style'] ?? 'photorealistic' );

		if ( ! $post_id || get_post_type( $post_id ) !== SequencePostType::POST_TYPE ) {
			wp_send_json_error( array( 'message' => $this->t( 'invalid_post' ) ), 400 );
		}
		if ( empty( trim( $raw_prompt ) ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'prompt_required' ) ), 422 );
		}
		if ( mb_strlen( $raw_prompt ) > 1000 ) {
			wp_send_json_error( array( 'message' => $this->t( 'prompt_too_long' ) ), 422 );
		}

		// Build master prompt (security + optimization)
		$master_prompt = $this->build_master_prompt( $raw_prompt, $style, $post_id );

		// Store prompt
		update_post_meta( $post_id, self::META_AI_PROMPT, $raw_prompt );
		update_post_meta( $post_id, self::META_MODE, 'ai' );
		update_post_meta( $post_id, '_shseq_ai_status', 'generating' );

		// Schedule async generation
		$job_id = null;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$job_id = as_enqueue_async_action(
				'shseq_generate_final_image',
				array( 'post_id' => $post_id, 'prompt' => $master_prompt ),
				'shseq'
			);
			update_post_meta( $post_id, '_shseq_ai_job_id', $job_id );
		} else {
			// Fallback: synchronous (slower but safe)
			$this->run_ai_generation_sync( $post_id, $master_prompt );
		}

		// Decrement rate limit
		$this->decrement_ai_rate();

		wp_send_json_success( array( 'jobId' => $job_id, 'polling' => true ) );
	}

	/** Poll AI image generation status */
	public function ajax_step2_ai_status(): void {
		check_ajax_referer( self::NONCE_S2, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$status  = get_post_meta( $post_id, '_shseq_ai_status', true ) ?: 'generating';
		$img_id  = (int) get_post_meta( $post_id, self::META_FINAL_IMG, true );
		$url     = $img_id ? wp_get_attachment_url( $img_id ) : '';
		$error   = get_post_meta( $post_id, '_shseq_ai_error', true ) ?: '';

		wp_send_json_success( array(
			'status' => $status,
			'url'    => $url,
			'error'  => $error,
			'done'   => ( $status === 'done' ),
		) );
	}

	/** Step 3: استخراج متن از تصویر نهایی → Vision API */
	public function ajax_step3_extract(): void {
		check_ajax_referer( self::NONCE_S3, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}

		$post_id     = (int) ( $_POST['post_id'] ?? 0 );
		$final_img_id = (int) get_post_meta( $post_id, self::META_FINAL_IMG, true );

		if ( ! $final_img_id ) {
			wp_send_json_error( array( 'message' => $this->t( 'no_final_image' ) ), 400 );
		}

		$image_url = wp_get_attachment_url( $final_img_id );

		// If Pro and OpenAI configured → Vision extraction
		$overlays = array();
		if ( LicenseManager::is_pro() && ! empty( get_option( 'shseq_openai_api_key' ) ) ) {
			$overlays = $this->extract_overlays_via_vision( $image_url, $post_id );
		}

		// Return clean background URL + extracted overlays
		$clean_bg_id = (int) get_post_meta( $post_id, self::META_CLEAN_BG, true );
		$clean_url   = $clean_bg_id ? wp_get_attachment_url( $clean_bg_id ) : $image_url;

		wp_send_json_success( array(
			'finalImageUrl' => $image_url,
			'cleanBgUrl'    => $clean_url,
			'overlays'      => $overlays,
		) );
	}

	/** Step 3: ذخیره overlay data */
	public function ajax_step3_save(): void {
		check_ajax_referer( self::NONCE_S3, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}

		$post_id  = (int) ( $_POST['post_id'] ?? 0 );
		$overlays = isset( $_POST['overlays'] ) ? wp_unslash( $_POST['overlays'] ) : '[]';

		// Validate JSON
		$decoded = json_decode( $overlays, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'invalid_overlay_data' ) ), 422 );
		}

		// Sanitize each overlay item
		$sanitized = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) continue;
			$sanitized[] = array(
				'id'       => sanitize_key( $item['id']       ?? uniqid( 'el_', true ) ),
				'type'     => sanitize_key( $item['type']     ?? 'heading' ),
				'text'     => sanitize_text_field( $item['text']     ?? '' ),
				'x'        => min( 100, max( 0, (float) ( $item['x'] ?? 0 ) ) ),
				'y'        => min( 100, max( 0, (float) ( $item['y'] ?? 0 ) ) ),
				'w'        => min( 100, max( 5,  (float) ( $item['w'] ?? 30 ) ) ),
				'h'        => min( 100, max( 3,  (float) ( $item['h'] ?? 10 ) ) ),
				'fontSize' => min( 200, max( 10, (int) ( $item['fontSize'] ?? 24 ) ) ),
				'color'    => sanitize_hex_color( $item['color'] ?? '#ffffff' ),
				'align'    => in_array( $item['align'] ?? 'left', array( 'left', 'center', 'right' ), true ) ? $item['align'] : 'left',
			);
		}

		update_post_meta( $post_id, self::META_OVERLAY, wp_json_encode( $sanitized ) );

		$is_pro    = LicenseManager::is_pro();
		// For both Free and Pro, the next step number is 4.
		// Free: data-step="4" on the publish panel (Free skips frame gen).
		// Pro:  data-step="4" on the frame gen panel.
		$next_step = 4;
		update_post_meta( $post_id, self::META_STEP, $next_step );

		wp_send_json_success( array( 'nextStep' => $next_step ) );
	}

	/** Step 4: شروع ساخت فریم‌ها (Pro only) */
	public function ajax_step4_start(): void {
		check_ajax_referer( self::NONCE_S4, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) || ! LicenseManager::is_pro() ) {
			wp_send_json_error( null, 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => $this->t( 'invalid_post' ) ), 400 );
		}

		// Don't re-queue if already running
		$status = get_post_meta( $post_id, self::META_GEN_STATUS, true );
		if ( in_array( $status, array( 'pending', 'stage1', 'stage2', 'stage3' ), true ) ) {
			wp_send_json_success( array( 'status' => $status, 'alreadyRunning' => true ) );
		}

		// Schedule via Action Scheduler
		$job = new FrameGenerationJob(
			new \ShahreHonar\SequenceEngine\AI\OpenAIProvider(),
			new \ShahreHonar\SequenceEngine\AI\ReplicateProvider()
		);
		$action_id = $job->schedule( $post_id );

		if ( ! $action_id ) {
			// No AS available → show manual fallback notice
			wp_send_json_error( array( 'message' => $this->t( 'action_scheduler_missing' ) ), 503 );
		}

		wp_send_json_success( array( 'jobId' => $action_id, 'status' => 'pending' ) );
	}

	/** Step 4: poll generation status */
	public function ajax_step4_status(): void {
		check_ajax_referer( self::NONCE_S4, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$status  = get_post_meta( $post_id, FrameGenerationJob::META_STATUS, true ) ?: 'idle';
		$error   = get_post_meta( $post_id, FrameGenerationJob::META_ERROR,  true ) ?: '';
		$count   = FrameManager::count( $post_id );
		$total   = (int) get_post_meta( $post_id, self::META_FRAME_COUNT, true );
		$pct     = $total > 0 ? min( 100, (int) round( $count / $total * 100 ) ) : 0;

		wp_send_json_success( array(
			'status'  => $status,
			'error'   => $error,
			'frames'  => $count,
			'total'   => $total,
			'percent' => $pct,
			'done'    => ( $status === 'done' ),
			'labels'  => array(
				'pending' => $this->t( 'queued' ),
				'stage1'  => $this->t( 'gen_stage1' ),
				'stage2'  => $this->t( 'gen_stage2' ),
				'stage3'  => $this->t( 'gen_stage3' ),
				'done'    => $this->t( 'gen_done' ),
				'failed'  => $this->t( 'gen_failed' ),
			),
		) );
	}

	/** Step 4: advance to Step 5 after generation done */
	public function ajax_back(): void {
		check_ajax_referer( 'shseq_wiz_back', 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( null, 403 );
		}
		$post_id    = (int) ( $_POST['post_id'] ?? 0 );
		$target_step= max( 1, (int) ( $_POST['target_step'] ?? 1 ) );
		if ( $post_id ) {
			update_post_meta( $post_id, self::META_STEP, $target_step );
		}
		wp_send_json_success( array( 'step' => $target_step ) );
	}

	/** Step 5: publish */
	public function ajax_publish(): void {
		check_ajax_referer( self::NONCE_PUBLISH, 'nonce' );
		if ( ! current_user_can( 'publish_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->t( 'permission_denied' ) ), 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== SequencePostType::POST_TYPE ) {
			wp_send_json_error( array( 'message' => $this->t( 'invalid_post' ) ), 400 );
		}

		// Preflight checks
		$frame_count = FrameManager::count( $post_id );
		$is_pro      = LicenseManager::is_pro();

		if ( $is_pro && $frame_count < 2 ) {
			wp_send_json_error( array( 'message' => $this->t( 'not_enough_frames' ) ), 422 );
		}

		$result = wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$shortcode  = '[storyboard_live id="' . $post_id . '"]';
		$preview_tk = wp_create_nonce( "shseq_preview_{$post_id}" );
		$preview_url= add_query_arg( array( 'shseq_preview' => $post_id, '_shseq_token' => $preview_tk ), home_url( '/' ) );

		update_post_meta( $post_id, self::META_STEP, 5 );

		wp_send_json_success( array(
			'shortcode'   => $shortcode,
			'previewUrl'  => $preview_url,
			'permalink'   => get_permalink( $post_id ),
			'editUrl'     => get_edit_post_link( $post_id, 'raw' ),
		) );
	}

	// ── Private helpers ────────────────────────────────────────────────────

	/**
	 * Validate uploaded file against security and size rules.
	 */
	private function validate_upload( array $file, bool $is_final ): true|\WP_Error {
		// Size
		$max_bytes = self::MAX_UPLOAD_MB * 1024 * 1024;
		if ( $file['size'] > $max_bytes ) {
			return new \WP_Error( 'shseq_file_too_large',
				sprintf( $this->t( 'file_too_large' ), self::MAX_UPLOAD_MB ) );
		}

		// MIME — use WordPress finfo for real content detection
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$real_mime = $finfo->file( $file['tmp_name'] );
		if ( ! in_array( $real_mime, self::ALLOWED_MIMES, true ) ) {
			return new \WP_Error( 'shseq_invalid_mime', $this->t( 'invalid_file_type' ) );
		}

		// For final Pro image: only JPEG/PNG
		if ( $is_final && ! in_array( $real_mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new \WP_Error( 'shseq_invalid_final_mime', $this->t( 'final_must_be_jpg_png' ) );
		}

		// Dimensions
		$info = getimagesize( $file['tmp_name'] );
		if ( ! $info ) {
			return new \WP_Error( 'shseq_invalid_image', $this->t( 'invalid_image' ) );
		}
		list( $w, $h ) = $info;
		if ( $w < self::MIN_DIM || $h < self::MIN_DIM ) {
			return new \WP_Error( 'shseq_image_too_small',
				sprintf( $this->t( 'image_too_small' ), self::MIN_DIM ) );
		}
		if ( $w > self::MAX_DIM || $h > self::MAX_DIM ) {
			return new \WP_Error( 'shseq_image_too_large',
				sprintf( $this->t( 'image_dim_too_large' ), self::MAX_DIM ) );
		}

		return true;
	}

	/**
	 * Build a master prompt for DALL·E 3.
	 * خط قرمزها:
	 *  1. بهینه‌سازی مستر پرامپت
	 *  2. هیچ تغییری بجز تغییرات خواسته‌شده
	 *  3. رعایت امنیت بالا (injection prevention)
	 */
	private function build_master_prompt( string $raw_prompt, string $style, int $post_id ): string {
		// Sanitize and strip potential prompt injection
		$safe_prompt = preg_replace( '/\bignore\s+(previous|above|all)\b/i', '', $raw_prompt );
		$safe_prompt = wp_strip_all_tags( $safe_prompt );
		$safe_prompt = trim( $safe_prompt );

		$frame_count = (int) get_post_meta( $post_id, self::META_FRAME_COUNT, true ) ?: 24;
		$template_id = get_post_meta( $post_id, self::META_TEMPLATE_ID, true ) ?: 'blank';

		$style_map = array(
			'photorealistic' => 'ultra-photorealistic, 8K resolution, professional photography',
			'cinematic'      => 'cinematic, wide-angle shot, film grain, movie-quality lighting',
			'illustration'   => 'high-quality digital illustration, sharp lines, vibrant colors',
			'minimal'        => 'minimalist, clean, airy, white space, elegant typography-ready',
			'dark'           => 'dark dramatic lighting, deep shadows, moody atmosphere',
		);
		$style_desc = $style_map[ $style ] ?? $style_map['photorealistic'];

		// Master prompt template — professional, secure, optimized
		$master = sprintf(
			'Create a %s hero image for a scroll-driven web sequence with %d frames. '
			. 'The image is the FINAL approved frame (frame N/%d): it must show the complete scene at its most polished state. '
			. 'CRITICAL RULES: '
			. '(1) NO baked-in text, logos, UI elements, or typography in the image pixels — text will be added as HTML overlays. '
			. '(2) Leave clear negative space areas where text overlays will appear (typically top-third and bottom-third). '
			. '(3) Composition: 16:9 aspect ratio, 1792×1024px. '
			. '(4) Color palette should support light-colored text overlays. '
			. 'Subject and scene: %s. '
			. 'Visual style: %s.',
			$template_id !== 'blank' ? 'template-matched' : 'original',
			$frame_count,
			$frame_count,
			$safe_prompt,
			$style_desc
		);

		return $master;
	}

	/**
	 * Synchronous AI image generation fallback (when no Action Scheduler).
	 */
	private function run_ai_generation_sync( int $post_id, string $prompt ): void {
		$provider = new \ShahreHonar\SequenceEngine\AI\OpenAIProvider();
		$result   = $provider->generate_start_frame( $prompt, $post_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_shseq_ai_status', 'failed' );
			update_post_meta( $post_id, '_shseq_ai_error', $result->get_error_message() );
		} else {
			update_post_meta( $post_id, self::META_FINAL_IMG, $result );
			update_post_meta( $post_id, '_shseq_ai_status', 'done' );
		}
	}

	/**
	 * Extract text overlays from image via GPT-4 Vision.
	 */
	private function extract_overlays_via_vision( string $image_url, int $post_id ): array {
		$api_key = get_option( 'shseq_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			return array();
		}

		// Security: only allow images from our own domain or trusted CDN
		$parsed = wp_parse_url( $image_url );
		$home   = wp_parse_url( home_url() );
		if ( isset( $parsed['host'] ) && $parsed['host'] !== $home['host'] ) {
			// External image — skip Vision extraction for security
			return array();
		}

		$vision_prompt = 'Analyze this image and extract ALL text elements visible. '
			. 'For each text element, return a JSON array with: '
			. '"text" (exact text content), "type" (heading|paragraph|button), '
			. '"x" (left position as % 0-100), "y" (top position as % 0-100), '
			. '"w" (width as % 0-100), "h" (height as % 0-100), '
			. '"fontSize" (estimated px), "color" (hex color). '
			. 'IMPORTANT: return ONLY valid JSON array, no markdown, no explanation. '
			. 'If no text found, return empty array []. Maximum 20 elements.';

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array(
					'model'      => 'gpt-4-vision-preview',
					'max_tokens' => 1500,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => array(
								array( 'type' => 'text', 'text' => $vision_prompt ),
								array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url, 'detail' => 'high' ) ),
							),
						),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = $body['choices'][0]['message']['content'] ?? '';
		$overlays = json_decode( $content, true );

		if ( ! is_array( $overlays ) || json_last_error() !== JSON_ERROR_NONE ) {
			return array();
		}

		// Sanitize Vision output (never trust external API)
		$clean = array();
		foreach ( array_slice( $overlays, 0, 20 ) as $idx => $item ) {
			if ( ! is_array( $item ) ) continue;
			$clean[] = array(
				'id'       => 'vision_' . $idx,
				'type'     => in_array( $item['type'] ?? '', array( 'heading', 'paragraph', 'button' ), true ) ? $item['type'] : 'paragraph',
				'text'     => sanitize_text_field( (string) ( $item['text'] ?? '' ) ),
				'x'        => min( 100, max( 0, (float) ( $item['x'] ?? 10 ) ) ),
				'y'        => min( 100, max( 0, (float) ( $item['y'] ?? 10 ) ) ),
				'w'        => min( 100, max( 5,  (float) ( $item['w'] ?? 30 ) ) ),
				'h'        => min( 100, max( 3,  (float) ( $item['h'] ?? 10 ) ) ),
				'fontSize' => min( 200, max( 10, (int)   ( $item['fontSize'] ?? 24 ) ) ),
				'color'    => sanitize_hex_color( (string) ( $item['color'] ?? '#ffffff' ) ) ?: '#ffffff',
				'align'    => in_array( $item['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $item['align'] : 'left',
			);
		}

		return $clean;
	}

	/**
	 * Check if a sequence title already exists.
	 */
	private function title_exists( string $title, int $exclude_id = 0 ): bool {
		if ( empty( trim( $title ) ) ) {
			return false;
		}
		$existing = get_posts( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'exclude'        => $exclude_id ? array( $exclude_id ) : array(),
		) );
		return ! empty( $existing );
	}

	/** Count remaining AI generation calls today for current user. */
	private function remaining_ai_calls(): int {
		$uid    = get_current_user_id();
		$opt    = sprintf( self::AI_RATE_OPTION, $uid );
		$data   = get_transient( $opt );
		if ( false === $data ) {
			return self::AI_RATE_MAX;
		}
		$used = (int) ( $data['used'] ?? 0 );
		return max( 0, self::AI_RATE_MAX - $used );
	}

	private function decrement_ai_rate(): void {
		$uid  = get_current_user_id();
		$opt  = sprintf( self::AI_RATE_OPTION, $uid );
		$data = get_transient( $opt ) ?: array( 'used' => 0 );
		$data['used'] = (int) $data['used'] + 1;
		set_transient( $opt, $data, self::AI_RATE_WINDOW );
	}

	/** Detect site locale: returns 'fa' or 'en'. */
	private function locale_code(): string {
		$locale = get_locale();
		return ( $locale === 'fa_IR' || strpos( $locale, 'fa' ) === 0 ) ? 'fa' : 'en';
	}

	/** Step labels based on plan. */
	private function step_labels( bool $is_pro ): array {
		$labels = array(
			1 => $this->t( 'step1_short' ),
			2 => $this->t( $is_pro ? 'step2_short_pro' : 'step2_short_free' ),
			3 => $this->t( 'step3_short' ),
			4 => $is_pro ? $this->t( 'step4_short' ) : $this->t( 'step5_short' ),
		);
		if ( $is_pro ) {
			$labels[5] = $this->t( 'step5_short' );
		}
		return $labels;
	}

	/** AI style options. */
	private function ai_styles(): array {
		return array(
			'photorealistic' => $this->t( 'style_photo'  ),
			'cinematic'      => $this->t( 'style_cinema' ),
			'illustration'   => $this->t( 'style_illus'  ),
			'minimal'        => $this->t( 'style_minimal'),
			'dark'           => $this->t( 'style_dark'   ),
		);
	}

	// ── i18n ──────────────────────────────────────────────────────────────

	private function t( string $key ): string {
		$locale   = $this->locale_code();
		$strings  = $locale === 'fa' ? $this->strings_fa() : $this->strings_en();
		return $strings[ $key ] ?? $key;
	}

	/** i18n strings — all strings available for JS via wp_localize_script */
	private function i18n_strings(): array {
		$locale  = $this->locale_code();
		$strings = $locale === 'fa' ? $this->strings_fa() : $this->strings_en();
		return array_map( 'esc_html', $strings );
	}

	private function strings_fa(): array {
		return array(
			// General
			'new_sequence'        => 'سکانس جدید',
			'close'               => 'بستن',
			'wizard_progress'     => 'پیشرفت ویزارد',
			'save_continue'       => 'ذخیره و ادامه',
			'back'                => 'بازگشت',
			'permission_denied'   => 'دسترسی ندارید.',
			'invalid_post'        => 'سکانس نامعتبر.',
			'invalid_attachment'  => 'فایل پیوست نامعتبر.',
			'pro_only'            => 'این قابلیت فقط برای کاربران Pro است.',

			// Step labels
			'step1_short'         => 'نام و قالب',
			'step2_short_pro'     => 'تصویر پایانی',
			'step2_short_free'    => 'آپلود فریم‌ها',
			'step3_short'         => 'مراحل محتوا',
			'step4_short'         => 'ساخت فریم‌ها',
			'step5_short'         => 'انتشار',

			// Step 1
			'step1_title'         => 'نام و قالب',
			'step1_desc'          => 'یک نام منحصربه‌فرد برای سکانس خود بگذارید و ساختار مناسب را انتخاب کنید.',
			'sequence_name'       => 'نام سکانس',
			'name_placeholder'    => 'مثال: هیرو محصول اصلی ۲۰۲۵',
			'name_hint'           => 'نام باید منحصربه‌فرد باشد. حداکثر ۲۰۰ کاراکتر.',
			'name_required'       => 'نام سکانس الزامی است.',
			'name_too_long'       => 'نام سکانس نباید بیشتر از ۲۰۰ کاراکتر باشد.',
			'name_duplicate'      => 'این نام قبلاً استفاده شده. لطفاً نام دیگری انتخاب کنید.',
			'name_checking'       => 'بررسی نام…',
			'name_available'      => '✓ این نام در دسترس است.',
			'name_taken'          => '✕ این نام قبلاً استفاده شده.',
			'choose_template'     => 'شروع از یک قالب',
			'template_hint'       => 'یک ساختار آماده یا خالی انتخاب کنید.',
			'blank_template'      => 'سکانس خالی',
			'blank_template_desc' => 'شروع از صفر — ۲۴ فریم',
			'frames'              => 'فریم',
			'invalid_template'    => 'قالب انتخابی معتبر نیست.',

			// Step 2
			'step2_title_pro'     => 'تصویر پایانی یا آپلود فریم',
			'step2_title_free'    => 'آپلود فریم‌ها',
			'step2_desc_pro'      => 'تصویر نهایی تأیید‌شده را آپلود کنید یا از هوش مصنوعی بخواهید آن را بسازد.',
			'step2_desc_free'     => 'فریم‌های سکانس را به‌ترتیب آپلود کنید. آخرین فریم، تصویر نهایی خواهد بود.',
			'upload_zone_label'   => 'ناحیه آپلود فریم‌ها',
			'drop_frames_here'    => 'فریم‌ها را اینجا بکشید و رها کنید',
			'upload_limits'       => 'هر فایل حداکثر %dMB — حداکثر %d فریم',
			'choose_frames'       => 'انتخاب فریم‌ها',
			'uploaded_frames'     => 'فریم‌های آپلود‌شده',
			'no_frames'           => 'حداقل یک فریم آپلود کنید.',
			'mode_upload'         => 'آپلود تصویر',
			'mode_ai_generate'    => 'ساخت با هوش مصنوعی',
			'upload_mode'         => 'روش آپلود',
			'upload_final_image'  => 'تصویر نهایی (PNG یا JPG) را اینجا بکشید',
			'upload_final_hint'   => 'فرمت: PNG یا JPG — حداکثر %dMB — ابعاد: ۴۰۰px تا ۷۶۸۰px',
			'choose_image'        => 'انتخاب تصویر',
			'remove_image'        => 'حذف تصویر',
			'ai_prompt_label'     => 'توضیح صحنه نهایی',
			'ai_prompt_placeholder'=> 'مثال: یک استودیو طراحی مدرن با نور طبیعی، میز چوبی، کتاب‌ها و گیاه سبز در کنار پنجره',
			'ai_prompt_hint'      => 'توضیح دهید می‌خواهید چه صحنه‌ای در تصویر نهایی باشد. متن‌ها را وارد نکنید، آن‌ها در مرحله بعد اضافه می‌شوند.',
			'ai_style'            => 'سبک تصویر',
			'ai_rate_notice'      => 'شما %d بار در روز می‌توانید تولید کنید. تعداد باقی‌مانده: %d',
			'ai_rate_exceeded'    => 'سقف روزانه تولید تصویر به‌پایان رسیده. فردا دوباره امتحان کنید.',
			'generate_image'      => 'ساخت تصویر',
			'generating'          => 'در حال ساخت تصویر…',
			'use_this_image'      => 'استفاده از این تصویر',
			'regenerate'          => 'دوباره بساز',
			'prompt_required'     => 'توضیح صحنه الزامی است.',
			'prompt_too_long'     => 'توضیح صحنه نباید بیشتر از ۱۰۰۰ کاراکتر باشد.',
			'no_final_image'      => 'تصویر نهایی انتخاب نشده.',

			// File validation
			'file_too_large'      => 'حجم فایل بیشتر از %dMB است.',
			'invalid_file_type'   => 'فرمت فایل قابل‌قبول نیست. فقط WebP، JPEG و PNG مجاز است.',
			'final_must_be_jpg_png' => 'تصویر نهایی باید JPEG یا PNG باشد.',
			'invalid_image'       => 'فایل انتخابی تصویر معتبری نیست.',
			'image_too_small'     => 'ابعاد تصویر کمتر از %dpx است.',
			'image_dim_too_large' => 'ابعاد تصویر بیشتر از %dpx است.',
			'no_file'             => 'هیچ فایلی ارسال نشده.',

			// Style options
			'style_photo'         => 'واقع‌گرایانه',
			'style_cinema'        => 'سینماتیک',
			'style_illus'         => 'ایلوستریشن',
			'style_minimal'       => 'مینیمال',
			'style_dark'          => 'تاریک و دراماتیک',

			// Step 3
			'step3_title'         => 'مراحل محتوا',
			'step3_desc'          => 'متن‌ها و المان‌های محتوا را روی تصویر پایانی تنظیم کنید.',
			'extracting_text'     => 'در حال استخراج متن‌های تصویر…',
			'viewport'            => 'نمایش',
			'overlay_elements'    => 'المان‌های روی‌نما',
			'element_properties'  => 'ویژگی‌های المان انتخابی',
			'add_heading'         => 'افزودن عنوان',
			'add_paragraph'       => 'افزودن پاراگراف',
			'add_button'          => 'افزودن دکمه',
			'heading'             => 'عنوان',
			'paragraph'           => 'پاراگراف',
			'text'                => 'متن',
			'font_size'           => 'اندازه فونت',
			'color'               => 'رنگ',
			'align'               => 'تراز',
			'left'                => 'چپ',
			'center'              => 'وسط',
			'right'               => 'راست',
			'delete_element'      => 'حذف المان',
			'invalid_overlay_data'=> 'داده‌های المان نامعتبر است.',

			// Step 4
			'step4_title'         => 'ساخت فریم‌ها',
			'step4_desc'          => 'فریم‌های میانی از آخر به اول ساخته می‌شوند. این فرآیند در پس‌زمینه اجرا می‌شود.',
			'total_frames'        => 'مجموع فریم‌ها',
			'final_frame'         => 'فریم نهایی',
			'to_generate'         => 'برای ساخت',
			'frame_n'             => 'فریم N',
			'generating_frames'   => 'در حال ساخت فریم‌ها',
			'start_generation'    => 'شروع ساخت',
			'generated_frames'    => 'فریم‌های ساخته‌شده',
			'generation_resumable'=> 'ساخت از نقطه قبلی قابل ادامه است.',
			'queued'              => 'در صف انتظار…',
			'gen_stage1'          => 'مرحله ۱: ساخت فریم شروع…',
			'gen_stage2'          => 'مرحله ۲: ساخت فریم‌های میانی…',
			'gen_stage3'          => 'مرحله ۳: ذخیره فریم‌ها…',
			'gen_done'            => '✓ فریم‌ها آماده شدند!',
			'gen_failed'          => '✕ خطا در ساخت فریم‌ها',
			'action_scheduler_missing' => 'Action Scheduler نصب نیست. لطفاً ابتدا آن را نصب کنید.',
			'not_enough_frames'   => 'حداقل ۲ فریم نیاز است.',

			// Step 5
			'step5_title'         => 'پیش‌نمایش و انتشار',
			'summary'             => 'خلاصه',
			'sequence_preview'    => 'پیش‌نمایش سکانس',
			'shortcode'           => 'شورت‌کد',
			'copy_shortcode'      => 'کپی شورت‌کد',
			'preflight'           => 'بررسی‌های قبل از انتشار',
			'preview'             => 'پیش‌نمایش',
			'publish'             => 'انتشار',
			'published_title'     => '🎉 سکانس منتشر شد!',
			'view_sequence'       => 'مشاهده سکانس',
			'all_sequences'       => 'همه سکانس‌ها',
			'viewport'            => 'نمایش',

			// Toast/misc
			'copied'              => 'کپی شد!',
			'copy_failed'         => 'کپی ناموفق. Ctrl+C را فشار دهید.',
			'error_generic'       => 'خطایی رخ داد. لطفاً دوباره امتحان کنید.',
			'saving'              => 'در حال ذخیره…',
			'saved'               => 'ذخیره شد.',
		);
	}

	private function strings_en(): array {
		return array(
			// General
			'new_sequence'        => 'New Sequence',
			'close'               => 'Close',
			'wizard_progress'     => 'Wizard progress',
			'save_continue'       => 'Save & Continue',
			'back'                => 'Back',
			'permission_denied'   => 'Permission denied.',
			'invalid_post'        => 'Invalid sequence.',
			'invalid_attachment'  => 'Invalid attachment.',
			'pro_only'            => 'This feature is for Pro users only.',

			// Step labels
			'step1_short'         => 'Name & Template',
			'step2_short_pro'     => 'Final Image',
			'step2_short_free'    => 'Upload Frames',
			'step3_short'         => 'Content Steps',
			'step4_short'         => 'Build Frames',
			'step5_short'         => 'Publish',

			// Step 1
			'step1_title'         => 'Name & Template',
			'step1_desc'          => 'Give your sequence a unique name and choose a starting structure.',
			'sequence_name'       => 'Sequence Name',
			'name_placeholder'    => 'e.g. Main Product Hero 2025',
			'name_hint'           => 'Name must be unique. Maximum 200 characters.',
			'name_required'       => 'Sequence name is required.',
			'name_too_long'       => 'Name cannot exceed 200 characters.',
			'name_duplicate'      => 'This name is already in use. Please choose a different name.',
			'name_checking'       => 'Checking name…',
			'name_available'      => '✓ Name is available.',
			'name_taken'          => '✕ This name is already taken.',
			'choose_template'     => 'Start from a Template',
			'template_hint'       => 'Select a pre-built structure or start blank.',
			'blank_template'      => 'Blank Sequence',
			'blank_template_desc' => 'Start from scratch — 24 frames',
			'frames'              => 'frames',
			'invalid_template'    => 'Selected template is not valid.',

			// Step 2
			'step2_title_pro'     => 'Final Image or Upload Frames',
			'step2_title_free'    => 'Upload Frames',
			'step2_desc_pro'      => 'Upload your approved final image or let AI create it for you.',
			'step2_desc_free'     => 'Upload frames in order. The last frame will be the final image.',
			'upload_zone_label'   => 'Frame upload zone',
			'drop_frames_here'    => 'Drag & drop frames here',
			'upload_limits'       => 'Max %dMB per file — Max %d frames',
			'choose_frames'       => 'Choose Frames',
			'uploaded_frames'     => 'Uploaded frames',
			'no_frames'           => 'Please upload at least one frame.',
			'mode_upload'         => 'Upload Image',
			'mode_ai_generate'    => 'Generate with AI',
			'upload_mode'         => 'Upload mode',
			'upload_final_image'  => 'Drop your final image (PNG or JPG) here',
			'upload_final_hint'   => 'Format: PNG or JPG — Max %dMB — Dimensions: 400px to 7680px',
			'choose_image'        => 'Choose Image',
			'remove_image'        => 'Remove image',
			'ai_prompt_label'     => 'Describe the final scene',
			'ai_prompt_placeholder'=> 'e.g. A modern design studio with natural light, wooden desk, books and a green plant by the window',
			'ai_prompt_hint'      => 'Describe what you want in the final image. Do not include text — text will be added in the next step.',
			'ai_style'            => 'Image style',
			'ai_rate_notice'      => 'You have %d generations per day. Remaining: %d',
			'ai_rate_exceeded'    => 'Daily AI generation limit reached. Try again tomorrow.',
			'generate_image'      => 'Generate Image',
			'generating'          => 'Generating image…',
			'use_this_image'      => 'Use this image',
			'regenerate'          => 'Regenerate',
			'prompt_required'     => 'Scene description is required.',
			'prompt_too_long'     => 'Scene description cannot exceed 1000 characters.',
			'no_final_image'      => 'No final image selected.',

			// File validation
			'file_too_large'      => 'File size exceeds %dMB.',
			'invalid_file_type'   => 'Invalid file type. Only WebP, JPEG, and PNG are allowed.',
			'final_must_be_jpg_png'=> 'Final image must be JPEG or PNG.',
			'invalid_image'       => 'Selected file is not a valid image.',
			'image_too_small'     => 'Image dimensions are smaller than %dpx.',
			'image_dim_too_large' => 'Image dimensions exceed %dpx.',
			'no_file'             => 'No file was uploaded.',

			// Style options
			'style_photo'         => 'Photorealistic',
			'style_cinema'        => 'Cinematic',
			'style_illus'         => 'Illustration',
			'style_minimal'       => 'Minimal',
			'style_dark'          => 'Dark & Dramatic',

			// Step 3
			'step3_title'         => 'Content Steps',
			'step3_desc'          => 'Position text and content elements over your final image.',
			'extracting_text'     => 'Extracting text from image…',
			'viewport'            => 'Viewport',
			'overlay_elements'    => 'Overlay elements',
			'element_properties'  => 'Selected element properties',
			'add_heading'         => 'Add Heading',
			'add_paragraph'       => 'Add Paragraph',
			'add_button'          => 'Add Button',
			'heading'             => 'Heading',
			'paragraph'           => 'Paragraph',
			'text'                => 'Text',
			'font_size'           => 'Font size',
			'color'               => 'Color',
			'align'               => 'Align',
			'left'                => 'Left',
			'center'              => 'Center',
			'right'               => 'Right',
			'delete_element'      => 'Delete element',
			'invalid_overlay_data'=> 'Invalid overlay data.',

			// Step 4
			'step4_title'         => 'Build Frames',
			'step4_desc'          => 'Intermediate frames are built from last to first in the background.',
			'total_frames'        => 'Total frames',
			'final_frame'         => 'Final frame',
			'to_generate'         => 'To generate',
			'frame_n'             => 'Frame N',
			'generating_frames'   => 'Generating frames',
			'start_generation'    => 'Start Generation',
			'generated_frames'    => 'Generated frames',
			'generation_resumable'=> 'Generation can be resumed from the last checkpoint.',
			'queued'              => 'Queued…',
			'gen_stage1'          => 'Stage 1: Generating start frame…',
			'gen_stage2'          => 'Stage 2: Interpolating frames…',
			'gen_stage3'          => 'Stage 3: Saving frames…',
			'gen_done'            => '✓ Frames ready!',
			'gen_failed'          => '✕ Frame generation failed',
			'action_scheduler_missing' => 'Action Scheduler is not installed. Please install it first.',
			'not_enough_frames'   => 'At least 2 frames are required.',

			// Step 5
			'step5_title'         => 'Preview & Publish',
			'summary'             => 'Summary',
			'sequence_preview'    => 'Sequence preview',
			'shortcode'           => 'Shortcode',
			'copy_shortcode'      => 'Copy shortcode',
			'preflight'           => 'Pre-publish Checks',
			'preview'             => 'Preview',
			'publish'             => 'Publish',
			'published_title'     => '🎉 Sequence Published!',
			'view_sequence'       => 'View Sequence',
			'all_sequences'       => 'All Sequences',

			// Toast/misc
			'copied'              => 'Copied!',
			'copy_failed'         => 'Copy failed. Press Ctrl+C.',
			'error_generic'       => 'An error occurred. Please try again.',
			'saving'              => 'Saving…',
			'saved'               => 'Saved.',
		);
	}
}

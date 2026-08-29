<?php
/**
 * Wizard Step 4 Override — v2 Final
 *
 * Fixes:
 *  1. "دستور هوش مصنوعی خالی است" — mode-aware, no AI prompt required for upload/frames_uploaded mode
 *  2. When mode is empty but frames already exist in meta → skip to done
 *  3. Bilingual status labels
 *  4. PHP 8.x compatibility (no mixed return type)
 *
 * @package StoryBoardLive
 */

declare( strict_types = 1 );

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

final class WizardStep4Override {

	/** @var SequenceWizardPage */
	private SequenceWizardPage $wizard;

	public function __construct( SequenceWizardPage $wizard ) {
		$this->wizard = $wizard;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'override_step4_hook' ), 20 );
	}

	public function override_step4_hook(): void {
		remove_action( 'wp_ajax_shseq_wiz_step4_start',  array( $this->wizard, 'ajax_step4_start'  ) );
		remove_action( 'wp_ajax_shseq_wiz_step4_status', array( $this->wizard, 'ajax_step4_status' ) );
		add_action( 'wp_ajax_shseq_wiz_step4_start',  array( $this, 'ajax_step4_start'  ) );
		add_action( 'wp_ajax_shseq_wiz_step4_status', array( $this, 'ajax_step4_status' ) );
	}

	// ── Bilingual ─────────────────────────────────────────────────────────

	private function is_fa(): bool {
		return is_rtl();
	}

	private function fa( string $fa, string $en ): string {
		return $this->is_fa() ? $fa : $en;
	}

	// ── Step 4 Start ──────────────────────────────────────────────────────

	public function ajax_step4_start(): void {
		check_ajax_referer( SequenceWizardPage::NONCE_S4, 'nonce' );

		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'سکانس نامعتبر است.', 'Invalid sequence.' ) ), 400 );
			return;
		}

		$mode = (string) get_post_meta( $post_id, SequenceWizardPage::META_MODE, true );

		// ── Priority 1: Frames already uploaded (any mode) ────────────────
		// If _shseq_frames is already populated, we can skip generation entirely
		$existing_frames = get_post_meta( $post_id, SequenceWizardPage::META_FRAMES, true );
		if ( ! empty( $existing_frames ) && is_array( $existing_frames ) && count( $existing_frames ) > 0 ) {
			update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'done' );
			update_post_meta( $post_id, SequenceWizardPage::META_STEP, 5 );
			wp_send_json_success( array(
				'status'  => 'done',
				'percent' => 100,
				'label'   => $this->fa( 'فریم‌ها آماده‌اند!', 'Frames ready!' ),
			) );
			return;
		}

		// ── Mode: frames_uploaded (bulk frame upload flow) ─────────────────
		if ( $mode === 'frames_uploaded' || $mode === 'frames' ) {
			update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'done' );
			update_post_meta( $post_id, SequenceWizardPage::META_STEP, 5 );
			wp_send_json_success( array(
				'status'  => 'done',
				'percent' => 100,
				'label'   => $this->fa( 'فریم‌ها آماده‌اند!', 'Frames ready!' ),
			) );
			return;
		}

		// ── Mode: upload (single final image) OR empty mode with end frame ──
		if ( $mode === 'upload' || $mode === '' || empty( $mode ) ) {
			$end_frame_id = (int) get_post_meta( $post_id, SequenceWizardPage::META_FINAL_IMG, true );

			if ( ! $end_frame_id ) {
				wp_send_json_error( array(
					'message' => $this->fa(
						'تصویر پایانی یافت نشد. لطفاً به Step 2 بازگردید و یک تصویر آپلود کنید.',
						'Final image not found. Please go back to Step 2 and upload an image.'
					),
				), 400 );
				return;
			}

			// Check if already running
			$current_status = (string) get_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, true );
			if ( in_array( $current_status, array( 'pending', 'running', 'stage1', 'stage2', 'stage3' ), true ) ) {
				wp_send_json_success( array(
					'status'  => $current_status,
					'percent' => $this->status_percent( $current_status ),
					'label'   => $this->status_label( $current_status ),
				) );
				return;
			}

			$this->schedule_gd_generation( $post_id, $end_frame_id );
			wp_send_json_success( array(
				'status'  => 'pending',
				'percent' => 5,
				'label'   => $this->fa( 'در صف…', 'Queued…' ),
			) );
			return;
		}

		// ── Mode: AI (Pro only) ────────────────────────────────────────────
		if ( $mode === 'ai' ) {
			if ( ! LicenseManager::is_pro() ) {
				wp_send_json_error( array(
					'message' => $this->fa( 'برای تولید هوش مصنوعی به پلن Pro نیاز دارید.', 'Pro plan required for AI generation.' ),
				), 403 );
				return;
			}

			$prompt = (string) get_post_meta( $post_id, SequenceWizardPage::META_AI_PROMPT, true );

			// No prompt → fall back to GD if we have an end frame
			if ( empty( trim( $prompt ) ) ) {
				$end_frame_id = (int) get_post_meta( $post_id, SequenceWizardPage::META_FINAL_IMG, true );
				if ( $end_frame_id ) {
					$this->schedule_gd_generation( $post_id, $end_frame_id );
					wp_send_json_success( array(
						'status'  => 'pending',
						'percent' => 5,
						'label'   => $this->fa( 'در صف (بدون پرامپت AI — استفاده از GD)…', 'Queued (no AI prompt — using GD)…' ),
					) );
					return;
				}
				wp_send_json_error( array(
					'message' => $this->fa(
						'دستور هوش مصنوعی خالی است. لطفاً به Step 2 بازگردید و صحنه را توصیف کنید.',
						'AI prompt is empty. Please go back to Step 2 and describe the scene.'
					),
				), 400 );
				return;
			}

			// Check existing running job
			$current_status = (string) get_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, true );
			if ( in_array( $current_status, array( 'pending', 'running', 'stage1', 'stage2', 'stage3' ), true ) ) {
				wp_send_json_success( array(
					'status'  => $current_status,
					'percent' => $this->status_percent( $current_status ),
					'label'   => $this->status_label( $current_status ),
				) );
				return;
			}

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'pending' );
				as_enqueue_async_action( 'shseq_generate_frames', array( 'post_id' => $post_id ), 'shseq' );
			} else {
				$end_frame_id = (int) get_post_meta( $post_id, SequenceWizardPage::META_FINAL_IMG, true );
				if ( $end_frame_id ) {
					$this->schedule_gd_generation( $post_id, $end_frame_id );
				} else {
					wp_send_json_error( array(
						'message' => $this->fa(
							'Action Scheduler در دسترس نیست. WooCommerce یا پلاگین Action Scheduler را نصب کنید.',
							'Action Scheduler not available. Please install WooCommerce or the Action Scheduler plugin.'
						),
					), 500 );
					return;
				}
			}

			wp_send_json_success( array(
				'status'  => 'pending',
				'percent' => 5,
				'label'   => $this->fa( 'در صف…', 'Queued…' ),
			) );
			return;
		}

		// ── Unknown mode → attempt GD with end frame ──────────────────────
		$end_frame_id = (int) get_post_meta( $post_id, SequenceWizardPage::META_FINAL_IMG, true );
		if ( $end_frame_id ) {
			$this->schedule_gd_generation( $post_id, $end_frame_id );
			wp_send_json_success( array(
				'status'  => 'pending',
				'percent' => 5,
				'label'   => $this->fa( 'در صف…', 'Queued…' ),
			) );
		} else {
			wp_send_json_error( array(
				'message' => $this->fa(
					'تصویری یافت نشد. لطفاً ابتدا Step 2 را تکمیل کنید.',
					'No image found. Please complete Step 2 first.'
				),
			), 400 );
		}
	}

	// ── Step 4 Status ─────────────────────────────────────────────────────

	public function ajax_step4_status(): void {
		check_ajax_referer( SequenceWizardPage::NONCE_S4, 'nonce' );

		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => $this->fa( 'سکانس نامعتبر است.', 'Invalid sequence.' ) ), 400 );
			return;
		}

		$status     = (string) ( get_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, true ) ?: 'idle' );
		$job_status = (string) ( get_post_meta( $post_id, '_shseq_generation_status', true ) ?: '' );
		if ( $job_status && $status === 'pending' ) {
			$status = $job_status;
		}

		$error       = (string) ( get_post_meta( $post_id, SequenceWizardPage::META_ERROR, true ) ?: '' );
		if ( ! $error ) {
			$error = (string) ( get_post_meta( $post_id, '_shseq_generation_error', true ) ?: '' );
		}
		$frame_count = FrameManager::count( $post_id );

		wp_send_json_success( array(
			'status'     => $status,
			'error'      => $error,
			'frameCount' => $frame_count,
			'label'      => $this->status_label( $status ),
			'percent'    => $this->status_percent( $status ),
		) );
	}

	// ── GD generation ─────────────────────────────────────────────────────

	private function schedule_gd_generation( int $post_id, int $end_frame_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'pending' );
			as_enqueue_async_action(
				'shseq_gd_generate_frames',
				array( 'post_id' => $post_id, 'end_frame_id' => $end_frame_id ),
				'shseq'
			);
			add_action( 'shseq_gd_generate_frames', array( $this, 'run_gd_generation' ), 10, 2 );
		} else {
			// Sync fallback
			$this->run_gd_generation( $post_id, $end_frame_id );
		}
	}

	public function run_gd_generation( int $post_id, int $end_frame_id ): void {
		update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'running' );

		try {
			$frame_count = max( 12, min( 36, (int) ( get_post_meta( $post_id, SequenceWizardPage::META_FRAME_COUNT, true ) ?: 24 ) ) );
			$end_path    = get_attached_file( $end_frame_id );

			if ( ! $end_path || ! file_exists( $end_path ) ) {
				throw new \RuntimeException( 'End frame file not found: ' . (string) $end_path );
			}

			$frame_ids = $this->generate_zoom_out_frames( $post_id, $end_frame_id, $end_path, $frame_count );

			if ( ! empty( $frame_ids ) ) {
				FrameManager::set_frames( $post_id, $frame_ids );
				update_post_meta( $post_id, SequenceWizardPage::META_STEP, 5 );
				update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'done' );
			} else {
				throw new \RuntimeException( 'Frame generation produced no frames.' );
			}
		} catch ( \Throwable $e ) {
			update_post_meta( $post_id, SequenceWizardPage::META_GEN_STATUS, 'failed' );
			update_post_meta( $post_id, SequenceWizardPage::META_ERROR, $e->getMessage() );
		}
	}

	private function generate_zoom_out_frames(
		int $post_id,
		int $end_frame_id,
		string $src_path,
		int $frame_count
	): array {
		if ( ! extension_loaded( 'gd' ) ) {
			return array( $end_frame_id );
		}

		$src_img = $this->load_gd_image( $src_path );
		if ( ! $src_img ) {
			return array( $end_frame_id );
		}

		$src_w  = imagesx( $src_img );
		$src_h  = imagesy( $src_img );
		$upload = wp_upload_dir();
		$subdir = $upload['path'] . '/shseq/' . $post_id . '/';
		wp_mkdir_p( $subdir );

		$frame_ids = array();

		for ( $i = 1; $i <= $frame_count; $i++ ) {
			$t       = ( $i - 1 ) / max( 1, $frame_count - 1 );
			$scale   = 1.0 + ( 1 - $t ) * ( 1 - $t ) * 0.35; // 1.35x → 1.0x
			$crop_w  = (int) round( $src_w / $scale );
			$crop_h  = (int) round( $src_h / $scale );
			$off_x   = (int) round( ( $src_w - $crop_w ) / 2 );
			$off_y   = (int) round( ( $src_h - $crop_h ) / 2 );

			$frame_img = imagecreatetruecolor( $src_w, $src_h );
			imagecopyresampled( $frame_img, $src_img, 0, 0, $off_x, $off_y, $src_w, $src_h, $crop_w, $crop_h );

			$filename = $subdir . 'frame-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ) . '.webp';
			if ( function_exists( 'imagewebp' ) ) {
				imagewebp( $frame_img, $filename, 85 );
			} else {
				$filename = str_replace( '.webp', '.jpg', $filename );
				imagejpeg( $frame_img, $filename, 85 );
			}
			imagedestroy( $frame_img );

			$att_id = $this->import_frame_to_media( $filename, $post_id, $i );
			if ( $att_id && ! is_wp_error( $att_id ) ) {
				$frame_ids[] = $att_id;
			}
		}

		imagedestroy( $src_img );

		if ( ! in_array( $end_frame_id, $frame_ids, true ) ) {
			$frame_ids[] = $end_frame_id;
		}

		return $frame_ids;
	}

	private function load_gd_image( string $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'jpg':
			case 'jpeg':
				return imagecreatefromjpeg( $path );
			case 'png':
				return imagecreatefrompng( $path );
			case 'webp':
				return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : null;
			default:
				return null;
		}
	}

	private function import_frame_to_media( string $filename, int $post_id, int $frame_num ) {
		if ( ! file_exists( $filename ) ) {
			return new \WP_Error( 'file_not_found', 'Frame file not found: ' . $filename );
		}
		$attachment = array(
			'post_mime_type' => str_ends_with( $filename, '.webp' ) ? 'image/webp' : 'image/jpeg',
			'post_title'     => 'Frame ' . $frame_num,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
		);
		$att_id = wp_insert_attachment( $attachment, $filename, $post_id );
		if ( ! is_wp_error( $att_id ) && $att_id ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $att_id, $filename );
			wp_update_attachment_metadata( $att_id, $metadata );
		}
		return $att_id;
	}

	// ── Status helpers ────────────────────────────────────────────────────

	private function status_label( string $status ): string {
		$map = array(
			'idle'    => $this->fa( 'آماده', 'Ready' ),
			'pending' => $this->fa( 'در صف…', 'Queued…' ),
			'running' => $this->fa( 'در حال ساخت فریم‌ها…', 'Building frames…' ),
			'stage1'  => $this->fa( 'ساخت فریم اول…', 'Generating start frame…' ),
			'stage2'  => $this->fa( 'میان‌یابی فریم‌ها…', 'Interpolating frames…' ),
			'stage3'  => $this->fa( 'ذخیره فریم‌ها…', 'Saving frames…' ),
			'done'    => $this->fa( 'انجام شد — فریم‌ها آماده‌اند!', 'Done — frames ready!' ),
			'failed'  => $this->fa( 'شکست خورد', 'Failed' ),
		);
		return $map[ $status ] ?? $status;
	}

	private function status_percent( string $status ): int {
		$map = array(
			'idle'    => 0,
			'pending' => 5,
			'running' => 40,
			'stage1'  => 20,
			'stage2'  => 60,
			'stage3'  => 90,
			'done'    => 100,
			'failed'  => 0,
		);
		return $map[ $status ] ?? 0;
	}
}

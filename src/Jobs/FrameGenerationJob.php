<?php
/**
 * Frame Generation Job — Action Scheduler async handler.
 *
 * Flow:
 *   1. Admin saves a Sequence with an AI prompt and an End Frame (Golden Master).
 *   2. FrameGenerationJob::schedule() queues an async Action Scheduler job.
 *   3. The hook shseq_generate_frames fires in a background request.
 *   4. Stage 1: OpenAIProvider generates the Start Frame from the prompt.
 *   5. Stage 2: ReplicateProvider interpolates Start + End → 24/36 frames.
 *   6. Stage 3: FrameManager::set_frames() stores the ordered attachment IDs.
 *   7. Status meta (_shseq_generation_status) is updated throughout.
 *
 * Action Scheduler must be present (bundled in WooCommerce or installed
 * standalone). The plugin checks for it in Activator and shows a notice
 * if it's missing.
 *
 * Status values stored in _shseq_generation_status:
 *   idle        — no job scheduled
 *   pending     — job queued, not yet started
 *   stage1      — generating Start Frame via OpenAI
 *   stage2      — interpolating frames via Replicate
 *   stage3      — saving frames to media library
 *   done        — all frames saved, ready to publish
 *   failed      — error, message stored in _shseq_generation_error
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Jobs;

use ShahreHonar\SequenceEngine\AI\OpenAIProvider;
use ShahreHonar\SequenceEngine\AI\ReplicateProvider;
use ShahreHonar\SequenceEngine\Admin\GoldenMasterMetaBox;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

/**
 * Registers and handles the async frame generation job.
 */
final class FrameGenerationJob {

	const HOOK           = 'shseq_generate_frames';
	const META_STATUS    = '_shseq_generation_status';
	const META_ERROR     = '_shseq_generation_error';
	const META_JOB_ID    = '_shseq_generation_job_id';

	/** @var OpenAIProvider */
	private $openai;

	/** @var ReplicateProvider */
	private $replicate;

	/**
	 * @param OpenAIProvider    $openai    OpenAI provider.
	 * @param ReplicateProvider $replicate Replicate provider.
	 */
	public function __construct( OpenAIProvider $openai, ReplicateProvider $replicate ) {
		$this->openai    = $openai;
		$this->replicate = $replicate;
	}

	/** Register hooks. */
	public function register_hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );

		// AJAX handler for admin status polling.
		add_action( 'wp_ajax_shseq_generation_status', array( $this, 'ajax_status' ) );

		// Trigger generation when admin submits the Sequence editor.
		add_action( 'save_post_shseq_sequence', array( $this, 'maybe_schedule' ), 20, 2 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Scheduling
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Schedule a generation job after post save if conditions are met.
	 *
	 * Conditions:
	 *   - Pro plan active.
	 *   - A non-empty AI prompt is stored.
	 *   - An End Frame (Golden Master desktop) is confirmed.
	 *   - No job is already running or pending.
	 *   - The "Generate" nonce is present (user clicked the button).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function maybe_schedule( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only Pro can use AI generation.
		if ( ! LicenseManager::can_use_ai() ) {
			return;
		}

		// Require the explicit "Generate" nonce so saving without clicking
		// Generate never triggers a job.
		if ( ! isset( $_POST['_shseq_generate_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_shseq_generate_nonce'] ) ),
				'shseq_generate_frames_' . $post_id
			)
		) {
			return;
		}

		// Check prerequisites.
		$prompt = get_post_meta( $post_id, '_shseq_ai_prompt', true );
		if ( empty( trim( (string) $prompt ) ) ) {
			return;
		}

		$masters = GoldenMasterMetaBox::get_masters( $post_id );
		if ( empty( $masters['desktop'] ) ) {
			return;
		}

		// Don't queue a second job if one is already running.
		$status = get_post_meta( $post_id, self::META_STATUS, true );
		if ( in_array( $status, array( 'pending', 'stage1', 'stage2', 'stage3' ), true ) ) {
			return;
		}

		$this->schedule( $post_id );
	}

	/**
	 * Queue the generation job via Action Scheduler.
	 *
	 * @param int $post_id Sequence post ID.
	 * @return int|null Action Scheduler action ID, or null if AS not available.
	 */
	public function schedule( $post_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler not installed — store error and bail.
			update_post_meta( $post_id, self::META_STATUS, 'failed' );
			update_post_meta( $post_id, self::META_ERROR, __( 'Action Scheduler is not available. Please install WooCommerce or the Action Scheduler plugin.', 'sh-sequence-engine' ) );
			return null;
		}

		update_post_meta( $post_id, self::META_STATUS, 'pending' );
		delete_post_meta( $post_id, self::META_ERROR );

		$action_id = as_enqueue_async_action(
			self::HOOK,
			array( 'post_id' => (int) $post_id ),
			'shseq' // group
		);

		update_post_meta( $post_id, self::META_JOB_ID, $action_id );

		return $action_id;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Job execution
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Run the full generation pipeline.
	 * Called by Action Scheduler in a background request.
	 *
	 * @param int $post_id Sequence post ID.
	 */
	public function run( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! get_post( $post_id ) ) {
			return; // Post was deleted while queued.
		}

		try {
			$this->execute( $post_id );
		} catch ( \Throwable $e ) {
			$this->set_failed( $post_id, $e->getMessage() );
		}
	}

	/**
	 * Internal pipeline execution.
	 *
	 * @param int $post_id Sequence post ID.
	 * @throws \RuntimeException On any unrecoverable failure.
	 */
	private function execute( $post_id ) {

		// ── Stage 1: Generate Start Frame via OpenAI ──────────────────────
		$this->set_status( $post_id, 'stage1' );

		$prompt = get_post_meta( $post_id, '_shseq_ai_prompt', true );
		if ( empty( trim( (string) $prompt ) ) ) {
			throw new \RuntimeException( __( 'AI prompt is empty.', 'sh-sequence-engine' ) );
		}

		$start_id = $this->openai->generate_start_frame( (string) $prompt, $post_id );
		if ( is_wp_error( $start_id ) ) {
			throw new \RuntimeException( $start_id->get_error_message() );
		}

		// ── Stage 2: Interpolate Start → End via Replicate ───────────────
		$this->set_status( $post_id, 'stage2' );

		$masters = GoldenMasterMetaBox::get_masters( $post_id );
		$end_id  = isset( $masters['desktop'] ) ? (int) $masters['desktop'] : 0;

		if ( 0 === $end_id ) {
			throw new \RuntimeException( __( 'End Frame (Golden Master) not found.', 'sh-sequence-engine' ) );
		}

		$frame_count = LicenseManager::max_frames();

		$frame_ids = $this->replicate->interpolate( $start_id, $end_id, $post_id, $frame_count );
		if ( is_wp_error( $frame_ids ) ) {
			throw new \RuntimeException( $frame_ids->get_error_message() );
		}

		// ── Stage 3: Store frames ─────────────────────────────────────────
		$this->set_status( $post_id, 'stage3' );

		// Ensure End Frame is always the final frame in the sequence.
		$all_frames = $frame_ids;
		if ( ! in_array( $end_id, $all_frames, true ) ) {
			$all_frames[] = $end_id;
		}

		FrameManager::set_frames( $post_id, $all_frames );

		$this->set_status( $post_id, 'done' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// AJAX status endpoint
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Return the current generation status for a sequence.
	 * Used by the admin meta box progress bar via polling.
	 */
	public function ajax_status() {
		check_ajax_referer( 'shseq_generation_status', '_nonce' );

		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sh-sequence-engine' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'sh-sequence-engine' ) ), 400 );
			return;
		}

		$status       = get_post_meta( $post_id, self::META_STATUS, true ) ?: 'idle';
		$error        = get_post_meta( $post_id, self::META_ERROR,  true ) ?: '';
		$frame_count  = FrameManager::count( $post_id );

		wp_send_json_success(
			array(
				'status'      => $status,
				'error'       => $error,
				'frameCount'  => $frame_count,
				'label'       => $this->status_label( $status ),
				'percent'     => $this->status_percent( $status ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Update the generation status meta.
	 *
	 * @param int    $post_id Sequence post ID.
	 * @param string $status  Status key.
	 */
	private function set_status( $post_id, $status ) {
		update_post_meta( $post_id, self::META_STATUS, $status );
	}

	/**
	 * Mark the job as failed with an error message.
	 *
	 * @param int    $post_id Sequence post ID.
	 * @param string $message Error message.
	 */
	private function set_failed( $post_id, $message ) {
		update_post_meta( $post_id, self::META_STATUS, 'failed' );
		update_post_meta( $post_id, self::META_ERROR, $message );
	}

	/**
	 * Human-readable label for a status key.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'idle'    => __( 'Ready', 'sh-sequence-engine' ),
			'pending' => __( 'Queued…', 'sh-sequence-engine' ),
			'stage1'  => __( 'Generating start frame…', 'sh-sequence-engine' ),
			'stage2'  => __( 'Interpolating frames…', 'sh-sequence-engine' ),
			'stage3'  => __( 'Saving frames…', 'sh-sequence-engine' ),
			'done'    => __( 'Done — frames ready!', 'sh-sequence-engine' ),
			'failed'  => __( 'Failed', 'sh-sequence-engine' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Progress percent for the admin progress bar.
	 *
	 * @param string $status Status key.
	 * @return int 0–100.
	 */
	private function status_percent( $status ) {
		$map = array(
			'idle'    => 0,
			'pending' => 5,
			'stage1'  => 20,
			'stage2'  => 60,
			'stage3'  => 90,
			'done'    => 100,
			'failed'  => 0,
		);
		return $map[ $status ] ?? 0;
	}
}

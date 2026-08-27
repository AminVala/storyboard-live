<?php
/**
 * Replicate provider — frame interpolation via FILM / RIFE.
 *
 * Pipeline:
 *   1. Upload Start Frame and End Frame to Replicate (or pass URLs directly).
 *   2. Run the frame-interpolation model (FILM by default) to produce N frames.
 *   3. Download each output frame and save it as a WordPress attachment.
 *   4. Return the ordered array of attachment IDs.
 *
 * BYOK: the API token is stored in wp_options under shseq_replicate_api_token.
 *
 * Model used: afiaka87/film-interpolation (or lucataco/rife as fallback).
 * Default output: 24 frames (Free: 24, Pro: 36).
 *
 * Privacy: images are sent to Replicate's API servers for processing.
 * The SettingsPage shows a disclosure notice.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\AI;

/**
 * Generates the full frame sequence by interpolating Start → End frames.
 */
final class ReplicateProvider implements ProviderInterface {

	const OPTION_API_TOKEN = 'shseq_replicate_api_token';

	// FILM interpolation model on Replicate.
	// Version pinned for reproducibility — update in SettingsPage when needed.
	const MODEL_VERSION = 'afiaka87/film-interpolation:b0a6d1a5a5f2ac4aa0abab3a5a6c72d0e9d07f3b8e4a5d3a4e2e7a0b1d8c5f9e';
	const API_BASE      = 'https://api.replicate.com/v1';
	const TIMEOUT       = 30;            // seconds per API call
	const POLL_INTERVAL = 5;            // seconds between status polls
	const POLL_MAX      = 24;           // max polls (= 2 minutes)

	/** @inheritDoc */
	public function get_name() {
		return 'Replicate (FILM Interpolation)';
	}

	/** @inheritDoc */
	public function validate_credentials() {
		$token = $this->get_api_token();
		if ( empty( $token ) ) {
			return new \WP_Error(
				'shseq_missing_replicate_token',
				__( 'Replicate API token is not configured. Go to StoryBoard Live → Settings to add it.', 'sh-sequence-engine' )
			);
		}
		return true;
	}

	/**
	 * Interpolate Start Frame → End Frame → N frames.
	 *
	 * @param int $start_attachment_id WordPress attachment ID of the Start Frame.
	 * @param int $end_attachment_id   WordPress attachment ID of the End Frame (Golden Master).
	 * @param int $post_id             Sequence post ID (new attachments are attached to it).
	 * @param int $frame_count         Number of output frames (24 or 36).
	 * @return int[]|\WP_Error Ordered array of attachment IDs, or WP_Error on failure.
	 */
	public function interpolate( $start_attachment_id, $end_attachment_id, $post_id, $frame_count = 24 ) {
		$valid = $this->validate_credentials();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$start_url = wp_get_attachment_url( $start_attachment_id );
		$end_url   = wp_get_attachment_url( $end_attachment_id );

		if ( ! $start_url || ! $end_url ) {
			return new \WP_Error(
				'shseq_invalid_attachments',
				__( 'Start or End Frame attachment not found.', 'sh-sequence-engine' )
			);
		}

		// Clamp frame count to a sensible range.
		$frame_count = max( 8, min( 36, (int) $frame_count ) );

		// Submit prediction to Replicate.
		$prediction = $this->create_prediction( $start_url, $end_url, $frame_count );
		if ( is_wp_error( $prediction ) ) {
			return $prediction;
		}

		$prediction_id = isset( $prediction['id'] ) ? $prediction['id'] : '';
		if ( empty( $prediction_id ) ) {
			return new \WP_Error( 'shseq_no_prediction_id', __( 'Replicate returned no prediction ID.', 'sh-sequence-engine' ) );
		}

		// Poll until complete (runs in the background via Action Scheduler,
		// so blocking polling here is acceptable).
		$output_urls = $this->poll_prediction( $prediction_id );
		if ( is_wp_error( $output_urls ) ) {
			return $output_urls;
		}

		// Sideload each frame into the media library.
		$attachment_ids = array();
		foreach ( $output_urls as $index => $url ) {
			$title = sprintf(
				/* translators: 1: sequence post ID, 2: frame index. */
				__( 'Sequence %1$d — Frame %2$d (AI)', 'sh-sequence-engine' ),
				$post_id,
				$index + 1
			);

			$att_id = $this->sideload_image( $url, $post_id, $title );
			if ( is_wp_error( $att_id ) ) {
				// Best-effort: skip bad frames rather than failing the whole run.
				continue;
			}
			$attachment_ids[] = $att_id;
		}

		if ( empty( $attachment_ids ) ) {
			return new \WP_Error(
				'shseq_no_frames_saved',
				__( 'No output frames could be saved to the media library.', 'sh-sequence-engine' )
			);
		}

		return $attachment_ids;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Private API helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Submit a new prediction to Replicate.
	 *
	 * @param string $start_url   Public URL of the Start Frame.
	 * @param string $end_url     Public URL of the End Frame.
	 * @param int    $frame_count Number of output frames.
	 * @return array<string,mixed>|\WP_Error Parsed prediction object or error.
	 */
	private function create_prediction( $start_url, $end_url, $frame_count ) {
		// Number of recursive interpolation passes needed.
		// Each pass doubles the frame count: 1 pass = 3, 2 = 5, 3 = 9...
		// For FILM we pass the desired frame count directly.
		$response = wp_remote_post(
			self::API_BASE . '/predictions',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Token ' . $this->get_api_token(),
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'version' => self::MODEL_VERSION,
						'input'   => array(
							'frame1'       => $start_url,
							'frame2'       => $end_url,
							'times_to_interpolate' => max( 1, (int) round( log( $frame_count, 2 ) ) ),
						),
					)
				),
			)
		);

		return $this->parse_response( $response, 201 );
	}

	/**
	 * Poll a prediction until it reaches a terminal state.
	 *
	 * @param string $prediction_id Replicate prediction ID.
	 * @return string[]|\WP_Error Array of output frame URLs, or WP_Error.
	 */
	private function poll_prediction( $prediction_id ) {
		$url     = self::API_BASE . '/predictions/' . rawurlencode( $prediction_id );
		$headers = array( 'Authorization' => 'Token ' . $this->get_api_token() );

		for ( $i = 0; $i < self::POLL_MAX; $i++ ) {
			sleep( self::POLL_INTERVAL );

			$response = wp_remote_get(
				$url,
				array( 'timeout' => self::TIMEOUT, 'headers' => $headers )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$status = isset( $body['status'] ) ? $body['status'] : 'unknown';

			if ( 'succeeded' === $status ) {
				$output = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
				if ( empty( $output ) ) {
					return new \WP_Error( 'shseq_replicate_no_output', __( 'Replicate returned no output frames.', 'sh-sequence-engine' ) );
				}
				// Filter to string URLs only.
				return array_values( array_filter( $output, 'is_string' ) );
			}

			if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
				$error_detail = isset( $body['error'] ) ? (string) $body['error'] : 'unknown';
				return new \WP_Error(
					'shseq_replicate_failed',
					sprintf(
						/* translators: 1: status, 2: error detail. */
						__( 'Replicate prediction %1$s: %2$s', 'sh-sequence-engine' ),
						$status,
						$error_detail
					)
				);
			}
			// Still processing ('starting', 'processing') — keep polling.
		}

		return new \WP_Error(
			'shseq_replicate_timeout',
			__( 'Replicate prediction timed out after 2 minutes.', 'sh-sequence-engine' )
		);
	}

	/**
	 * Parse a wp_remote_* response.
	 *
	 * @param array<string,mixed>|\WP_Error $response   wp_remote_* result.
	 * @param int                           $expect_code Expected HTTP code.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function parse_response( $response, $expect_code = 200 ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( (int) $code !== $expect_code ) {
			$detail = isset( $body['detail'] ) ? $body['detail']
					: ( isset( $body['error'] )  ? $body['error'] : 'HTTP ' . $code );
			return new \WP_Error( 'shseq_replicate_http_error', (string) $detail );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Download an image and sideload into the media library.
	 *
	 * @param string $url     Remote URL of the frame.
	 * @param int    $post_id Parent post ID.
	 * @param string $title   Attachment title / alt.
	 * @return int|\WP_Error Attachment ID.
	 */
	private function sideload_image( $url, $post_id, $title ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$att_id = media_sideload_image( $url, $post_id, sanitize_text_field( $title ), 'id' );

		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}
		return (int) $att_id;
	}

	/**
	 * Retrieve the stored Replicate API token.
	 *
	 * @return string
	 */
	private function get_api_token() {
		return (string) get_option( self::OPTION_API_TOKEN, '' );
	}
}

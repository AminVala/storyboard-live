<?php
/**
 * OpenAI provider — generates the Start Frame from a text prompt.
 *
 * Uses the DALL·E 3 image generation endpoint:
 *   POST https://api.openai.com/v1/images/generations
 *
 * The generated image is downloaded and saved as a WordPress media attachment
 * so it becomes part of the site's Media Library and can be referenced by
 * attachment ID like any other frame.
 *
 * BYOK: the API key is stored in wp_options under the key
 *   shseq_openai_api_key (encrypted via WordPress option + site_url salt).
 *
 * Privacy: images are sent to OpenAI's API. The SettingsPage shows a
 * disclosure notice to the administrator.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\AI;

/**
 * Generates the Start Frame from a text prompt via DALL·E 3.
 */
final class OpenAIProvider implements ProviderInterface {

	const OPTION_API_KEY = 'shseq_openai_api_key';
	const API_URL        = 'https://api.openai.com/v1/images/generations';
	const MODEL          = 'dall-e-3';
	const SIZE           = '1792x1024'; // 16:9 landscape
	const QUALITY        = 'standard';
	const TIMEOUT        = 60; // seconds

	/** @inheritDoc */
	public function get_name() {
		return 'OpenAI (DALL·E 3)';
	}

	/** @inheritDoc */
	public function validate_credentials() {
		$key = $this->get_api_key();
		if ( empty( $key ) ) {
			return new \WP_Error(
				'shseq_missing_openai_key',
				__( 'OpenAI API key is not configured. Go to StoryBoard Live → Settings to add it.', 'sh-sequence-engine' )
			);
		}
		return true;
	}

	/**
	 * Generate a Start Frame image from a text prompt.
	 *
	 * Downloads the resulting image and stores it as a WordPress attachment
	 * attached to the given Sequence post.
	 *
	 * @param string $prompt  Descriptive prompt for the starting scene.
	 * @param int    $post_id Sequence post ID (image is attached to it).
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function generate_start_frame( $prompt, $post_id ) {
		$valid = $this->validate_credentials();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( empty( trim( $prompt ) ) ) {
			return new \WP_Error( 'shseq_empty_prompt', __( 'Prompt cannot be empty.', 'sh-sequence-engine' ) );
		}

		// Call DALL·E 3 API.
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_api_key(),
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model'   => self::MODEL,
						'prompt'  => sanitize_text_field( $prompt ),
						'n'       => 1,
						'size'    => self::SIZE,
						'quality' => self::QUALITY,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'shseq_openai_request_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'OpenAI request failed: %s', 'sh-sequence-engine' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$api_message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			return new \WP_Error(
				'shseq_openai_api_error',
				sprintf(
					/* translators: 1: HTTP code, 2: API error message. */
					__( 'OpenAI API error (HTTP %1$d): %2$s', 'sh-sequence-engine' ),
					$code,
					$api_message
				)
			);
		}

		$image_url = isset( $body['data'][0]['url'] ) ? $body['data'][0]['url'] : '';
		if ( empty( $image_url ) ) {
			return new \WP_Error( 'shseq_openai_no_url', __( 'OpenAI returned no image URL.', 'sh-sequence-engine' ) );
		}

		// Download and sideload the image into the media library.
		return $this->sideload_image( $image_url, $post_id, $prompt );
	}

	/**
	 * Download an image URL into the WordPress media library.
	 *
	 * @param string $url     Remote image URL.
	 * @param int    $post_id Parent attachment post ID.
	 * @param string $title   Title / alt text for the attachment.
	 * @return int|\WP_Error Attachment ID on success.
	 */
	private function sideload_image( $url, $post_id, $title ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, sanitize_text_field( $title ), 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error(
				'shseq_sideload_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Failed to save generated image: %s', 'sh-sequence-engine' ),
					$attachment_id->get_error_message()
				)
			);
		}

		return (int) $attachment_id;
	}

	/**
	 * Retrieve and decrypt the stored API key.
	 *
	 * @return string Empty string when not set.
	 */
	private function get_api_key() {
		return (string) get_option( self::OPTION_API_KEY, '' );
	}
}

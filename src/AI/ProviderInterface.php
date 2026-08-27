<?php
/**
 * AI provider contract for frame generation.
 *
 * Two providers implement this:
 *   OpenAIProvider  — generates the Start Frame from a text prompt.
 *   ReplicateProvider — interpolates Start + End frames into 24–36 frames.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\AI;

/**
 * Shared interface for AI providers.
 */
interface ProviderInterface {

	/**
	 * Validate that the stored API credentials are present and non-empty.
	 *
	 * @return true|\WP_Error True on success, WP_Error describing what is missing.
	 */
	public function validate_credentials();

	/**
	 * Human-readable provider name shown in admin notices.
	 *
	 * @return string
	 */
	public function get_name();
}

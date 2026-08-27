<?php
/**
 * License / plan manager.
 *
 * Determines whether a site is running the Free or Pro plan. In Sprint 1,
 * the check is a simple option flag toggled in SettingsPage. In Sprint 3
 * this will be replaced by a verified license-key check against the
 * licensing API.
 *
 * Free plan limits:
 *   - 1 Hero (Sequence) per site.
 *   - 3 Content Steps per Hero.
 *   - 24 frames per Hero.
 *   - Manual frame upload only (no AI generation).
 *
 * Pro plan limits:
 *   - Up to 15 Heroes per site.
 *   - 10 Content Steps per Hero.
 *   - 36 frames per Hero.
 *   - AI frame generation (BYOK via Replicate/OpenAI).
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\License;

/**
 * Single source of truth for plan limits and feature flags.
 */
final class LicenseManager {

	// -----------------------------------------------------------------------
	// Option key stored in wp_options.
	// -----------------------------------------------------------------------

	const OPTION_KEY_IS_PRO = 'shseq_is_pro';

	// -----------------------------------------------------------------------
	// Hard limits.
	// -----------------------------------------------------------------------

	const FREE_MAX_HEROES  = 1;
	const PRO_MAX_HEROES   = 15;

	const FREE_MAX_STEPS   = 3;
	const PRO_MAX_STEPS    = 10;

	const FREE_MAX_FRAMES  = 24;
	const PRO_MAX_FRAMES   = 36;

	// -----------------------------------------------------------------------
	// Plan detection.
	// -----------------------------------------------------------------------

	/**
	 * Whether the current site has an active Pro license.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		// Sprint 3: replace option flag with verified license-key check.
		return (bool) get_option( self::OPTION_KEY_IS_PRO, false );
	}

	// -----------------------------------------------------------------------
	// Limit accessors.
	// -----------------------------------------------------------------------

	/**
	 * Maximum number of Sequence posts allowed site-wide.
	 *
	 * @return int
	 */
	public static function max_heroes() {
		return self::is_pro() ? self::PRO_MAX_HEROES : self::FREE_MAX_HEROES;
	}

	/**
	 * Maximum Content Steps per Sequence.
	 *
	 * @return int
	 */
	public static function max_steps() {
		return self::is_pro() ? self::PRO_MAX_STEPS : self::FREE_MAX_STEPS;
	}

	/**
	 * Maximum frame count per Sequence.
	 *
	 * @return int
	 */
	public static function max_frames() {
		return self::is_pro() ? self::PRO_MAX_FRAMES : self::FREE_MAX_FRAMES;
	}

	// -----------------------------------------------------------------------
	// Feature flags.
	// -----------------------------------------------------------------------

	/**
	 * Whether AI frame generation is available.
	 *
	 * @return bool
	 */
	public static function can_use_ai() {
		return self::is_pro();
	}

	/**
	 * Whether WooCommerce product hero integration is available.
	 *
	 * @return bool
	 */
	public static function can_use_woocommerce() {
		return self::is_pro();
	}

	// -----------------------------------------------------------------------
	// Quota checks.
	// -----------------------------------------------------------------------

	/**
	 * Count published/draft Sequences on the site.
	 *
	 * @return int
	 */
	public static function hero_count() {
		$counts = wp_count_posts( 'shseq_sequence' );
		$total  = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $status ) {
			if ( isset( $counts->$status ) ) {
				$total += (int) $counts->$status;
			}
		}
		return $total;
	}

	/**
	 * Whether the site can create another Sequence under the current plan.
	 *
	 * @return bool
	 */
	public static function can_create_hero() {
		return self::hero_count() < self::max_heroes();
	}

	/**
	 * Human-readable upgrade notice for the admin.
	 *
	 * @return string
	 */
	public static function upgrade_notice() {
		return sprintf(
			/* translators: 1: current hero count, 2: plan limit. */
			__( 'You have reached the %1$d-hero limit on the Free plan. Upgrade to Pro for up to %2$d heroes.', 'sh-sequence-engine' ),
			self::FREE_MAX_HEROES,
			self::PRO_MAX_HEROES
		);
	}
}

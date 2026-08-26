<?php
/**
 * Plugin dashboard page — UI Redesign v2.0
 *
 * Changes vs v1:
 * - A1: Single entry point — onboarding card replaces three competing CTAs
 * - A2: Shortcode callout in plain language, no milestone jargon
 * - A3: Stats now show context (label + colour dot + description)
 * - A7: Recent list includes status badge, date, quick-filter search
 * - A9: Environment banner is impossible to miss in Production
 * - A10: Empty/onboarding state is a first-class UI mode
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Renders the task-oriented dashboard with onboarding awareness.
 */
final class DashboardPage {

	/**
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		$counts        = wp_count_posts( SequencePostType::POST_TYPE );
		$draft_count   = isset( $counts->draft ) ? (int) $counts->draft : 0;
		$live_count    = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$total_count   = 0;

		foreach ( array( 'draft', 'publish', 'private', 'pending', 'future' ) as $s ) {
			if ( isset( $counts->{$s} ) ) {
				$total_count += (int) $counts->{$s};
			}
		}

		$is_empty    = 0 === $total_count;
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		$recent = get_posts( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => array( 'draft', 'publish', 'private', 'pending' ),
			'posts_per_page' => 8,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		?>
		<div class="wrap shseq-admin">

			<?php /* A9: Environment banner — large & colored */
			$this->render_env_banner( $environment ); ?>

			<?php /* Page header — title only, no duplicate CTAs */ ?>
			<header class="shseq-page-header">
				<div>
					<h1 class="shseq-page-header__title">
						<?php echo esc_html__( 'StoryBoard Live', 'sh-sequence-engine' ); ?>
					</h1>
					<p class="shseq-page-header__subtitle">
						<?php echo esc_html__( 'Scroll-driven visual stories, powered by a single confirmed image.', 'sh-sequence-engine' ); ?>
					</p>
				</div>
				<span class="shseq-version-chip">
					<?php
					printf(
						/* translators: %s: Plugin version number. */
						esc_html__( 'v%s', 'sh-sequence-engine' ),
						esc_html( SHSEQ_VERSION )
					);
					?>
				</span>
			</header>

			<?php if ( $is_empty ) : ?>
				<?php /* A1 + A10: Single onboarding card when the site has 0 sequences */ ?>
				<?php $this->render_onboarding_card(); ?>
			<?php else : ?>

				<?php /* A3: Stats with context */ ?>
				<section class="shseq-stats" aria-label="<?php echo esc_attr__( 'Sequence stats', 'sh-sequence-engine' ); ?>">
					<?php $this->render_stat_card( 'all',       $total_count, __( 'All sequences', 'sh-sequence-engine' ), __( 'Total across all statuses', 'sh-sequence-engine' ) ); ?>
					<?php $this->render_stat_card( 'drafts',    $draft_count, __( 'Drafts',         'sh-sequence-engine' ), __( 'Work in progress, not visible to visitors', 'sh-sequence-engine' ) ); ?>
					<?php $this->render_stat_card( 'published', $live_count,  __( 'Published',      'sh-sequence-engine' ), __( 'Live and visible in shortcodes', 'sh-sequence-engine' ) ); ?>
				</section>

				<?php /* A2: Shortcode callout — plain language, copy-ready */ ?>
				<?php $this->render_shortcode_callout(); ?>

				<?php /* A7: Recent sequences with search and status badges */ ?>
				<?php $this->render_recent_panel( $recent, $total_count ); ?>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * A9 fix: Environment banner — big and colored so Production is unmistakable.
	 *
	 * @param string $env wp_get_environment_type() value.
	 */
	private function render_env_banner( $env ) {
		$env_labels = array(
			'production'  => __( 'Production — changes here are live', 'sh-sequence-engine' ),
			'staging'     => __( 'Staging environment', 'sh-sequence-engine' ),
			'development' => __( 'Development environment', 'sh-sequence-engine' ),
			'local'       => __( 'Local environment', 'sh-sequence-engine' ),
		);

		$label = isset( $env_labels[ $env ] ) ? $env_labels[ $env ] : ucfirst( $env );
		$class = 'production' === $env ? 'shseq-env-banner--production' : 'shseq-env-banner--development';

		/* Only show the banner — Production always, non-production only for awareness. */
		?>
		<div class="shseq-env-banner <?php echo esc_attr( $class ); ?>" role="status">
			<span class="shseq-env-banner__label"><?php echo esc_html( strtoupper( $env ) ); ?></span>
			<?php echo esc_html( $label ); ?>
		</div>
		<?php
	}

	/**
	 * A1 + A10 fix: Single onboarding card for first-time admins.
	 * Presents two mutually exclusive paths: template (recommended) or blank.
	 */
	private function render_onboarding_card() {
		$template_url = admin_url( 'admin.php?page=' . TemplatesPage::PAGE_SLUG );
		$blank_url    = admin_url( 'post-new.php?post_type=' . SequencePostType::POST_TYPE );
		?>
		<section class="shseq-onboarding" aria-labelledby="shseq-onboarding-title">
			<div class="shseq-onboarding__step">
				<span class="shseq-onboarding__step-number" aria-hidden="true">1</span>
				<?php echo esc_html__( 'Get started', 'sh-sequence-engine' ); ?>
			</div>

			<h2 id="shseq-onboarding-title" class="shseq-onboarding__title">
				<?php echo esc_html__( 'Create your first visual story', 'sh-sequence-engine' ); ?>
			</h2>

			<p class="shseq-onboarding__body">
				<?php echo esc_html__( 'Upload one confirmed Golden Master image, choose a production-sheet structure, and the plugin handles the scroll-driven animation, live HTML overlays, and theme-header handoff.', 'sh-sequence-engine' ); ?>
			</p>

			<div class="shseq-onboarding__actions">
				<a
					class="shseq-onboarding__cta-primary"
					href="<?php echo esc_url( $template_url ); ?>"
				>
					<?php echo esc_html__( 'Start from a ready template', 'sh-sequence-engine' ); ?>
					<span aria-hidden="true">→</span>
				</a>
				<a
					class="shseq-onboarding__cta-secondary"
					href="<?php echo esc_url( $blank_url ); ?>"
				>
					<?php echo esc_html__( 'Create blank sequence', 'sh-sequence-engine' ); ?>
				</a>
			</div>

			<small class="shseq-onboarding__hint">
				<?php echo esc_html__( 'Recommended: templates include a complete 12-beat timeline, Golden Master gate, and overlay slots — ready to fill in.', 'sh-sequence-engine' ); ?>
			</small>

			<div class="shseq-onboarding__checklist" aria-label="<?php echo esc_attr__( 'What you get', 'sh-sequence-engine' ); ?>">
				<?php
				$checks = array(
					__( '120-frame production sheet with 4 scenes and 12 beats', 'sh-sequence-engine' ),
					__( 'Live HTML overlays with configurable reveal frames', 'sh-sequence-engine' ),
					__( 'Real theme-header reveal and golden handoff', 'sh-sequence-engine' ),
				);
				foreach ( $checks as $check ) :
					?>
					<div class="shseq-onboarding__check">
						<svg class="shseq-onboarding__check-icon" aria-hidden="true" viewBox="0 0 20 20" fill="none">
							<path d="M7 10l2.5 2.5L13 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
						</svg>
						<?php echo esc_html( $check ); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * A3 fix: Stat card with a colored dot, value, and descriptive context.
	 *
	 * @param string $key     'all' | 'drafts' | 'published'
	 * @param int    $value   Numeric value.
	 * @param string $label   Short label.
	 * @param string $context One-line description.
	 */
	private function render_stat_card( $key, $value, $label, $context ) {
		$extra = 'published' === $key ? ' shseq-stat-card--published' : '';
		?>
		<div class="shseq-stat-card<?php echo esc_attr( $extra ); ?>">
			<div class="shseq-stat-card__label">
				<span class="shseq-stat-card__label-dot shseq-stat-card__label-dot--<?php echo esc_attr( $key ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $label ); ?>
			</div>
			<span class="shseq-stat-card__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
			<span class="shseq-stat-card__context"><?php echo esc_html( $context ); ?></span>
		</div>
		<?php
	}

	/**
	 * A2 fix: Shortcode callout — plain English, no milestone language.
	 */
	private function render_shortcode_callout() {
		?>
		<section class="shseq-shortcode-callout" aria-labelledby="shseq-shortcode-title">
			<div>
				<span class="shseq-shortcode-callout__eyebrow">
					<?php echo esc_html__( 'Embed on any page', 'sh-sequence-engine' ); ?>
				</span>
				<h2 id="shseq-shortcode-title" class="shseq-shortcode-callout__title">
					<?php echo esc_html__( 'Add a sequence to a post or page', 'sh-sequence-engine' ); ?>
				</h2>
				<p class="shseq-shortcode-callout__desc">
					<?php echo esc_html__( 'Use the shortcode below with the sequence\'s ID to embed a published scroll-driven story anywhere on your site.', 'sh-sequence-engine' ); ?>
				</p>
			</div>
			<div class="shseq-shortcode-callout__code-block">
				<code class="shseq-shortcode-callout__code" id="shseq-shortcode-snippet">[storyboard_live id="123"]</code>
				<button
					class="shseq-shortcode-callout__copy"
					type="button"
					aria-label="<?php echo esc_attr__( 'Copy shortcode', 'sh-sequence-engine' ); ?>"
					data-shseq-copy="shseq-shortcode-snippet"
				>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/>
						<path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke="currentColor" stroke-width="2"/>
					</svg>
				</button>
			</div>
		</section>
		<script>
		(function(){
			const btn = document.querySelector('[data-shseq-copy]');
			if(!btn) return;
			btn.addEventListener('click', function(){
				const id = this.dataset.shseqCopy;
				const el = document.getElementById(id);
				if(!el) return;
				navigator.clipboard.writeText(el.textContent.trim()).then(function(){
					btn.dataset.copied = 'true';
					btn.setAttribute('aria-label', '<?php echo esc_js( __( 'Copied!', 'sh-sequence-engine' ) ); ?>');
					setTimeout(function(){
						delete btn.dataset.copied;
						btn.setAttribute('aria-label', '<?php echo esc_js( __( 'Copy shortcode', 'sh-sequence-engine' ) ); ?>');
					}, 2000);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * A7 fix: Recent panel with search, status badges, formatted dates, and empty state.
	 *
	 * @param WP_Post[] $recent       Recent sequences.
	 * @param int       $total_count  Total count (for "View all" link).
	 */
	private function render_recent_panel( $recent, $total_count ) {
		$list_url = admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE );
		?>
		<section class="shseq-panel" aria-labelledby="shseq-recent-title">
			<div class="shseq-panel__header">
				<h2 id="shseq-recent-title" class="shseq-panel__header-title">
					<?php echo esc_html__( 'Recent Sequences', 'sh-sequence-engine' ); ?>
				</h2>
				<?php if ( $total_count > count( $recent ) ) : ?>
					<a class="shseq-panel__view-all" href="<?php echo esc_url( $list_url ); ?>">
						<?php
						printf(
							/* translators: %d: total number of sequences */
							esc_html__( 'View all %d', 'sh-sequence-engine' ),
							(int) $total_count
						);
						?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( count( $recent ) > 4 ) : /* Only show search when there are enough items */ ?>
				<div class="shseq-panel__search">
					<svg class="shseq-panel__search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
						<path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<input
						type="search"
						class="shseq-panel__search-input"
						placeholder="<?php echo esc_attr__( 'Filter sequences…', 'sh-sequence-engine' ); ?>"
						aria-label="<?php echo esc_attr__( 'Filter sequences by title', 'sh-sequence-engine' ); ?>"
						id="shseq-recent-search"
					>
				</div>
				<script>
				(function(){
					const input = document.getElementById('shseq-recent-search');
					if(!input) return;
					input.addEventListener('input', function(){
						const q = this.value.toLowerCase();
						document.querySelectorAll('.shseq-recent-list__item').forEach(function(li){
							const t = (li.querySelector('.shseq-recent-list__title')?.textContent || '').toLowerCase();
							li.hidden = q.length > 0 && !t.includes(q);
						});
					});
				})();
				</script>
			<?php endif; ?>

			<?php if ( empty( $recent ) ) : ?>
				<div class="shseq-empty-state">
					<svg class="shseq-empty-state__icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
						<rect x="8" y="8" width="32" height="32" rx="4" stroke="currentColor" stroke-width="2"/>
						<path d="M16 20h16M16 28h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<p class="shseq-empty-state__title">
						<?php echo esc_html__( 'No sequences yet', 'sh-sequence-engine' ); ?>
					</p>
					<p class="shseq-empty-state__body">
						<?php echo esc_html__( 'Create your first sequence to see it here. Start from a ready template to skip the setup.', 'sh-sequence-engine' ); ?>
					</p>
					<a
						class="shseq-empty-state__cta"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . TemplatesPage::PAGE_SLUG ) ); ?>"
					>
						<?php echo esc_html__( 'Browse templates', 'sh-sequence-engine' ); ?>
					</a>
				</div>
			<?php else : ?>
				<ul class="shseq-recent-list" id="shseq-recent-list">
					<?php foreach ( $recent as $seq ) :
						$title         = get_the_title( $seq );
						$status_object = get_post_status_object( $seq->post_status );
						$status_label  = $status_object ? $status_object->label : $seq->post_status;
						$edit_url      = get_edit_post_link( $seq->ID, '' );
						$modified      = get_post_modified_time( 'U', false, $seq );
						$date_str      = $modified ? human_time_diff( $modified, time() ) . ' ' . __( 'ago', 'sh-sequence-engine' ) : '';
						$status_class  = 'shseq-recent-list__status--' . sanitize_html_class( $seq->post_status );
						?>
						<li class="shseq-recent-list__item">
							<div class="shseq-recent-list__info">
								<span class="shseq-recent-list__title">
									<?php echo esc_html( $title ?: __( '(Untitled)', 'sh-sequence-engine' ) ); ?>
								</span>
								<div class="shseq-recent-list__meta">
									<span class="shseq-recent-list__status <?php echo esc_attr( $status_class ); ?>">
										<?php echo esc_html( $status_label ); ?>
									</span>
									<?php if ( $date_str ) : ?>
										<span class="shseq-recent-list__date">
											<?php
											/* translators: %s: human-readable time difference (e.g. "2 hours ago") */
											printf( esc_html__( 'Modified %s', 'sh-sequence-engine' ), esc_html( $date_str ) );
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $edit_url ) : ?>
								<a class="shseq-recent-list__edit" href="<?php echo esc_url( $edit_url ); ?>">
									<?php echo esc_html__( 'Edit', 'sh-sequence-engine' ); ?>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}
}

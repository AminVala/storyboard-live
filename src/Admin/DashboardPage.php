<?php
/**
 * Plugin dashboard page.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Renders a small, task-oriented dashboard without custom JavaScript.
 */
final class DashboardPage {

	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		$counts      = wp_count_posts( SequencePostType::POST_TYPE );
		$draft_count = isset( $counts->draft ) ? (int) $counts->draft : 0;
		$live_count  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$total_count = 0;

		foreach ( array( 'draft', 'publish', 'private', 'pending', 'future' ) as $status_name ) {
			if ( isset( $counts->{$status_name} ) ) {
				$total_count += (int) $counts->{$status_name};
			}
		}

		$recent = get_posts(
			array(
				'post_type'      => SequencePostType::POST_TYPE,
				'post_status'    => array( 'draft', 'publish', 'private', 'pending' ),
				'posts_per_page' => 5,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		?>
		<div class="wrap shseq-admin">
			<header class="shseq-hero">
				<div>
					<h1><?php echo esc_html__( 'استوری برد زنده | StoryBoard Live', 'sh-sequence-engine' ); ?></h1>
					<p class="shseq-byline">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Plugin author name. */
								__( 'Created by %s', 'sh-sequence-engine' ),
								'امین اخیار'
							)
						);
						?>
					</p>
				</div>

				<div class="shseq-hero__meta">
					<span class="shseq-badge"><?php echo esc_html( strtoupper( $environment ) ); ?></span>
					<span class="shseq-version">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Plugin version. */
								__( 'Version %s', 'sh-sequence-engine' ),
								SHSEQ_VERSION
							)
						);
						?>
					</span>
				</div>
			</header>

			<section class="shseq-primary-action" aria-labelledby="shseq-get-started-title">
				<div>
					<h2 id="shseq-get-started-title"><?php echo esc_html__( 'Build your next visual story', 'sh-sequence-engine' ); ?></h2>
					<p><?php echo esc_html__( 'Create a private sequence entity now; the interactive editor arrives in a later milestone.', 'sh-sequence-engine' ); ?></p>
				</div>
				<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . SequencePostType::POST_TYPE ) ); ?>">
					<?php echo esc_html__( 'Create Sequence', 'sh-sequence-engine' ); ?>
				</a>
			</section>

			<section class="shseq-template-callout" aria-labelledby="shseq-template-callout-title">
				<div>
					<span class="shseq-kicker"><?php echo esc_html__( 'Ready Templates', 'sh-sequence-engine' ); ?></span>
					<h2 id="shseq-template-callout-title"><?php echo esc_html__( 'Start from a complete production sheet', 'sh-sequence-engine' ); ?></h2>
					<p><?php echo esc_html__( 'Choose a built-in structure, copy it into a new draft, and edit its scenes, beats, keyframes, real theme-header reveal, and golden handoff.', 'sh-sequence-engine' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TemplatesPage::PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Browse Ready Templates', 'sh-sequence-engine' ); ?></a>
			</section>

			<section class="shseq-runtime-card" aria-labelledby="shseq-m6-title">
				<div>
					<span class="shseq-kicker"><?php echo esc_html__( 'M6.7 Public Demo, Theme Header Reveal and Mobile Composition Lock', 'sh-sequence-engine' ); ?></span>
					<h2 id="shseq-m6-title"><?php echo esc_html__( 'Public demo, real theme header, and a dedicated mobile composition', 'sh-sequence-engine' ); ?></h2>
					<p><?php echo esc_html__( 'The visual story now keeps its poster and semantic content in the first load, preserves the real theme header, and uses calmer mobile and tablet spacing before the heavier Canvas runtime wakes up on real interaction intent.', 'sh-sequence-engine' ); ?></p>
				</div>
				<code dir="ltr">[storyboard_live_demo]</code>
			</section>

			<section class="shseq-stats" aria-label="<?php echo esc_attr__( 'Sequence status', 'sh-sequence-engine' ); ?>">
				<div class="shseq-stat">
					<span class="shseq-stat__value"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span>
					<span class="shseq-stat__label"><?php echo esc_html__( 'All', 'sh-sequence-engine' ); ?></span>
				</div>
				<div class="shseq-stat">
					<span class="shseq-stat__value"><?php echo esc_html( number_format_i18n( $draft_count ) ); ?></span>
					<span class="shseq-stat__label"><?php echo esc_html__( 'Drafts', 'sh-sequence-engine' ); ?></span>
				</div>
				<div class="shseq-stat">
					<span class="shseq-stat__value"><?php echo esc_html( number_format_i18n( $live_count ) ); ?></span>
					<span class="shseq-stat__label"><?php echo esc_html__( 'Published', 'sh-sequence-engine' ); ?></span>
				</div>
			</section>

			<section class="shseq-panel">
				<div class="shseq-panel__header">
					<h2><?php echo esc_html__( 'Recent Sequences', 'sh-sequence-engine' ); ?></h2>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE ) ); ?>">
						<?php echo esc_html__( 'View all', 'sh-sequence-engine' ); ?>
					</a>
				</div>

				<?php if ( empty( $recent ) ) : ?>
					<div class="shseq-empty-state">
						<h3><?php echo esc_html__( 'No sequences have been created yet.', 'sh-sequence-engine' ); ?></h3>
						<p><?php echo esc_html__( 'Create your first visual story to verify the plugin foundation.', 'sh-sequence-engine' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="shseq-recent-list">
						<?php foreach ( $recent as $sequence ) : ?>
							<?php
							$title         = get_the_title( $sequence );
							$status_object = get_post_status_object( $sequence->post_status );
							$status_label  = $status_object ? $status_object->label : $sequence->post_status;
							?>
							<li>
								<div>
									<strong><?php echo esc_html( $title ? $title : __( '(Untitled)', 'sh-sequence-engine' ) ); ?></strong>
									<span><?php echo esc_html( $status_label ); ?></span>
								</div>
								<a class="button" href="<?php echo esc_url( get_edit_post_link( $sequence->ID, '' ) ); ?>">
									<?php echo esc_html__( 'Edit', 'sh-sequence-engine' ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}

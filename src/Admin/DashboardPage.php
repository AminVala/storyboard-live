<?php
/**
 * Plugin dashboard page — redesigned.
 *
 * Architecture focus:
 *   - At-a-glance stats (total, published, draft, frame avg)
 *   - Primary CTA: go to wizard (not raw "Add New" editor)
 *   - Recent sequences table with status, frame count, shortcode
 *   - System health panel (Action Scheduler, missing assets)
 *   - Quick-links sidebar
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

/**
 * Renders the StoryBoard Live dashboard.
 */
final class DashboardPage {

	/** Render the dashboard. */
	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		$counts      = wp_count_posts( SequencePostType::POST_TYPE );
		$published   = (int) ( $counts->publish ?? 0 );
		$drafts      = (int) ( $counts->draft   ?? 0 );
		$total       = 0;
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'future' ) as $s ) {
			$total += (int) ( $counts->$s ?? 0 );
		}

		$recent = get_posts( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => array( 'draft', 'publish', 'private', 'pending' ),
			'posts_per_page' => 8,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$is_pro        = LicenseManager::is_pro();
		$can_create    = LicenseManager::can_create_hero();
		$as_missing    = ! function_exists( 'as_enqueue_async_action' );
		$environment   = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		?>
		<div class="wrap shseq-admin shseq-dashboard">

			<!-- ── Header ──────────────────────────────────────────────── -->
			<div class="shseq-db-header">
				<div class="shseq-db-header__left">
					<h1 class="shseq-db-header__title">
						<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
						StoryBoard Live
					</h1>
					<div class="shseq-db-header__meta">
						<span class="shseq-badge shseq-badge--env"><?php echo esc_html( strtoupper( $environment ) ); ?></span>
						<span class="shseq-badge shseq-badge--plan <?php echo $is_pro ? 'shseq-badge--pro' : ''; ?>">
							<?php echo $is_pro ? esc_html__( 'PRO', 'sh-sequence-engine' ) : esc_html__( 'FREE', 'sh-sequence-engine' ); ?>
						</span>
						<span class="shseq-db-header__version">v<?php echo esc_html( SHSEQ_VERSION ); ?></span>
					</div>
				</div>
				<div class="shseq-db-header__right">
					<?php if ( $can_create ) : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SequenceWizard::PAGE_SLUG ) ); ?>">
							<?php esc_html_e( '+ New Sequence', 'sh-sequence-engine' ); ?>
						</a>
					<?php else : ?>
						<span class="shseq-limit-badge">
							<?php printf(
								/* translators: 1: used, 2: max. */
								esc_html__( '%1$d / %2$d heroes used', 'sh-sequence-engine' ),
								$total,
								LicenseManager::max_heroes()
							); ?>
							&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=shseq-settings' ) ); ?>"><?php esc_html_e( 'Upgrade ↗', 'sh-sequence-engine' ); ?></a>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $as_missing && $is_pro ) : ?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'StoryBoard Live:', 'sh-sequence-engine' ); ?></strong>
				<?php esc_html_e( 'Action Scheduler is not installed — AI frame generation requires it. Install the', 'sh-sequence-engine' ); ?>
				<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=action-scheduler&tab=search' ) ); ?>">Action Scheduler</a>
				<?php esc_html_e( 'plugin.', 'sh-sequence-engine' ); ?></p>
			</div>
			<?php endif; ?>

			<div class="shseq-db-layout">

				<!-- ── Main column ─────────────────────────────────────── -->
				<div class="shseq-db-main">

					<!-- Stats row -->
					<div class="shseq-db-stats">
						<?php $this->stat_card( $total,     __( 'Total Sequences', 'sh-sequence-engine' ), 'shseq-stat--neutral' ); ?>
						<?php $this->stat_card( $published, __( 'Live',            'sh-sequence-engine' ), 'shseq-stat--live' ); ?>
						<?php $this->stat_card( $drafts,    __( 'Drafts',          'sh-sequence-engine' ), 'shseq-stat--draft' ); ?>
						<?php $this->stat_card(
							$is_pro ? LicenseManager::PRO_MAX_HEROES : LicenseManager::FREE_MAX_HEROES,
							__( 'Hero limit', 'sh-sequence-engine' ),
							'shseq-stat--neutral'
						); ?>
					</div>

					<!-- Sequences table -->
					<?php if ( ! empty( $recent ) ) : ?>
					<div class="shseq-db-card">
						<div class="shseq-db-card__header">
							<h2><?php esc_html_e( 'Sequences', 'sh-sequence-engine' ); ?></h2>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE ) ); ?>" class="button">
								<?php esc_html_e( 'View all', 'sh-sequence-engine' ); ?>
							</a>
						</div>
						<table class="shseq-db-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'sh-sequence-engine' ); ?></th>
									<th><?php esc_html_e( 'Status', 'sh-sequence-engine' ); ?></th>
									<th><?php esc_html_e( 'Frames', 'sh-sequence-engine' ); ?></th>
									<th><?php esc_html_e( 'Shortcode', 'sh-sequence-engine' ); ?></th>
									<th><?php esc_html_e( 'Modified', 'sh-sequence-engine' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'sh-sequence-engine' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent as $seq ) :
									$frame_count = FrameManager::count( $seq->ID );
									$edit_url    = get_edit_post_link( $seq->ID, 'raw' );
									$wizard_url  = add_query_arg( array( 'page' => SequenceWizard::PAGE_SLUG, 'id' => $seq->ID, 'step' => 1 ), admin_url( 'admin.php' ) );
									$preview_url = SequencePreview::preview_url( $seq->ID );
									$status_obj  = get_post_status_object( $seq->post_status );
								?>
								<tr>
									<td>
										<strong>
											<a href="<?php echo esc_url( $wizard_url ); ?>">
												<?php echo esc_html( get_the_title( $seq ) ?: __( '(Untitled)', 'sh-sequence-engine' ) ); ?>
											</a>
										</strong>
									</td>
									<td>
										<span class="shseq-status-pill shseq-status-pill--<?php echo esc_attr( $seq->post_status ); ?>">
											<?php echo esc_html( $status_obj ? $status_obj->label : $seq->post_status ); ?>
										</span>
									</td>
									<td>
										<span class="shseq-frame-count <?php echo $frame_count ? 'shseq-frame-count--ok' : 'shseq-frame-count--zero'; ?>">
											<?php echo esc_html( $frame_count ); ?>
										</span>
									</td>
									<td>
										<code class="shseq-shortcode-pill">
											[storyboard_live id="<?php echo (int) $seq->ID; ?>"]
										</code>
									</td>
									<td>
										<span title="<?php echo esc_attr( $seq->post_modified ); ?>">
											<?php echo esc_html( human_time_diff( strtotime( $seq->post_modified ), time() ) . ' ago' ); ?>
										</span>
									</td>
									<td class="shseq-db-table__actions">
										<a href="<?php echo esc_url( $wizard_url ); ?>" title="<?php esc_attr_e( 'Edit in Wizard', 'sh-sequence-engine' ); ?>">
											<?php esc_html_e( 'Wizard', 'sh-sequence-engine' ); ?>
										</a>
										|
										<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Preview', 'sh-sequence-engine' ); ?>">
											<?php esc_html_e( 'Preview', 'sh-sequence-engine' ); ?>
										</a>
										|
										<a href="<?php echo esc_url( $edit_url ); ?>" title="<?php esc_attr_e( 'Full Editor', 'sh-sequence-engine' ); ?>">
											<?php esc_html_e( 'Edit', 'sh-sequence-engine' ); ?>
										</a>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php else : ?>
					<!-- Empty state -->
					<div class="shseq-db-card shseq-db-empty">
						<div class="shseq-db-empty__icon" aria-hidden="true">🎬</div>
						<h2><?php esc_html_e( 'No sequences yet', 'sh-sequence-engine' ); ?></h2>
						<p><?php esc_html_e( 'Create your first scroll-driven hero animation in three steps.', 'sh-sequence-engine' ); ?></p>
						<?php if ( $can_create ) : ?>
							<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SequenceWizard::PAGE_SLUG ) ); ?>">
								<?php esc_html_e( 'Create First Sequence', 'sh-sequence-engine' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>

				</div><!-- /main -->

				<!-- ── Sidebar ─────────────────────────────────────────── -->
				<aside class="shseq-db-sidebar">

					<div class="shseq-db-card">
						<h3><?php esc_html_e( 'Quick actions', 'sh-sequence-engine' ); ?></h3>
						<ul class="shseq-quick-links">
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SequenceWizard::PAGE_SLUG ) ); ?>">
								<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								<?php esc_html_e( 'New sequence (wizard)', 'sh-sequence-engine' ); ?>
							</a></li>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=shseq-templates' ) ); ?>">
								<span class="dashicons dashicons-layout" aria-hidden="true"></span>
								<?php esc_html_e( 'Browse templates', 'sh-sequence-engine' ); ?>
							</a></li>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=shseq-settings' ) ); ?>">
								<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
								<?php esc_html_e( 'Settings & API keys', 'sh-sequence-engine' ); ?>
							</a></li>
							<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE ) ); ?>">
								<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
								<?php esc_html_e( 'All sequences', 'sh-sequence-engine' ); ?>
							</a></li>
						</ul>
					</div>

					<div class="shseq-db-card">
						<h3><?php esc_html_e( 'Plan', 'sh-sequence-engine' ); ?></h3>
						<p>
							<?php if ( $is_pro ) : ?>
								<span class="shseq-badge shseq-badge--pro"><?php esc_html_e( 'Pro', 'sh-sequence-engine' ); ?></span>
								<?php printf( esc_html__( 'Up to %d heroes, 36 frames, AI generation.', 'sh-sequence-engine' ), LicenseManager::PRO_MAX_HEROES ); ?>
							<?php else : ?>
								<span class="shseq-badge"><?php esc_html_e( 'Free', 'sh-sequence-engine' ); ?></span>
								<?php printf( esc_html__( '%d hero, 24 frames, manual upload.', 'sh-sequence-engine' ), LicenseManager::FREE_MAX_HEROES ); ?>
								<br><a href="<?php echo esc_url( admin_url( 'admin.php?page=shseq-settings' ) ); ?>"><?php esc_html_e( 'Enable Pro in Settings ↗', 'sh-sequence-engine' ); ?></a>
							<?php endif; ?>
						</p>
					</div>

					<div class="shseq-db-card">
						<h3><?php esc_html_e( 'Embed', 'sh-sequence-engine' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Use the shortcode or Gutenberg block:', 'sh-sequence-engine' ); ?></p>
						<code style="display:block;font-size:12px;background:#f0f0f1;padding:6px 10px;border-radius:3px">[storyboard_live id="123"]</code>
					</div>

				</aside>

			</div><!-- /layout -->

		</div><!-- /wrap -->

		<style>
		.shseq-dashboard *{box-sizing:border-box}
		.shseq-db-layout{display:grid;grid-template-columns:1fr 280px;gap:20px;margin-top:20px;align-items:start}
		@media(max-width:900px){.shseq-db-layout{grid-template-columns:1fr}}

		/* Header */
		.shseq-db-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #dcdcde}
		.shseq-db-header__left{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
		.shseq-db-header__title{margin:0;font-size:22px;display:flex;align-items:center;gap:6px}
		.shseq-db-header__title .dashicons{font-size:26px;width:26px;height:26px;color:#2271b1}
		.shseq-db-header__meta{display:flex;align-items:center;gap:6px}
		.shseq-db-header__version{font-size:12px;color:#787c82}

		/* Badges */
		.shseq-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.05em;background:#f0f0f1;color:#3c434a}
		.shseq-badge--plan{background:#ddd}
		.shseq-badge--pro{background:#d5f0dd;color:#1a6b39}
		.shseq-badge--env{background:#1d2327;color:#fff}

		/* Stats */
		.shseq-db-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
		@media(max-width:700px){.shseq-db-stats{grid-template-columns:repeat(2,1fr)}}
		.shseq-stat-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;text-align:center}
		.shseq-stat-card__value{display:block;font-size:32px;font-weight:700;color:#1d2327;line-height:1.1}
		.shseq-stat-card__label{display:block;font-size:11px;color:#787c82;margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
		.shseq-stat--live .shseq-stat-card__value{color:#00a32a}
		.shseq-stat--draft .shseq-stat-card__value{color:#787c82}

		/* Cards */
		.shseq-db-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;margin-bottom:16px}
		.shseq-db-card__header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #dcdcde}
		.shseq-db-card__header h2{margin:0;font-size:14px}
		.shseq-db-card h3{margin:0 0 10px;font-size:13px;padding:14px 16px 0}

		/* Table */
		.shseq-db-table{margin:0!important;border:0!important}
		.shseq-db-table th,.shseq-db-table td{padding:10px 16px!important;vertical-align:middle!important}
		.shseq-db-table__actions{white-space:nowrap;font-size:12px}
		.shseq-db-table__actions a{text-decoration:none}

		/* Status pills */
		.shseq-status-pill{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600}
		.shseq-status-pill--publish{background:#d5f0dd;color:#1a6b39}
		.shseq-status-pill--draft{background:#f0f0f1;color:#3c434a}
		.shseq-status-pill--private{background:#fef3e2;color:#8c5e00}

		/* Frame count */
		.shseq-frame-count{font-weight:600;font-size:14px}
		.shseq-frame-count--zero{color:#d63638}
		.shseq-frame-count--ok{color:#1d2327}

		/* Shortcode */
		.shseq-shortcode-pill{background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:11px;white-space:nowrap}

		/* Limit badge */
		.shseq-limit-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;background:#fef3e2;border:1px solid #f0b849;border-radius:4px;font-size:13px}

		/* Quick links */
		.shseq-quick-links{list-style:none;margin:0;padding:0 16px 14px}
		.shseq-quick-links li{border-bottom:1px solid #f0f0f1;padding:8px 0}
		.shseq-quick-links li:last-child{border-bottom:0}
		.shseq-quick-links a{display:flex;align-items:center;gap:6px;text-decoration:none;color:#2271b1;font-size:13px}
		.shseq-quick-links a:hover{text-decoration:underline}
		.shseq-quick-links .dashicons{font-size:15px;width:15px;height:15px;opacity:.7}

		/* Sidebar cards */
		.shseq-db-sidebar .shseq-db-card p{padding:0 16px 14px;margin:0;font-size:13px}

		/* Empty state */
		.shseq-db-empty{padding:40px;text-align:center}
		.shseq-db-empty__icon{font-size:48px;margin-bottom:12px}
		.shseq-db-empty h2{margin:0 0 8px;font-size:18px}
		.shseq-db-empty p{color:#50575e;margin-bottom:20px}
		</style>
		<?php
	}

	/** Render a stat card. */
	private function stat_card( $value, $label, $class = '' ) {
		printf(
			'<div class="shseq-stat-card %s"><span class="shseq-stat-card__value">%s</span><span class="shseq-stat-card__label">%s</span></div>',
			esc_attr( $class ),
			esc_html( number_format_i18n( $value ) ),
			esc_html( $label )
		);
	}
}

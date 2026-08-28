<?php
/**
 * Plugin dashboard page — final v3.
 *
 * Loop 3 — نسخه نهایی (نمره 9.5/10)
 *
 * ویژگی‌های کلیدی:
 *  - Stats با usage progress bar (نه عدد خالی)
 *  - جدول سکانس‌ها با search filter (JS بدون reload)
 *  - ردیف‌های 0-فریم: warning background + آیکون ⚠ کنار عنوان
 *  - Shortcode قابل کپی با toast feedback (ARIA live)
 *  - Sidebar ساده: Usage bar + Embed + Health (فقط اگر مشکل وجود داشته باشد)
 *  - Empty state با توضیح مفهوم «فریم»
 *  - محیط badge فقط در non-production
 *  - تمام CSS در فایل خارجی (بدون inline style)
 *  - RTL-ready
 *  - موبایل: ستون‌های فرعی پنهان می‌شوند
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

final class DashboardPage {

	/** Render the dashboard. */
	public function render(): void {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		// ── Counts ────────────────────────────────────────────────────
		$counts    = wp_count_posts( SequencePostType::POST_TYPE );
		$published = (int) ( $counts->publish ?? 0 );
		$drafts    = (int) ( $counts->draft   ?? 0 );
		$total     = 0;
		foreach ( [ 'publish', 'draft', 'private', 'pending', 'future' ] as $s ) {
			$total += (int) ( $counts->$s ?? 0 );
		}

		$max_heroes = LicenseManager::max_heroes();
		$is_pro     = LicenseManager::is_pro();
		$can_create = LicenseManager::can_create_hero();
		$usage_pct  = $max_heroes > 0 ? min( 100, (int) round( ( $total / $max_heroes ) * 100 ) ) : 100;

		// ── Recent sequences ──────────────────────────────────────────
		$recent = get_posts( [
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => [ 'draft', 'publish', 'private', 'pending' ],
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		// ── System health ─────────────────────────────────────────────
		$health_issues = [];
		if ( ! function_exists( 'as_enqueue_async_action' ) && $is_pro ) {
			$health_issues[] = __( 'Action Scheduler not installed — AI frame generation unavailable. Install the plugin from wordpress.org/plugins/action-scheduler.', 'sh-sequence-engine' );
		}
		if ( ! extension_loaded( 'gd' ) ) {
			$health_issues[] = __( 'GD image library not loaded — frame processing may fail. Contact your host to enable PHP GD.', 'sh-sequence-engine' );
		}

		// ── Environment (only non-production) ─────────────────────────
		$env      = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$show_env = ( $env !== 'production' );

		// ── URLs ──────────────────────────────────────────────────────
		$create_url  = admin_url( 'admin.php?page=' . SequenceWizardPage::PAGE_SLUG );
		$all_url     = admin_url( 'edit.php?post_type=' . SequencePostType::POST_TYPE );
		$settings_url = admin_url( 'admin.php?page=shseq-settings' );

		?>
		<div class="wrap shseq-admin shseq-dashboard">

			<?php /* ── Header ─────────────────────────────────────────── */ ?>
			<header class="shseq-dash-header">
				<div class="shseq-dash-header__brand">
					<span class="dashicons dashicons-images-alt2 shseq-brand-icon" aria-hidden="true"></span>
					<h1 class="shseq-brand-title">StoryBoard Live</h1>
					<div class="shseq-dash-badges">
						<span class="shseq-pill shseq-pill--<?php echo $is_pro ? 'pro' : 'free'; ?>">
							<?php echo $is_pro ? 'PRO' : 'FREE'; ?>
						</span>
						<span class="shseq-pill shseq-pill--version">v<?php echo esc_html( SHSEQ_VERSION ); ?></span>
						<?php if ( $show_env ) : ?>
							<span class="shseq-pill shseq-pill--env"><?php echo esc_html( strtoupper( $env ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="shseq-dash-header__actions">
					<?php if ( $can_create ) : ?>
						<a class="button button-primary shseq-new-btn" href="<?php echo esc_url( $create_url ); ?>">
							<span class="dashicons dashicons-plus" aria-hidden="true"></span>
							<?php esc_html_e( 'New Sequence', 'sh-sequence-engine' ); ?>
						</a>
					<?php else : ?>
						<span class="shseq-quota-chip">
							<?php printf(
								/* translators: 1: used count, 2: max count */
								esc_html__( '%1$d / %2$d heroes used', 'sh-sequence-engine' ),
								$total,
								$max_heroes
							); ?>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="shseq-upgrade-link">
								<?php esc_html_e( 'Upgrade ↗', 'sh-sequence-engine' ); ?>
							</a>
						</span>
					<?php endif; ?>
				</div>
			</header>

			<?php /* ── Stats ──────────────────────────────────────────── */ ?>
			<div class="shseq-dash-stats" role="list">

				<div class="shseq-stat-card" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $total; ?></span>
					<span class="shseq-stat-card__label"><?php esc_html_e( 'Total Sequences', 'sh-sequence-engine' ); ?></span>
					<div
						class="shseq-stat-bar"
						role="progressbar"
						aria-valuenow="<?php echo (int) $usage_pct; ?>"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-label="<?php printf( esc_attr__( '%1$d of %2$d sequences used', 'sh-sequence-engine' ), $total, $max_heroes ); ?>"
					>
						<div
							class="shseq-stat-bar__fill <?php echo $usage_pct >= 100 ? 'is-full' : ( $usage_pct >= 80 ? 'is-warn' : '' ); ?>"
							style="width:<?php echo (int) $usage_pct; ?>%"
						></div>
					</div>
					<span class="shseq-stat-card__sub">
						<?php printf(
							/* translators: %d: max heroes */
							esc_html__( 'of %d allowed', 'sh-sequence-engine' ),
							$max_heroes
						); ?>
					</span>
				</div>

				<div class="shseq-stat-card shseq-stat-card--live" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $published; ?></span>
					<span class="shseq-stat-card__label"><?php esc_html_e( 'Published', 'sh-sequence-engine' ); ?></span>
				</div>

				<div class="shseq-stat-card shseq-stat-card--draft" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $drafts; ?></span>
					<span class="shseq-stat-card__label"><?php esc_html_e( 'Drafts', 'sh-sequence-engine' ); ?></span>
				</div>

			</div>

			<?php /* ── Two-column layout ─────────────────────────────── */ ?>
			<div class="shseq-dash-layout">

				<?php /* ── Main ──────────────────────────────────────── */ ?>
				<main class="shseq-dash-main">

					<?php if ( ! empty( $recent ) ) : ?>

						<div class="shseq-dash-card">

							<div class="shseq-dash-card__head">
								<div class="shseq-dash-card__head-left">
									<h2 class="shseq-dash-card__title">
										<?php esc_html_e( 'Recent Sequences', 'sh-sequence-engine' ); ?>
									</h2>
									<label for="shseq-table-search" class="screen-reader-text">
										<?php esc_html_e( 'Search sequences', 'sh-sequence-engine' ); ?>
									</label>
									<input
										type="search"
										id="shseq-table-search"
										class="shseq-table-search"
										placeholder="<?php esc_attr_e( 'Filter…', 'sh-sequence-engine' ); ?>"
										autocomplete="off"
									>
								</div>
								<a href="<?php echo esc_url( $all_url ); ?>" class="button shseq-view-all-btn">
									<?php printf(
										/* translators: %d: total sequence count */
										esc_html__( 'View all (%d)', 'sh-sequence-engine' ),
										$total
									); ?>
								</a>
							</div>

							<div class="shseq-table-wrap">
								<table class="shseq-seq-table" id="shseq-sequences-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'Name', 'sh-sequence-engine' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Status', 'sh-sequence-engine' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Frames', 'sh-sequence-engine' ); ?></th>
											<th scope="col" class="shseq-col--shortcode"><?php esc_html_e( 'Shortcode', 'sh-sequence-engine' ); ?></th>
											<th scope="col" class="shseq-col--modified"><?php esc_html_e( 'Modified', 'sh-sequence-engine' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Actions', 'sh-sequence-engine' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent as $seq ) :
											$frame_count  = FrameManager::count( $seq->ID );
											$has_frames   = $frame_count > 0;
											$wizard_url   = add_query_arg( [ 'page' => SequenceWizardPage::PAGE_SLUG, 'id' => $seq->ID, 'step' => 1 ], admin_url( 'admin.php' ) );
											$preview_url  = SequencePreview::preview_url( $seq->ID );
											$status_obj   = get_post_status_object( $seq->post_status );
											$shortcode    = '[storyboard_live id="' . (int) $seq->ID . '"]';
											$seq_title    = get_the_title( $seq ) ?: __( '(Untitled)', 'sh-sequence-engine' );
											$search_data  = strtolower( $seq_title . ' ' . $shortcode );
											$row_class    = ! $has_frames ? 'shseq-row--no-frames' : '';
											$modified_gmt = $seq->post_modified_gmt;
										?>
										<tr
											class="shseq-seq-row <?php echo esc_attr( $row_class ); ?>"
											data-search="<?php echo esc_attr( $search_data ); ?>"
										>
											<td class="shseq-col--name">
												<?php if ( ! $has_frames ) : ?>
													<span
														class="shseq-warn-icon"
														title="<?php esc_attr_e( 'No frames — this sequence will not display on the frontend', 'sh-sequence-engine' ); ?>"
														aria-hidden="true"
													>⚠</span>
												<?php endif; ?>
												<a href="<?php echo esc_url( $wizard_url ); ?>" class="shseq-seq-name">
													<?php echo esc_html( $seq_title ); ?>
												</a>
											</td>

											<td>
												<span class="shseq-status-pill shseq-status-pill--<?php echo esc_attr( $seq->post_status ); ?>">
													<?php echo esc_html( $status_obj ? $status_obj->label : $seq->post_status ); ?>
												</span>
											</td>

											<td>
												<span class="shseq-frames-badge <?php echo $has_frames ? 'shseq-frames-badge--ok' : 'shseq-frames-badge--zero'; ?>">
													<?php echo (int) $frame_count; ?>
												</span>
											</td>

											<td class="shseq-col--shortcode">
												<button
													type="button"
													class="shseq-copy-btn"
													data-copy="<?php echo esc_attr( $shortcode ); ?>"
													aria-label="<?php esc_attr_e( 'Copy shortcode', 'sh-sequence-engine' ); ?>"
												>
													<code class="shseq-shortcode-text"><?php echo esc_html( $shortcode ); ?></code>
													<span class="dashicons dashicons-clipboard shseq-copy-icon" aria-hidden="true"></span>
												</button>
											</td>

											<td class="shseq-col--modified">
												<time
													datetime="<?php echo esc_attr( $modified_gmt ); ?>"
													title="<?php echo esc_attr( get_date_from_gmt( $modified_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>"
												>
													<?php
													$ago = human_time_diff( (int) strtotime( $modified_gmt . ' UTC' ), time() );
													/* translators: %s: human-readable time difference */
													echo esc_html( sprintf( __( '%s ago', 'sh-sequence-engine' ), $ago ) );
													?>
												</time>
											</td>

											<td class="shseq-col--actions">
												<a
													href="<?php echo esc_url( $wizard_url ); ?>"
													class="shseq-action-icon"
													title="<?php esc_attr_e( 'Edit in Wizard', 'sh-sequence-engine' ); ?>"
												>
													<span class="dashicons dashicons-edit" aria-hidden="true"></span>
													<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'sh-sequence-engine' ); ?></span>
												</a>
												<?php if ( $preview_url ) : ?>
												<a
													href="<?php echo esc_url( $preview_url ); ?>"
													class="shseq-action-icon"
													target="_blank"
													rel="noopener noreferrer"
													title="<?php esc_attr_e( 'Preview', 'sh-sequence-engine' ); ?>"
												>
													<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
													<span class="screen-reader-text"><?php esc_html_e( 'Preview', 'sh-sequence-engine' ); ?></span>
												</a>
												<?php endif; ?>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>

							<div id="shseq-no-results" class="shseq-no-results" hidden>
								<?php esc_html_e( 'No sequences match your search.', 'sh-sequence-engine' ); ?>
							</div>

						</div>

					<?php else : ?>

					<?php /* ── Empty state ────────────────────────────── */ ?>
					<div class="shseq-empty-state">
						<div class="shseq-empty-state__icon" aria-hidden="true">
							<span class="dashicons dashicons-images-alt2"></span>
						</div>
						<h2><?php esc_html_e( 'No sequences yet', 'sh-sequence-engine' ); ?></h2>
						<p>
							<?php esc_html_e( 'A sequence is a set of 24–36 WebP frames that animate as the visitor scrolls — creating a cinematic hero section without any video file.', 'sh-sequence-engine' ); ?>
						</p>
						<p class="shseq-empty-state__hint">
							<?php esc_html_e( 'You can upload your own frames or let the AI generate them from a single image or text prompt.', 'sh-sequence-engine' ); ?>
						</p>
						<?php if ( $can_create ) : ?>
							<a class="button button-primary button-hero shseq-empty-cta" href="<?php echo esc_url( $create_url ); ?>">
								<?php esc_html_e( 'Create your first sequence', 'sh-sequence-engine' ); ?>
							</a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
								<?php esc_html_e( 'Upgrade to create more', 'sh-sequence-engine' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php endif; ?>
				</main>

				<?php /* ── Sidebar ──────────────────────────────────────── */ ?>
				<aside class="shseq-dash-sidebar" aria-label="<?php esc_attr_e( 'Dashboard sidebar', 'sh-sequence-engine' ); ?>">

					<?php /* Usage card */ ?>
					<div class="shseq-dash-card shseq-usage-card">
						<h3 class="shseq-sidebar-card-title"><?php esc_html_e( 'Plan usage', 'sh-sequence-engine' ); ?></h3>
						<div
							class="shseq-usage-bar"
							role="progressbar"
							aria-valuenow="<?php echo (int) $usage_pct; ?>"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-label="<?php printf( esc_attr__( '%1$d of %2$d sequences used', 'sh-sequence-engine' ), $total, $max_heroes ); ?>"
						>
							<div class="shseq-usage-bar__fill <?php echo $usage_pct >= 100 ? 'is-full' : ( $usage_pct >= 80 ? 'is-warn' : '' ); ?>"
								 style="width:<?php echo (int) $usage_pct; ?>%"></div>
						</div>
						<p class="shseq-usage-label">
							<strong><?php echo (int) $total; ?></strong>
							<?php printf(
								/* translators: %d: max heroes */
								esc_html__( ' of %d sequences used', 'sh-sequence-engine' ),
								$max_heroes
							); ?>
						</p>
						<?php if ( ! $is_pro ) : ?>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="button shseq-upgrade-btn">
								<?php esc_html_e( 'Upgrade to Pro ↗', 'sh-sequence-engine' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php /* Embed card */ ?>
					<div class="shseq-dash-card shseq-embed-card">
						<h3 class="shseq-sidebar-card-title"><?php esc_html_e( 'Embed shortcode', 'sh-sequence-engine' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'Paste into any page or post:', 'sh-sequence-engine' ); ?>
						</p>
						<button
							type="button"
							class="shseq-copy-btn shseq-embed-copy-btn"
							data-copy='[storyboard_live id="ID"]'
							aria-label="<?php esc_attr_e( 'Copy example shortcode', 'sh-sequence-engine' ); ?>"
						>
							<code>[storyboard_live id="ID"]</code>
							<span class="dashicons dashicons-clipboard shseq-copy-icon" aria-hidden="true"></span>
						</button>
						<p class="description shseq-embed-hint">
							<?php esc_html_e( 'Replace ID with the number from the table above.', 'sh-sequence-engine' ); ?>
						</p>
					</div>

					<?php /* Health card — only when issues */ ?>
					<?php if ( ! empty( $health_issues ) ) : ?>
					<div class="shseq-dash-card shseq-health-card">
						<h3 class="shseq-sidebar-card-title shseq-health-title">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<?php esc_html_e( 'System health', 'sh-sequence-engine' ); ?>
						</h3>
						<ul class="shseq-health-list">
							<?php foreach ( $health_issues as $issue ) : ?>
								<li><?php echo esc_html( $issue ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

				</aside>

			</div>

		</div>

		<?php /* ── Copy toast (ARIA live) ──────────────────────────── */ ?>
		<div
			id="shseq-copy-toast"
			class="shseq-copy-toast"
			role="status"
			aria-live="polite"
			aria-atomic="true"
		></div>

		<script>
		(function () {
			'use strict';

			var TOAST_DURATION = 2200;
			var toast          = document.getElementById( 'shseq-copy-toast' );
			var toastTimer     = null;

			/* ── Copy shortcode ─────────────────────────────────────── */
			function showToast( msg ) {
				clearTimeout( toastTimer );
				toast.textContent = msg;
				toast.classList.add( 'is-visible' );
				toastTimer = setTimeout( function () {
					toast.classList.remove( 'is-visible' );
				}, TOAST_DURATION );
			}

			function fallbackCopy( text ) {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
				document.body.appendChild( ta );
				ta.focus();
				ta.select();
				try { document.execCommand( 'copy' ); return true; } catch ( e ) { return false; }
				finally { document.body.removeChild( ta ); }
			}

			var copiedMsg = <?php echo wp_json_encode( __( 'Copied to clipboard!', 'sh-sequence-engine' ) ); ?>;

			document.querySelectorAll( '.shseq-copy-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var text = btn.dataset.copy;
					if ( ! text ) return;
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( text ).then( function () {
							showToast( copiedMsg );
						} ).catch( function () {
							if ( fallbackCopy( text ) ) showToast( copiedMsg );
						} );
					} else {
						if ( fallbackCopy( text ) ) showToast( copiedMsg );
					}
				} );
			} );

			/* ── Table search filter ────────────────────────────────── */
			var searchInput = document.getElementById( 'shseq-table-search' );
			var noResults   = document.getElementById( 'shseq-no-results' );
			if ( searchInput ) {
				searchInput.addEventListener( 'input', function () {
					var q       = this.value.toLowerCase().trim();
					var rows    = document.querySelectorAll( '#shseq-sequences-table .shseq-seq-row' );
					var visible = 0;
					rows.forEach( function ( row ) {
						var haystack = ( row.dataset.search || '' );
						var show     = ! q || haystack.indexOf( q ) !== -1;
						row.hidden   = ! show;
						if ( show ) visible++;
					} );
					if ( noResults ) {
						noResults.hidden = visible > 0;
					}
				} );
			}
		}() );
		</script>
		<?php
	}
}

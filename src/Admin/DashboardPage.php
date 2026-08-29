<?php
/**
 * Plugin dashboard page — v5 Final
 *
 * Fixes:
 *  - Full fa_IR / English bilingual via fa() helper
 *  - "صفحات استفاده شده" column added
 *  - Edit button uses post_id + reads current wizard step from meta
 *  - "View all" links to AllSequencesPage slug
 *  - Usage sidebar: all Persian when fa_IR
 *  - Shortcode sidebar: all Persian when fa_IR
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;
use ShahreHonar\SequenceEngine\License\LicenseManager;

final class DashboardPage {

	// ── Bilingual helper ───────────────────────────────────────────────────

	private static ?bool $is_fa = null;

	private function is_fa(): bool {
		if ( self::$is_fa === null ) {
			self::$is_fa = ( strpos( get_locale(), 'fa' ) === 0 ) || is_rtl();
		}
		return self::$is_fa;
	}

	private function fa( string $fa, string $en ): string {
		return $this->is_fa() ? $fa : $en;
	}

	// ── Pages used helper ──────────────────────────────────────────────────

	private function count_pages_using( int $post_id ): int {
		$transient = 'shseq_pu_' . $post_id;
		$cached    = get_transient( $transient );
		if ( $cached !== false ) {
			return (int) $cached;
		}
		global $wpdb;
		$like  = $wpdb->esc_like( '[storyboard_live id="' . $post_id . '"' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_content LIKE %s",
				'%' . $like . '%'
			)
		);
		set_transient( $transient, $count, DAY_IN_SECONDS );
		return $count;
	}

	private function get_pages_using( int $post_id ): array {
		global $wpdb;
		$like  = $wpdb->esc_like( '[storyboard_live id="' . $post_id . '"' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_content LIKE %s
				 LIMIT 5",
				'%' . $like . '%'
			)
		);
		return $rows ?: array();
	}

	/** Render the dashboard. */
	public function render(): void {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html( $this->fa( 'دسترسی غیر مجاز.', 'You do not have permission to access this page.' ) ) );
		}

		// ── Counts ────────────────────────────────────────────────────
		$counts    = wp_count_posts( SequencePostType::POST_TYPE );
		$published = (int) ( $counts->publish ?? 0 );
		$drafts    = (int) ( $counts->draft   ?? 0 );
		$total     = 0;
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'future' ) as $s ) {
			$total += (int) ( $counts->$s ?? 0 );
		}

		$max_heroes = LicenseManager::max_heroes();
		$is_pro     = LicenseManager::is_pro();
		$can_create = LicenseManager::can_create_hero();
		$usage_pct  = $max_heroes > 0 ? min( 100, (int) round( ( $total / $max_heroes ) * 100 ) ) : 100;

		// ── Recent sequences ──────────────────────────────────────────
		$recent = get_posts( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => array( 'draft', 'publish', 'private', 'pending' ),
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		// ── System health ─────────────────────────────────────────────
		$health_issues = array();
		if ( ! function_exists( 'as_enqueue_async_action' ) && $is_pro ) {
			$health_issues[] = $this->fa(
				'Action Scheduler نصب نیست — ساخت فریم با هوش مصنوعی غیرفعال است.',
				'Action Scheduler not installed — AI frame generation unavailable.'
			);
		}
		if ( ! extension_loaded( 'gd' ) ) {
			$health_issues[] = $this->fa(
				'GD library بارگذاری نشده — پردازش فریم ممکن است با خطا مواجه شود.',
				'GD image library not loaded — frame processing may fail.'
			);
		}

		// ── Environment ───────────────────────────────────────────────
		$env      = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$show_env = ( $env !== 'production' );

		// ── URLs ──────────────────────────────────────────────────────
		$create_url   = admin_url( 'admin.php?page=' . SequenceWizardPage::PAGE_SLUG );
		$all_url      = admin_url( 'admin.php?page=' . AllSequencesPage::PAGE_SLUG );
		$settings_url = admin_url( 'admin.php?page=shseq-settings' );
		?>
		<div class="wrap shseq-admin shseq-dashboard" dir="<?php echo $this->is_fa() ? 'rtl' : 'ltr'; ?>">

			<?php /* ── Header ─────────────────────────────────────── */ ?>
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
							<?php echo esc_html( $this->fa( 'سکانس جدید', 'New Sequence' ) ); ?>
						</a>
					<?php else : ?>
						<span class="shseq-quota-chip">
							<?php printf(
								esc_html( $this->fa( '%1$d از %2$d سکانس استفاده شده', '%1$d / %2$d heroes used' ) ),
								$total,
								$max_heroes
							); ?>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="shseq-upgrade-link">
								<?php echo esc_html( $this->fa( 'ارتقا ↗', 'Upgrade ↗' ) ); ?>
							</a>
						</span>
					<?php endif; ?>
				</div>
			</header>

			<?php /* ── Stats ──────────────────────────────────────── */ ?>
			<div class="shseq-dash-stats" role="list">

				<div class="shseq-stat-card" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $total; ?></span>
					<span class="shseq-stat-card__label"><?php echo esc_html( $this->fa( 'کل سکانس‌ها', 'Total Sequences' ) ); ?></span>
					<div
						class="shseq-stat-bar"
						role="progressbar"
						aria-valuenow="<?php echo (int) $usage_pct; ?>"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-label="<?php printf( '%d / %d', $total, $max_heroes ); ?>"
					>
						<div class="shseq-stat-bar__fill <?php echo $usage_pct >= 100 ? 'is-full' : ( $usage_pct >= 80 ? 'is-warn' : '' ); ?>"
							style="width:<?php echo (int) $usage_pct; ?>%"></div>
					</div>
					<span class="shseq-stat-card__sub">
						<?php printf(
							esc_html( $this->fa( 'از %d مجاز', 'of %d allowed' ) ),
							$max_heroes
						); ?>
					</span>
				</div>

				<div class="shseq-stat-card shseq-stat-card--live" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $published; ?></span>
					<span class="shseq-stat-card__label"><?php echo esc_html( $this->fa( 'منتشرشده', 'Published' ) ); ?></span>
				</div>

				<div class="shseq-stat-card shseq-stat-card--draft" role="listitem">
					<span class="shseq-stat-card__value"><?php echo (int) $drafts; ?></span>
					<span class="shseq-stat-card__label"><?php echo esc_html( $this->fa( 'پیش‌نویس', 'Drafts' ) ); ?></span>
				</div>

			</div>

			<?php /* ── Two-column layout ─────────────────────────── */ ?>
			<div class="shseq-dash-layout">

				<?php /* ── Main ──────────────────────────────────── */ ?>
				<main class="shseq-dash-main">

					<?php if ( ! empty( $recent ) ) : ?>

						<div class="shseq-dash-card">

							<div class="shseq-dash-card__head">
								<div class="shseq-dash-card__head-left">
									<h2 class="shseq-dash-card__title">
										<?php echo esc_html( $this->fa( 'سکانس‌های اخیر', 'Recent Sequences' ) ); ?>
									</h2>
									<label for="shseq-table-search" class="screen-reader-text">
										<?php echo esc_html( $this->fa( 'جستجوی سکانس‌ها', 'Search sequences' ) ); ?>
									</label>
									<input
										type="search"
										id="shseq-table-search"
										class="shseq-table-search"
										placeholder="<?php echo esc_attr( $this->fa( 'فیلتر…', 'Filter…' ) ); ?>"
										autocomplete="off"
									>
								</div>
								<a href="<?php echo esc_url( $all_url ); ?>" class="button shseq-view-all-btn">
									<?php printf(
										esc_html( $this->fa( 'مشاهده همه (%d)', 'View all (%d)' ) ),
										$total
									); ?>
								</a>
							</div>

							<div class="shseq-table-wrap">
								<table class="shseq-seq-table" id="shseq-sequences-table">
									<thead>
										<tr>
											<th scope="col"><?php echo esc_html( $this->fa( 'نام', 'Name' ) ); ?></th>
											<th scope="col"><?php echo esc_html( $this->fa( 'وضعیت', 'Status' ) ); ?></th>
											<th scope="col"><?php echo esc_html( $this->fa( 'فریم‌ها', 'Frames' ) ); ?></th>
											<th scope="col" class="shseq-col--shortcode"><?php echo esc_html( $this->fa( 'شورتکد', 'Shortcode' ) ); ?></th>
											<th scope="col" class="shseq-col--pages"><?php echo esc_html( $this->fa( 'صفحات استفاده شده', 'Pages Used' ) ); ?></th>
											<th scope="col" class="shseq-col--modified"><?php echo esc_html( $this->fa( 'ویرایش‌شده', 'Modified' ) ); ?></th>
											<th scope="col"><?php echo esc_html( $this->fa( 'عملیات', 'Actions' ) ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent as $seq ) :
											$frame_count  = FrameManager::count( $seq->ID );
											$has_frames   = $frame_count > 0;
											// Read current wizard step from meta
											$current_step = (int) get_post_meta( $seq->ID, SequenceWizardPage::META_STEP, true );
											$current_step = $current_step > 0 ? $current_step : 1;
											$wizard_url   = add_query_arg(
												array( 'page' => SequenceWizardPage::PAGE_SLUG, 'post_id' => $seq->ID ),
												admin_url( 'admin.php' )
											);
											$preview_url  = SequencePreview::preview_url( $seq->ID );
											$status_obj   = get_post_status_object( $seq->post_status );
											$shortcode    = '[storyboard_live id="' . (int) $seq->ID . '"]';
											$seq_title    = get_the_title( $seq ) ?: $this->fa( '(بدون عنوان)', '(Untitled)' );
											$search_data  = strtolower( $seq_title . ' ' . $shortcode );
											$row_class    = ! $has_frames ? 'shseq-row--no-frames' : '';
											$modified_gmt = $seq->post_modified_gmt;
											$pages_count  = $this->count_pages_using( $seq->ID );
										?>
										<tr
											class="shseq-seq-row <?php echo esc_attr( $row_class ); ?>"
											data-search="<?php echo esc_attr( $search_data ); ?>"
										>
											<td class="shseq-col--name">
												<?php if ( ! $has_frames ) : ?>
													<span
														class="shseq-warn-icon"
														title="<?php echo esc_attr( $this->fa( 'بدون فریم — این سکانس در فرانت نمایش داده نخواهد شد', 'No frames — this sequence will not display on the frontend' ) ); ?>"
														aria-hidden="true"
													>⚠</span>
												<?php endif; ?>
												<a href="<?php echo esc_url( $wizard_url ); ?>" class="shseq-seq-name">
													<?php echo esc_html( $seq_title ); ?>
												</a>
											</td>

											<td>
												<span class="shseq-status-pill shseq-status-pill--<?php echo esc_attr( $seq->post_status ); ?>">
													<?php
													if ( $seq->post_status === 'publish' ) {
														echo esc_html( $this->fa( 'منتشرشده', 'Published' ) );
													} elseif ( $seq->post_status === 'draft' ) {
														echo esc_html( $this->fa( 'پیش‌نویس', 'Draft' ) );
													} elseif ( $seq->post_status === 'private' ) {
														echo esc_html( $this->fa( 'خصوصی', 'Private' ) );
													} else {
														echo esc_html( $status_obj ? $status_obj->label : $seq->post_status );
													}
													?>
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
													aria-label="<?php echo esc_attr( $this->fa( 'کپی شورتکد', 'Copy shortcode' ) ); ?>"
												>
													<code class="shseq-shortcode-text"><?php echo esc_html( $shortcode ); ?></code>
													<span class="dashicons dashicons-clipboard shseq-copy-icon" aria-hidden="true"></span>
												</button>
											</td>

											<td class="shseq-col--pages">
												<?php if ( $pages_count > 0 ) : ?>
													<button
														type="button"
														class="shseq-pages-used-btn"
														data-post-id="<?php echo (int) $seq->ID; ?>"
														aria-expanded="false"
													>
														<?php printf(
															esc_html( $this->fa( '%d صفحه', '%d page(s)' ) ),
															$pages_count
														); ?>
													</button>
												<?php else : ?>
													<span class="shseq-pages-used--none">—</span>
												<?php endif; ?>
											</td>

											<td class="shseq-col--modified">
												<time
													datetime="<?php echo esc_attr( $modified_gmt ); ?>"
													title="<?php echo esc_attr( get_date_from_gmt( $modified_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>"
												>
													<?php
													$ago = human_time_diff( (int) strtotime( $modified_gmt . ' UTC' ), time() );
													echo esc_html( sprintf( $this->fa( '%s پیش', '%s ago' ), $ago ) );
													?>
												</time>
											</td>

											<td class="shseq-col--actions">
												<a
													href="<?php echo esc_url( $wizard_url ); ?>"
													class="shseq-action-icon"
													title="<?php echo esc_attr( $this->fa( 'ویرایش در ویزارد', 'Edit in Wizard' ) ); ?>"
												>
													<span class="dashicons dashicons-edit" aria-hidden="true"></span>
													<span class="screen-reader-text"><?php echo esc_html( $this->fa( 'ویرایش', 'Edit' ) ); ?></span>
												</a>
												<?php if ( $preview_url ) : ?>
												<a
													href="<?php echo esc_url( $preview_url ); ?>"
													class="shseq-action-icon"
													target="_blank"
													rel="noopener noreferrer"
													title="<?php echo esc_attr( $this->fa( 'پیش‌نمایش', 'Preview' ) ); ?>"
												>
													<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
													<span class="screen-reader-text"><?php echo esc_html( $this->fa( 'پیش‌نمایش', 'Preview' ) ); ?></span>
												</a>
												<?php endif; ?>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>

							<div id="shseq-no-results" class="shseq-no-results" hidden>
								<?php echo esc_html( $this->fa( 'سکانسی با این جستجو یافت نشد.', 'No sequences match your search.' ) ); ?>
							</div>

						</div>

					<?php else : ?>

					<?php /* ── Empty state ────────────────────────── */ ?>
					<div class="shseq-empty-state">
						<div class="shseq-empty-state__icon" aria-hidden="true">
							<span class="dashicons dashicons-images-alt2"></span>
						</div>
						<h2><?php echo esc_html( $this->fa( 'هنوز سکانسی ایجاد نشده', 'No sequences yet' ) ); ?></h2>
						<p>
							<?php echo esc_html( $this->fa(
								'یک سکانس مجموعه‌ای از ۲۴ تا ۳۶ فریم WebP است که هنگام اسکرول کاربر انیمیت می‌شوند و یک هیرو سینماتیک بدون فایل ویدیو می‌سازند.',
								'A sequence is a set of 24–36 WebP frames that animate as the visitor scrolls — creating a cinematic hero section without any video file.'
							) ); ?>
						</p>
						<?php if ( $can_create ) : ?>
							<a class="button button-primary button-hero shseq-empty-cta" href="<?php echo esc_url( $create_url ); ?>">
								<?php echo esc_html( $this->fa( 'ساخت اولین سکانس', 'Create your first sequence' ) ); ?>
							</a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
								<?php echo esc_html( $this->fa( 'ارتقا برای ساخت بیشتر', 'Upgrade to create more' ) ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php endif; ?>
				</main>

				<?php /* ── Sidebar ──────────────────────────────────── */ ?>
				<aside class="shseq-dash-sidebar" aria-label="<?php echo esc_attr( $this->fa( 'سایدبار داشبورد', 'Dashboard sidebar' ) ); ?>">

					<?php /* Usage card */ ?>
					<div class="shseq-dash-card shseq-usage-card">
						<h3 class="shseq-sidebar-card-title"><?php echo esc_html( $this->fa( 'استفاده از پلن', 'Plan Usage' ) ); ?></h3>
						<div
							class="shseq-usage-bar"
							role="progressbar"
							aria-valuenow="<?php echo (int) $usage_pct; ?>"
							aria-valuemin="0"
							aria-valuemax="100"
						>
							<div class="shseq-usage-bar__fill <?php echo $usage_pct >= 100 ? 'is-full' : ( $usage_pct >= 80 ? 'is-warn' : '' ); ?>"
								style="width:<?php echo (int) $usage_pct; ?>%"></div>
						</div>
						<p class="shseq-usage-label">
							<strong><?php echo (int) $total; ?></strong>
							<?php printf(
								esc_html( $this->fa( 'از %d سکانس مجاز', 'of %d sequences used' ) ),
								$max_heroes
							); ?>
						</p>
						<?php if ( ! $is_pro ) : ?>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="button shseq-upgrade-btn">
								<?php echo esc_html( $this->fa( 'ارتقا به Pro ↗', 'Upgrade to Pro ↗' ) ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php /* Shortcode card */ ?>
					<div class="shseq-dash-card shseq-shortcode-card">
						<h3 class="shseq-sidebar-card-title"><?php echo esc_html( $this->fa( 'شورتکد جایگذاری', 'Embed Shortcode' ) ); ?></h3>
						<p class="shseq-shortcode-hint">
							<?php echo esc_html( $this->fa( 'در هر صفحه یا نوشته کپی کنید:', 'Paste into any page or post:' ) ); ?>
						</p>
						<div class="shseq-shortcode-sample">
							<span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
							<code>[storyboard_live id="ID"]</code>
						</div>
						<p class="shseq-shortcode-sub">
							<?php echo esc_html( $this->fa( 'ID را با شماره سکانس از جدول بالا جایگزین کنید.', 'Replace ID with the number from the table above.' ) ); ?>
						</p>
					</div>

					<?php /* Health card — only if issues */ ?>
					<?php if ( ! empty( $health_issues ) ) : ?>
					<div class="shseq-dash-card shseq-health-card" role="alert">
						<h3 class="shseq-sidebar-card-title shseq-health-card__title">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<?php echo esc_html( $this->fa( 'هشدارهای سیستم', 'System Warnings' ) ); ?>
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

			<?php /* ── Toast ─────────────────────────────────────── */ ?>
			<div
				id="shseq-toast"
				class="shseq-toast"
				role="status"
				aria-live="polite"
				aria-atomic="true"
				hidden
			></div>

		</div>

		<script>
		(function () {
			'use strict';
			var isFa = <?php echo $this->is_fa() ? 'true' : 'false'; ?>;

			// Search filter
			var searchInput = document.getElementById('shseq-table-search');
			var table       = document.getElementById('shseq-sequences-table');
			var noResults   = document.getElementById('shseq-no-results');
			if (searchInput && table) {
				searchInput.addEventListener('input', function () {
					var q = this.value.trim().toLowerCase();
					var rows = table.querySelectorAll('tbody .shseq-seq-row');
					var visible = 0;
					rows.forEach(function (r) {
						var match = !q || (r.dataset.search || '').indexOf(q) !== -1;
						r.hidden = !match;
						if (match) visible++;
					});
					if (noResults) noResults.hidden = (visible > 0);
				});
			}

			// Copy shortcode
			var toast = document.getElementById('shseq-toast');
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.shseq-copy-btn');
				if (!btn) return;
				var text = btn.dataset.copy;
				if (!text) return;
				navigator.clipboard.writeText(text).then(function () {
					showToast(isFa ? 'کپی شد!' : 'Copied!', 'success');
				}).catch(function () {
					showToast(isFa ? 'کپی ناموفق — Ctrl+C را فشار دهید' : 'Copy failed — press Ctrl+C', 'error');
				});
			});

			function showToast(msg, type) {
				if (!toast) return;
				toast.textContent = msg;
				toast.className = 'shseq-toast is-visible' + (type === 'error' ? ' is-error' : ' is-success');
				toast.removeAttribute('hidden');
				setTimeout(function () {
					toast.classList.remove('is-visible');
					setTimeout(function () { toast.setAttribute('hidden', ''); }, 300);
				}, 3000);
			}
		}());
		</script>
		<?php
	}
}

<?php
/**
 * All Sequences admin page — v4 Bilingual Final.
 *
 * Changes from v3:
 *  - Full fa_IR / English bilingual via fa() helper (EVERY string)
 *  - Fixed: "of X sequences Y" RTL-safe format
 *  - Fixed: all tab labels, table headers, bulk bar, actions → bilingual
 *  - Fixed: JS i18n via fa() not just __()
 *  - UI: improved empty state, cleaner toolbar
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;

final class AllSequencesPage {

	const PAGE_SLUG    = 'shseq-all-sequences';
	const NONCE_BULK   = 'shseq_bulk_action';
	const NONCE_RENAME = 'shseq_inline_rename';
	const PER_PAGE     = 20;

	// ── Bilingual ─────────────────────────────────────────────────────────

	private function is_fa(): bool {
		return is_rtl();
	}

	private function fa( string $fa, string $en ): string {
		return $this->is_fa() ? $fa : $en;
	}

	// ── Hooks ──────────────────────────────────────────────────────────────

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shseq_bulk_delete', array( $this, 'handle_bulk_delete' ) );
		add_action( 'wp_ajax_shseq_inline_rename', array( $this, 'handle_inline_rename' ) );
		add_action( 'wp_ajax_shseq_duplicate_sequence', array( $this, 'handle_duplicate' ) );
		add_action( 'save_post', array( $this, 'invalidate_pages_cache' ) );
		add_action( 'deleted_post', array( $this, 'invalidate_pages_cache' ) );
		add_action( 'trashed_post', array( $this, 'invalidate_pages_cache' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		$ver = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		wp_enqueue_style(
			'shseq-all-sequences',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/admin/all-sequences.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'shseq-all-sequences',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/admin/all-sequences.js',
			array(),
			$ver,
			true
		);
		wp_localize_script( 'shseq-all-sequences', 'shseqSeq', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonceRename'    => wp_create_nonce( self::NONCE_RENAME ),
			'nonceDuplicate' => wp_create_nonce( 'shseq_duplicate' ),
			'i18n'           => array(
				'copied'        => $this->fa( 'کپی شد!', 'Copied!' ),
				'copyFail'      => $this->fa( 'کپی ناموفق — Ctrl+C بزنید', 'Copy failed — press Ctrl+C' ),
				'confirmDelete' => $this->fa( 'سکانس‌های انتخاب‌شده برای همیشه حذف شوند؟ این عمل قابل بازگشت نیست.', 'Delete selected sequences permanently? This cannot be undone.' ),
				'renameEmpty'   => $this->fa( 'نام سکانس نمی‌تواند خالی باشد.', 'Sequence name cannot be empty.' ),
				'renameDupe'    => $this->fa( 'سکانسی با این نام وجود دارد. نام دیگری انتخاب کنید.', 'A sequence with this name already exists.' ),
				'renameSaved'   => $this->fa( 'نام ذخیره شد.', 'Name saved.' ),
				'noResults'     => $this->fa( 'سکانسی با این جستجو یافت نشد.', 'No sequences match your search.' ),
				'resultCount'   => $this->fa( '%d سکانس نمایش داده شده.', '%d sequence(s) shown.' ),
			),
		) );
	}

	// ── Render ─────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html( $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ) );
		}

		// ── Query params ─────────────────────────────────────────────────
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'modified';
		$order   = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$valid_orderby = array( 'title', 'post_status', 'modified', 'date' );
		if ( ! in_array( $orderby, $valid_orderby, true ) ) {
			$orderby = 'modified';
		}

		// ── Count per status ──────────────────────────────────────────────
		$counts_obj = wp_count_posts( SequencePostType::POST_TYPE );
		$counts     = array(
			'all'     => 0,
			'publish' => (int) ( $counts_obj->publish ?? 0 ),
			'draft'   => (int) ( $counts_obj->draft   ?? 0 ),
			'private' => (int) ( $counts_obj->private ?? 0 ),
			'trash'   => (int) ( $counts_obj->trash   ?? 0 ),
		);
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'future' ) as $s ) {
			$counts['all'] += (int) ( $counts_obj->$s ?? 0 );
		}

		// ── Query ─────────────────────────────────────────────────────────
		$post_status_query = $status === 'all'
			? array( 'publish', 'draft', 'private', 'pending', 'future' )
			: array( $status );

		$query = new \WP_Query( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => $post_status_query,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		) );
		$posts = $query->posts;
		$total = $query->found_posts;
		$pages = $query->max_num_pages;

		// ── Pages used map ────────────────────────────────────────────────
		$pages_used_map = $this->get_pages_used_map();

		// ── URLs ──────────────────────────────────────────────────────────
		$base_url   = add_query_arg( array_filter( array(
			'page'    => self::PAGE_SLUG,
			'status'  => $status !== 'all' ? $status : null,
			'orderby' => $orderby !== 'modified' ? $orderby : null,
			'order'   => $order !== 'DESC' ? $order : null,
		) ), admin_url( 'admin.php' ) );
		$create_url = admin_url( 'admin.php?page=' . SequenceWizardPage::PAGE_SLUG );

		// ── Tab labels ────────────────────────────────────────────────────
		$tabs = array(
			'all'     => $this->fa( 'همه',         'All' ),
			'publish' => $this->fa( 'منتشرشده',    'Published' ),
			'draft'   => $this->fa( 'پیشنویس‌ها',  'Drafts' ),
			'private' => $this->fa( 'خصوصی',       'Private' ),
			'trash'   => $this->fa( 'سطل زباله',   'Trash' ),
		);
		?>
		<div class="wrap shseq-admin shseq-all-sequences" dir="<?php echo $this->is_fa() ? 'rtl' : 'ltr'; ?>">

			<?php /* ── Header ─────────────────────────────────────── */ ?>
			<div class="shseq-seqlist-header">
				<div class="shseq-seqlist-header__text">
					<h1 class="shseq-seqlist-title">
						<?php echo esc_html( $this->fa( 'همه سکانس‌ها', 'All Sequences' ) ); ?>
					</h1>
					<p class="shseq-seqlist-header__desc">
						<?php echo esc_html( $this->fa(
							'مدیریت تمام سکانس‌های اسکرول‌محور خود در یک مکان.',
							'Manage all your scroll-driven sequences in one place.'
						) ); ?>
					</p>
				</div>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary shseq-new-btn">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php echo esc_html( $this->fa( 'سکانس جدید', 'New Sequence' ) ); ?>
				</a>
			</div>

			<?php /* ── Status tabs ─────────────────────────────────── */ ?>
			<nav class="shseq-status-tabs" role="tablist"
			     aria-label="<?php echo esc_attr( $this->fa( 'فیلتر بر اساس وضعیت', 'Filter by status' ) ); ?>">
				<?php foreach ( $tabs as $tab_status => $tab_label ) :
					$tab_count = $counts[ $tab_status ] ?? 0;
					if ( $tab_count === 0 && $tab_status !== 'all' && $tab_status !== $status ) {
						continue;
					}
					$is_active = ( $status === $tab_status );
					$tab_url   = add_query_arg( array_filter( array(
						'page'    => self::PAGE_SLUG,
						'status'  => $tab_status !== 'all' ? $tab_status : null,
						'orderby' => $orderby !== 'modified' ? $orderby : null,
						'order'   => $order !== 'DESC' ? $order : null,
					) ), admin_url( 'admin.php' ) );
					?>
					<a
						href="<?php echo esc_url( $tab_url ); ?>"
						class="shseq-status-tab <?php echo $is_active ? 'shseq-status-tab--active' : ''; ?>"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					>
						<?php echo esc_html( $tab_label ); ?>
						<span class="shseq-tab-count"><?php echo (int) $tab_count; ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php /* ── Toolbar ─────────────────────────────────────── */ ?>
			<div class="shseq-seqlist-toolbar">
				<div class="shseq-seqlist-toolbar__left">
					<div class="shseq-search-wrap" role="search">
						<label for="shseq-seq-search" class="screen-reader-text">
							<?php echo esc_html( $this->fa( 'جستجوی سکانس‌ها', 'Search sequences' ) ); ?>
						</label>
						<span class="dashicons dashicons-search shseq-search-icon" aria-hidden="true"></span>
						<input
							type="search"
							id="shseq-seq-search"
							class="shseq-seq-search"
							placeholder="<?php echo esc_attr( $this->fa( 'جستجو با نام یا شورتکد…', 'Search by name or shortcode…' ) ); ?>"
							aria-controls="shseq-seq-table"
							autocomplete="off"
						>
					</div>
				</div>
				<div class="shseq-seqlist-toolbar__right">
					<span class="shseq-result-count" aria-live="polite" aria-atomic="true">
						<?php printf(
							esc_html( $this->fa( 'نمایش %1$d از %2$d سکانس', '%1$d of %2$d sequences' ) ),
							count( $posts ),
							$total
						); ?>
					</span>
				</div>
			</div>

			<?php /* ── Bulk form ───────────────────────────────────── */ ?>
			<form id="shseq-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shseq_bulk_delete">
				<?php wp_nonce_field( self::NONCE_BULK, 'shseq_nonce' ); ?>
				<input type="hidden" name="status_redirect" value="<?php echo esc_attr( $status ); ?>">

				<?php /* ── Bulk action bar ─────────────────────────── */ ?>
				<div class="shseq-bulk-bar" id="shseq-bulk-bar" hidden aria-live="polite">
					<span class="shseq-bulk-bar__count" id="shseq-bulk-count">
						0 <?php echo esc_html( $this->fa( 'انتخاب‌شده', 'selected' ) ); ?>
					</span>
					<button type="submit" class="button shseq-bulk-delete-btn" id="shseq-bulk-delete-btn">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php echo esc_html( $this->fa( 'حذف انتخاب‌شده‌ها', 'Delete selected' ) ); ?>
					</button>
					<button type="button" class="button shseq-bulk-cancel-btn" id="shseq-bulk-cancel">
						<?php echo esc_html( $this->fa( 'لغو', 'Cancel' ) ); ?>
					</button>
				</div>

				<?php /* ── Table ──────────────────────────────────── */ ?>
				<?php if ( ! empty( $posts ) ) : ?>
				<div class="shseq-table-wrap" role="region"
				     aria-label="<?php echo esc_attr( $this->fa( 'فهرست سکانس‌ها', 'Sequences list' ) ); ?>">
					<table
						id="shseq-seq-table"
						class="shseq-seq-table wp-list-table widefat fixed striped"
						role="grid"
					>
						<thead>
							<tr>
								<th scope="col" class="shseq-col--cb check-column">
									<label for="shseq-select-all" class="screen-reader-text">
										<?php echo esc_html( $this->fa( 'انتخاب همه', 'Select all' ) ); ?>
									</label>
									<input type="checkbox" id="shseq-select-all"
									       aria-label="<?php echo esc_attr( $this->fa( 'انتخاب همه سکانس‌ها', 'Select all sequences' ) ); ?>">
								</th>
								<?php echo $this->sortable_th(
									$this->fa( 'نام', 'Name' ),
									'title', $orderby, $order, $base_url, 'shseq-col--name'
								); ?>
								<?php echo $this->sortable_th(
									$this->fa( 'وضعیت', 'Status' ),
									'post_status', $orderby, $order, $base_url, 'shseq-col--status'
								); ?>
								<?php echo $this->sortable_th(
									$this->fa( 'فریم‌ها', 'Frames' ),
									'frames', $orderby, $order, $base_url, 'shseq-col--frames'
								); ?>
								<th scope="col" class="shseq-col--shortcode">
									<?php echo esc_html( $this->fa( 'شورتکد', 'Shortcode' ) ); ?>
								</th>
								<th scope="col" class="shseq-col--pages">
									<?php echo esc_html( $this->fa( 'صفحات استفاده‌شده', 'Pages Used' ) ); ?>
								</th>
								<?php echo $this->sortable_th(
									$this->fa( 'نویسنده و تاریخ', 'Author & Modified' ),
									'modified', $orderby, $order, $base_url, 'shseq-col--author'
								); ?>
								<th scope="col" class="shseq-col--actions">
									<?php echo esc_html( $this->fa( 'عملیات', 'Actions' ) ); ?>
								</th>
							</tr>
						</thead>

						<tbody>
							<?php
							$today     = current_time( 'Y-m-d' );
							$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

							foreach ( $posts as $seq ) :
								$post_id     = $seq->ID;
								$frame_count = FrameManager::count( $post_id );
								$has_frames  = $frame_count > 0;
								$seq_title   = get_the_title( $seq ) ?: $this->fa( '(بدون عنوان)', '(Untitled)' );
								$shortcode   = '[storyboard_live id="' . (int) $post_id . '"]';
								$status_obj  = get_post_status_object( $seq->post_status );
								$author_id   = (int) $seq->post_author;
								$author_name = get_the_author_meta( 'display_name', $author_id );
								$avatar      = get_avatar( $author_id, 24, '', $author_name, array( 'class' => 'shseq-avatar' ) );
								$modified_gmt   = $seq->post_modified_gmt;
								$modified_ts    = (int) strtotime( $modified_gmt . ' UTC' );
								$modified_local = get_date_from_gmt( $modified_gmt, 'Y-m-d H:i' );
								$modified_date  = substr( $modified_local, 0, 10 );
								$modified_disp  = ( $modified_date === $today )
									? $this->fa( 'امروز', 'Today' )
									: ( $modified_date === $yesterday
										? $this->fa( 'دیروز', 'Yesterday' )
										: get_date_from_gmt( $modified_gmt, get_option( 'date_format' ) ) );
								$modified_time  = get_date_from_gmt( $modified_gmt, get_option( 'time_format' ) );

								// Pages used
								$used_pages = $pages_used_map[ $post_id ] ?? array();
								$pages_count = count( $used_pages );

								// Edit URL (wizard at current step)
								$current_step = (int) get_post_meta( $post_id, SequenceWizardPage::META_STEP, true );
								$current_step = $current_step > 0 ? $current_step : 1;
								$edit_url = add_query_arg(
									array( 'page' => SequenceWizardPage::PAGE_SLUG, 'post_id' => $post_id ),
									admin_url( 'admin.php' )
								);
								$preview_url = SequencePreview::preview_url( $post_id );

								// Status label
								$status_label = match( $seq->post_status ) {
									'publish' => $this->fa( 'منتشرشده', 'Published' ),
									'draft'   => $this->fa( 'پیشنویس',  'Draft' ),
									'private' => $this->fa( 'خصوصی',    'Private' ),
									'trash'   => $this->fa( 'سطل زباله','Trash' ),
									default   => $status_obj ? $status_obj->label : $seq->post_status,
								};

								$row_class  = ! $has_frames ? 'shseq-row--no-frames' : '';
								$search_key = strtolower( $seq_title . ' ' . $shortcode );
							?>
							<tr
								class="shseq-seq-row <?php echo esc_attr( $row_class ); ?>"
								data-search="<?php echo esc_attr( $search_key ); ?>"
							>
								<td class="shseq-col--cb check-column">
									<input
										type="checkbox"
										name="post_ids[]"
										value="<?php echo (int) $post_id; ?>"
										aria-label="<?php echo esc_attr( sprintf(
											$this->fa( 'انتخاب %s', 'Select %s' ),
											$seq_title
										) ); ?>"
									>
								</td>

								<td class="shseq-col--name">
									<?php if ( ! $has_frames ) : ?>
										<span
											class="shseq-warn-icon"
											title="<?php echo esc_attr( $this->fa(
												'بدون فریم — در فرانت نمایش داده نمی‌شود',
												'No frames — will not display on frontend'
											) ); ?>"
											aria-hidden="true"
										>⚠</span>
									<?php endif; ?>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="shseq-seq-name">
										<?php echo esc_html( $seq_title ); ?>
									</a>
									<div class="shseq-row-actions">
										<a href="<?php echo esc_url( $edit_url ); ?>" title="<?php echo esc_attr( $this->fa( 'ویرایش', 'Edit' ) ); ?>">
											<?php echo esc_html( $this->fa( 'ویرایش', 'Edit' ) ); ?>
										</a>
										<?php if ( $preview_url ) : ?>
											<span class="sep"> | </span>
											<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener"
											   title="<?php echo esc_attr( $this->fa( 'پیشنمایش', 'Preview' ) ); ?>">
												<?php echo esc_html( $this->fa( 'پیشنمایش', 'Preview' ) ); ?>
											</a>
										<?php endif; ?>
										<span class="sep"> | </span>
										<button type="button" class="shseq-inline-duplicate button-link"
										        data-post-id="<?php echo (int) $post_id; ?>"
										        title="<?php echo esc_attr( $this->fa( 'تکثیر', 'Duplicate' ) ); ?>">
											<?php echo esc_html( $this->fa( 'تکثیر', 'Duplicate' ) ); ?>
										</button>
										<span class="sep"> | </span>
										<a href="<?php echo esc_url( get_delete_post_link( $post_id ) ); ?>"
										   class="shseq-trash-link"
										   title="<?php echo esc_attr( $this->fa( 'سطل زباله', 'Trash' ) ); ?>">
											<?php echo esc_html( $this->fa( 'سطل زباله', 'Trash' ) ); ?>
										</a>
									</div>
								</td>

								<td class="shseq-col--status">
									<span class="shseq-status-pill shseq-status-pill--<?php echo esc_attr( $seq->post_status ); ?>">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>

								<td class="shseq-col--frames">
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
										<span class="shseq-pages-count" title="<?php
											$titles = array_map( fn($p) => get_the_title($p->ID), $used_pages );
											echo esc_attr( implode( ', ', $titles ) );
										?>">
											<?php printf(
												esc_html( $this->fa( '%d صفحه', '%d page(s)' ) ),
												$pages_count
											); ?>
										</span>
									<?php else : ?>
										<span class="shseq-pages-none">—</span>
									<?php endif; ?>
								</td>

								<td class="shseq-col--author">
									<div class="shseq-author-cell">
										<?php echo wp_kses_post( $avatar ); ?>
										<div class="shseq-author-meta">
											<span class="shseq-author-name"><?php echo esc_html( $author_name ); ?></span>
											<time
												class="shseq-modified-time"
												datetime="<?php echo esc_attr( $modified_gmt ); ?>"
												title="<?php echo esc_attr( $modified_disp . ' ' . $modified_time ); ?>"
											>
												<?php echo esc_html( $modified_disp . ' ' . $modified_time ); ?>
											</time>
										</div>
									</div>
								</td>

								<td class="shseq-col--actions">
									<a
										href="<?php echo esc_url( $edit_url ); ?>"
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
										title="<?php echo esc_attr( $this->fa( 'پیشنمایش', 'Preview' ) ); ?>"
									>
										<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php echo esc_html( $this->fa( 'پیشنمایش', 'Preview' ) ); ?></span>
									</a>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php /* ── Pagination ─────────────────────────────── */ ?>
				<?php if ( $pages > 1 ) : ?>
				<div class="shseq-pagination" aria-label="<?php echo esc_attr( $this->fa( 'صفحه‌بندی', 'Pagination' ) ); ?>">
					<?php for ( $p = 1; $p <= $pages; $p++ ) :
						$page_url = add_query_arg( 'paged', $p, $base_url );
						?>
						<a
							href="<?php echo esc_url( $page_url ); ?>"
							class="shseq-page-link <?php echo $p === $paged ? 'is-current' : ''; ?>"
							aria-current="<?php echo $p === $paged ? 'page' : 'false'; ?>"
						><?php echo (int) $p; ?></a>
					<?php endfor; ?>
				</div>
				<?php endif; ?>

				<?php else : ?>
				<?php /* ── Empty state ──────────────────────────────── */ ?>
				<div class="shseq-empty-state">
					<div class="shseq-empty-state__icon">
						<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
					</div>
					<h2><?php echo esc_html( $this->fa( 'هنوز سکانسی وجود ندارد', 'No sequences yet' ) ); ?></h2>
					<p><?php echo esc_html( $this->fa(
						'اولین سکانس اسکرول‌محور خود را بسازید.',
						'Create your first scroll-driven hero sequence.'
					) ); ?></p>
					<div class="shseq-empty-cta">
						<a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
							<?php echo esc_html( $this->fa( 'سکانس جدید', 'New Sequence' ) ); ?>
						</a>
					</div>
				</div>
				<?php endif; ?>

			</form>

			<?php /* ── SR live region ──────────────────────────── */ ?>
			<div id="shseq-seq-sr" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>

			<?php /* ── Toast ──────────────────────────────────── */ ?>
			<div id="shseq-seq-toast" class="shseq-copy-toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>

		</div>
		<?php
	}

	// ── Sortable TH ────────────────────────────────────────────────────────

	private function sortable_th(
		string $label,
		string $col,
		string $current_orderby,
		string $current_order,
		string $base_url,
		string $class = ''
	): string {
		$is_current = $current_orderby === $col;
		$next_order = ( $is_current && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
		$arrow      = $is_current ? ( $current_order === 'ASC' ? ' ▲' : ' ▼' ) : '';
		$url        = add_query_arg( array( 'orderby' => $col, 'order' => $next_order ), $base_url );
		$classes    = array_filter( array( $class, $is_current ? 'sorted' : 'sortable' ) );
		return sprintf(
			'<th scope="col" class="%s"><a href="%s">%s%s</a></th>',
			esc_attr( implode( ' ', $classes ) ),
			esc_url( $url ),
			esc_html( $label ),
			esc_html( $arrow )
		);
	}

	// ── Pages used helpers ──────────────────────────────────────────────────

	private function get_pages_used_map(): array {
		global $wpdb;
		$map  = array();
		// Get all published sequences' IDs
		$ids  = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE post_type = '" . SequencePostType::POST_TYPE . "'
			    AND post_status NOT IN ('trash','auto-draft')"
		);
		if ( empty( $ids ) ) {
			return $map;
		}
		foreach ( $ids as $sid ) {
			$sid  = (int) $sid;
			$like = $wpdb->esc_like( '[storyboard_live id="' . $sid . '"' );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				  WHERE post_status NOT IN ('trash','auto-draft')
				    AND post_content LIKE %s
				  LIMIT 5",
				'%' . $like . '%'
			) );
			if ( $rows ) {
				$map[ $sid ] = $rows;
			}
		}
		return $map;
	}

	public function invalidate_pages_cache(): void {
		// Simple: just use fresh queries (no persistent cache)
	}

	// ── AJAX handlers ───────────────────────────────────────────────────────

	public function handle_bulk_delete(): void {
		check_admin_referer( self::NONCE_BULK, 'shseq_nonce' );
		if ( ! current_user_can( 'delete_shseq_sequences' ) ) {
			wp_die( esc_html( $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ) );
		}
		$ids    = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
		$status = sanitize_key( $_POST['status_redirect'] ?? 'all' );
		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				wp_delete_post( $id, true );
			}
		}
		$redirect = add_query_arg( array_filter( array(
			'page'    => self::PAGE_SLUG,
			'status'  => $status !== 'all' ? $status : null,
			'deleted' => count( $ids ),
		) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_inline_rename(): void {
		check_ajax_referer( self::NONCE_RENAME, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ), 403 );
		}
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$new_name = sanitize_text_field( $_POST['new_name'] ?? '' );
		if ( ! $post_id || ! $new_name ) {
			wp_send_json_error( array( 'message' => $this->fa( 'داده‌های نامعتبر.', 'Invalid data.' ) ), 400 );
		}
		// Uniqueness check
		$existing = get_page_by_title( $new_name, OBJECT, SequencePostType::POST_TYPE );
		if ( $existing && (int) $existing->ID !== $post_id ) {
			wp_send_json_error( array(
				'message' => $this->fa( 'سکانسی با این نام وجود دارد.', 'A sequence with this name already exists.' ),
				'code'    => 'duplicate',
			) );
		}
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_name ) );
		wp_send_json_success( array( 'message' => $this->fa( 'نام ذخیره شد.', 'Name saved.' ) ) );
	}

	public function handle_duplicate(): void {
		check_ajax_referer( 'shseq_duplicate', 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ), 403 );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => $this->fa( 'سکانس نامعتبر.', 'Invalid sequence.' ) ), 400 );
		}
		$original = get_post( $post_id );
		if ( ! $original ) {
			wp_send_json_error( array( 'message' => $this->fa( 'سکانس یافت نشد.', 'Sequence not found.' ) ), 404 );
		}
		$new_id = wp_insert_post( array(
			'post_title'   => $original->post_title . ' ' . $this->fa( '(کپی)', '(Copy)' ),
			'post_type'    => SequencePostType::POST_TYPE,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_content' => $original->post_content,
		) );
		if ( ! $new_id || is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => $this->fa( 'خطا در تکثیر سکانس.', 'Failed to duplicate sequence.' ) ) );
		}
		// Copy meta
		$meta = get_post_meta( $post_id );
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( $key, '_shseq_' ) ) {
				foreach ( $values as $val ) {
					add_post_meta( $new_id, $key, maybe_unserialize( $val ) );
				}
			}
		}
		$edit_url = add_query_arg(
			array( 'page' => SequenceWizardPage::PAGE_SLUG, 'post_id' => $new_id ),
			admin_url( 'admin.php' )
		);
		wp_send_json_success( array(
			'message' => $this->fa( 'سکانس تکثیر شد.', 'Sequence duplicated.' ),
			'editUrl' => $edit_url,
			'postId'  => $new_id,
		) );
	}
}

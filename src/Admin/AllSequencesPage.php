<?php
/**
 * All Sequences admin page — Loop 3 Final (v3).
 *
 * 7 columns: Name | Status | Frames | Shortcode | Pages Used | Author & Modified | Actions
 *
 * Features:
 *  - Status filter tabs (All / Published / Draft / Private) with count badges
 *  - Live JS search (debounced, no page reload)
 *  - Sortable columns: name, status, frames, modified (URL-based, preserves filters)
 *  - Pagination (20 per page by default)
 *  - Bulk actions: Delete selected (with nonce + accessible confirm dialog)
 *  - "صفحات استفاده شده" via transient-cached shortcode scan, invalidated on post save
 *  - Author avatar (24px) + relative time with tooltip
 *  - Copyable shortcode with ARIA toast
 *  - Row hover actions: Edit | Preview | Duplicate | Trash
 *  - 0-frame warning row background + ⚠ icon
 *  - Unique title validation on inline rename (AJAX)
 *  - External CSS + JS (no inline styles)
 *  - Full i18n, RTL-safe, mobile-responsive (hide صفحات + modified on small screens)
 *  - ARIA throughout: live region for search results, role=status, role=alert
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Frames\FrameManager;

final class AllSequencesPage {

	const PAGE_SLUG      = 'shseq-all-sequences';
	const NONCE_BULK     = 'shseq_bulk_action';
	const NONCE_RENAME   = 'shseq_inline_rename';
	const PER_PAGE       = 20;
	const CACHE_GROUP    = 'shseq_pages_used';
	const CACHE_TTL      = DAY_IN_SECONDS;

	// ── Hooks ──────────────────────────────────────────────────────────────

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shseq_bulk_delete', array( $this, 'handle_bulk_delete' ) );
		add_action( 'wp_ajax_shseq_inline_rename', array( $this, 'handle_inline_rename' ) );
		add_action( 'wp_ajax_shseq_duplicate_sequence', array( $this, 'handle_duplicate' ) );
		// Invalidate "pages used" cache when any post is saved/deleted
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
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonceRename'   => wp_create_nonce( self::NONCE_RENAME ),
			'nonceDuplicate'=> wp_create_nonce( 'shseq_duplicate' ),
			'i18n'          => array(
				'copied'        => __( 'Copied!', 'sh-sequence-engine' ),
				'copyFail'      => __( 'Copy failed — press Ctrl+C', 'sh-sequence-engine' ),
				'confirmDelete' => __( 'Delete the selected sequences permanently? This cannot be undone.', 'sh-sequence-engine' ),
				'renameEmpty'   => __( 'Sequence name cannot be empty.', 'sh-sequence-engine' ),
				'renameDupe'    => __( 'A sequence with this name already exists. Please choose a different name.', 'sh-sequence-engine' ),
				'renameSaved'   => __( 'Name saved.', 'sh-sequence-engine' ),
				'noResults'     => __( 'No sequences match your search.', 'sh-sequence-engine' ),
				'resultCount'   => __( '%d sequence(s) shown.', 'sh-sequence-engine' ),
			),
		) );
	}

	// ── Render ─────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}

		// ── Query params ────────────────────────────────────────────────────
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'modified';
		$order    = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$author_f = isset( $_GET['author'] ) ? (int) $_GET['author'] : 0;

		$valid_orderby = array( 'title', 'post_status', 'modified', 'date' );
		if ( ! in_array( $orderby, $valid_orderby, true ) ) {
			$orderby = 'modified';
		}

		// ── Count per status ────────────────────────────────────────────────
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

		// ── Query ───────────────────────────────────────────────────────────
		$post_status_query = $status === 'all' ? array( 'publish', 'draft', 'private', 'pending', 'future' ) : array( $status );
		$query_args = array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => $post_status_query,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		);
		if ( $author_f ) {
			$query_args['author'] = $author_f;
		}

		$query    = new \WP_Query( $query_args );
		$posts    = $query->posts;
		$total    = $query->found_posts;
		$pages    = $query->max_num_pages;

		// ── Author list for filter ───────────────────────────────────────────
		$authors = $this->get_sequence_authors();

		// ── Pages used map (cached) ──────────────────────────────────────────
		$pages_used_map = $this->get_pages_used_map();

		// ── URL base ────────────────────────────────────────────────────────
		$base_url = add_query_arg( array_filter( array(
			'page'    => self::PAGE_SLUG,
			'status'  => $status !== 'all' ? $status : null,
			'orderby' => $orderby !== 'modified' ? $orderby : null,
			'order'   => $order !== 'DESC' ? $order : null,
			'author'  => $author_f ?: null,
		) ), admin_url( 'admin.php' ) );

		// ── Create URL ──────────────────────────────────────────────────────
		$create_url  = admin_url( 'admin.php?page=' . SequenceWizardPage::PAGE_SLUG );
		?>
		<div class="wrap shseq-admin shseq-all-sequences" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

			<!-- ── Page header ── -->
			<div class="shseq-seqlist-header">
				<div>
					<h1><?php esc_html_e( 'All Sequences', 'sh-sequence-engine' ); ?></h1>
					<p class="shseq-seqlist-header__desc">
						<?php esc_html_e( 'Manage all your scroll-driven sequences in one place.', 'sh-sequence-engine' ); ?>
					</p>
				</div>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary shseq-new-btn">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'New Sequence', 'sh-sequence-engine' ); ?>
				</a>
			</div>

			<!-- ── Status tabs ── -->
			<nav class="shseq-status-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter by status', 'sh-sequence-engine' ); ?>">
				<?php foreach ( array(
					'all'     => __( 'All', 'sh-sequence-engine' ),
					'publish' => __( 'Published', 'sh-sequence-engine' ),
					'draft'   => __( 'Drafts', 'sh-sequence-engine' ),
					'private' => __( 'Private', 'sh-sequence-engine' ),
					'trash'   => __( 'Trash', 'sh-sequence-engine' ),
				) as $tab_status => $tab_label ) :
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
						'author'  => $author_f ?: null,
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

			<!-- ── Toolbar ── -->
			<div class="shseq-seqlist-toolbar">
				<div class="shseq-seqlist-toolbar__left">
					<!-- Search -->
					<div class="shseq-search-wrap" role="search">
						<label for="shseq-seq-search" class="screen-reader-text">
							<?php esc_html_e( 'Search sequences', 'sh-sequence-engine' ); ?>
						</label>
						<input
							type="search"
							id="shseq-seq-search"
							class="shseq-seq-search"
							placeholder="<?php esc_attr_e( 'Search by name or shortcode…', 'sh-sequence-engine' ); ?>"
							aria-controls="shseq-seq-table"
							autocomplete="off"
						>
						<span class="dashicons dashicons-search shseq-search-icon" aria-hidden="true"></span>
					</div>

					<!-- Author filter -->
					<?php if ( count( $authors ) > 1 ) : ?>
					<div class="shseq-author-filter">
						<label for="shseq-author-filter" class="screen-reader-text">
							<?php esc_html_e( 'Filter by author', 'sh-sequence-engine' ); ?>
						</label>
						<select id="shseq-author-filter" class="shseq-author-select" onchange="window.location=this.value">
							<option value="<?php echo esc_url( remove_query_arg( 'author', $base_url ) ); ?>" <?php selected( ! $author_f ); ?>>
								<?php esc_html_e( 'All authors', 'sh-sequence-engine' ); ?>
							</option>
							<?php foreach ( $authors as $au_id => $au_name ) : ?>
								<option
									value="<?php echo esc_url( add_query_arg( 'author', $au_id, remove_query_arg( array( 'author', 'paged' ), $base_url ) ) ); ?>"
									<?php selected( $author_f, $au_id ); ?>
								>
									<?php echo esc_html( $au_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php endif; ?>
				</div>

				<div class="shseq-seqlist-toolbar__right">
					<span class="shseq-result-count" aria-live="polite" aria-atomic="true">
						<?php printf(
							/* translators: 1: shown count, 2: total count */
							esc_html__( '%1$d of %2$d sequences', 'sh-sequence-engine' ),
							count( $posts ),
							$total
						); ?>
					</span>
				</div>
			</div>

			<!-- ── Bulk delete form ── -->
			<form id="shseq-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shseq_bulk_delete">
				<?php wp_nonce_field( self::NONCE_BULK, 'shseq_nonce' ); ?>
				<input type="hidden" name="status_redirect" value="<?php echo esc_attr( $status ); ?>">

				<!-- Bulk action bar (visible when items are checked) -->
				<div class="shseq-bulk-bar" id="shseq-bulk-bar" hidden aria-live="polite">
					<span class="shseq-bulk-bar__count" id="shseq-bulk-count">0 <?php esc_html_e( 'selected', 'sh-sequence-engine' ); ?></span>
					<button type="submit" class="button shseq-bulk-delete-btn" id="shseq-bulk-delete-btn">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Delete selected', 'sh-sequence-engine' ); ?>
					</button>
					<button type="button" class="button shseq-bulk-cancel-btn" id="shseq-bulk-cancel">
						<?php esc_html_e( 'Cancel', 'sh-sequence-engine' ); ?>
					</button>
				</div>

				<!-- ── Table ── -->
				<?php if ( ! empty( $posts ) ) : ?>
				<div class="shseq-table-wrap" role="region" aria-label="<?php esc_attr_e( 'Sequences list', 'sh-sequence-engine' ); ?>">
					<table
						id="shseq-seq-table"
						class="shseq-seq-table wp-list-table widefat fixed striped"
						role="grid"
					>
						<thead>
							<tr>
								<th scope="col" class="shseq-col--cb check-column">
									<label for="shseq-select-all" class="screen-reader-text">
										<?php esc_html_e( 'Select all', 'sh-sequence-engine' ); ?>
									</label>
									<input type="checkbox" id="shseq-select-all" aria-label="<?php esc_attr_e( 'Select all sequences', 'sh-sequence-engine' ); ?>">
								</th>
								<?php echo $this->sortable_th( __( 'Name', 'sh-sequence-engine' ),       'title',    $orderby, $order, $base_url, 'shseq-col--name' ); ?>
								<?php echo $this->sortable_th( __( 'Status', 'sh-sequence-engine' ),     'post_status', $orderby, $order, $base_url, 'shseq-col--status' ); ?>
								<?php echo $this->sortable_th( __( 'Frames', 'sh-sequence-engine' ),    'frames',   $orderby, $order, $base_url, 'shseq-col--frames' ); ?>
								<th scope="col" class="shseq-col--shortcode"><?php esc_html_e( 'Shortcode', 'sh-sequence-engine' ); ?></th>
								<th scope="col" class="shseq-col--pages"><?php esc_html_e( 'Pages Used', 'sh-sequence-engine' ); ?></th>
								<?php echo $this->sortable_th( __( 'Author & Modified', 'sh-sequence-engine' ), 'modified', $orderby, $order, $base_url, 'shseq-col--author' ); ?>
								<th scope="col" class="shseq-col--actions"><?php esc_html_e( 'Actions', 'sh-sequence-engine' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php
							$today     = current_time( 'Y-m-d' );
							$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

							foreach ( $posts as $seq ) :
								$post_id      = $seq->ID;
								$frame_count  = FrameManager::count( $post_id );
								$has_frames   = $frame_count > 0;
								$seq_title    = get_the_title( $seq ) ?: __( '(Untitled)', 'sh-sequence-engine' );
								$shortcode    = '[storyboard_live id="' . (int) $post_id . '"]';
								$status_obj   = get_post_status_object( $seq->post_status );
								$author_id    = (int) $seq->post_author;
								$author_name  = get_the_author_meta( 'display_name', $author_id );
								$avatar       = get_avatar( $author_id, 24, '', $author_name, array( 'class' => 'shseq-avatar' ) );
								$modified_gmt = $seq->post_modified_gmt;
								$modified_ts  = (int) strtotime( $modified_gmt . ' UTC' );
								$modified_local = get_date_from_gmt( $modified_gmt, 'Y-m-d H:i' );
								$modified_date  = substr( $modified_local, 0, 10 );
								$modified_disp  = ( $modified_date === $today )
									? __( 'Today', 'sh-sequence-engine' )
									: ( $modified_date === $yesterday
										? __( 'Yesterday', 'sh-sequence-engine' )
										: get_date_from_gmt( $modified_gmt, get_option( 'date_format' ) ) );
								$modified_time  = get_date_from_gmt( $modified_gmt, get_option( 'time_format' ) );

								// Pages used
								$used_pages   = $pages_used_map[ $post_id ] ?? array();

								// Wizard URL
								$wizard_url   = add_query_arg( array(
									'page' => ( class_exists( __NAMESPACE__ . '\SequenceWizard' ) ? SequenceWizard::PAGE_SLUG : 'shseq-new-sequence' ),
									'id'   => $post_id,
									'step' => 1,
								), admin_url( 'admin.php' ) );

								// Preview URL
								$preview_url  = class_exists( __NAMESPACE__ . '\SequencePreview' )
									? SequencePreview::preview_url( $post_id )
									: add_query_arg( array( 'shseq_preview' => $post_id ), home_url( '/' ) );

								// Trash URL
								$trash_url    = get_delete_post_link( $post_id, '', false );

								$row_class    = implode( ' ', array_filter( array(
									'shseq-seq-row',
									! $has_frames ? 'shseq-row--no-frames' : '',
									'is-' . $seq->post_status,
								) ) );

								$search_data  = strtolower( $seq_title . ' ' . $shortcode . ' ' . $seq->post_status );
							?>
							<tr
								class="<?php echo esc_attr( $row_class ); ?>"
								data-search="<?php echo esc_attr( $search_data ); ?>"
								data-post-id="<?php echo (int) $post_id; ?>"
							>
								<!-- Checkbox -->
								<td class="check-column">
									<label class="screen-reader-text" for="cb-select-<?php echo (int) $post_id; ?>">
										<?php printf( esc_html__( 'Select %s', 'sh-sequence-engine' ), esc_html( $seq_title ) ); ?>
									</label>
									<input
										type="checkbox"
										id="cb-select-<?php echo (int) $post_id; ?>"
										name="post_ids[]"
										value="<?php echo (int) $post_id; ?>"
										class="shseq-row-cb"
									>
								</td>

								<!-- Name -->
								<td class="shseq-col--name" data-label="<?php esc_attr_e( 'Name', 'sh-sequence-engine' ); ?>">
									<div class="shseq-name-cell">
										<?php if ( ! $has_frames ) : ?>
											<span
												class="shseq-warn-icon"
												title="<?php esc_attr_e( 'No frames — sequence will not display on the frontend', 'sh-sequence-engine' ); ?>"
												aria-label="<?php esc_attr_e( 'Warning: no frames', 'sh-sequence-engine' ); ?>"
											>⚠</span>
										<?php endif; ?>
										<a
											href="<?php echo esc_url( $wizard_url ); ?>"
											class="shseq-seq-title row-title"
											data-post-id="<?php echo (int) $post_id; ?>"
										><?php echo esc_html( $seq_title ); ?></a>
										<!-- Inline rename (JS activates on double-click) -->
										<span
											class="shseq-inline-rename"
											data-post-id="<?php echo (int) $post_id; ?>"
											title="<?php esc_attr_e( 'Double-click to rename', 'sh-sequence-engine' ); ?>"
											role="button"
											tabindex="0"
											aria-label="<?php printf( esc_attr__( 'Rename: %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
										></span>
									</div>
									<!-- Row hover actions -->
									<div class="shseq-row-actions">
										<a href="<?php echo esc_url( $wizard_url ); ?>" class="shseq-row-action">
											<?php esc_html_e( 'Edit', 'sh-sequence-engine' ); ?>
										</a>
										<?php if ( $preview_url ) : ?>
										<span class="shseq-row-action-sep">|</span>
										<a href="<?php echo esc_url( $preview_url ); ?>" class="shseq-row-action" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Preview', 'sh-sequence-engine' ); ?>
										</a>
										<?php endif; ?>
										<span class="shseq-row-action-sep">|</span>
										<button
											type="button"
											class="shseq-row-action shseq-duplicate-btn"
											data-post-id="<?php echo (int) $post_id; ?>"
											aria-label="<?php printf( esc_attr__( 'Duplicate %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
										><?php esc_html_e( 'Duplicate', 'sh-sequence-engine' ); ?></button>
										<?php if ( $trash_url ) : ?>
										<span class="shseq-row-action-sep">|</span>
										<a href="<?php echo esc_url( $trash_url ); ?>" class="shseq-row-action shseq-row-action--delete">
											<?php esc_html_e( 'Trash', 'sh-sequence-engine' ); ?>
										</a>
										<?php endif; ?>
									</div>
								</td>

								<!-- Status -->
								<td class="shseq-col--status" data-label="<?php esc_attr_e( 'Status', 'sh-sequence-engine' ); ?>">
									<span class="shseq-status-pill shseq-status-pill--<?php echo esc_attr( $seq->post_status ); ?>">
										<?php echo esc_html( $status_obj ? $status_obj->label : $seq->post_status ); ?>
									</span>
								</td>

								<!-- Frames -->
								<td class="shseq-col--frames" data-label="<?php esc_attr_e( 'Frames', 'sh-sequence-engine' ); ?>">
									<span class="shseq-frames-badge <?php echo $has_frames ? 'shseq-frames-badge--ok' : 'shseq-frames-badge--zero'; ?>">
										<?php echo (int) $frame_count; ?>
									</span>
								</td>

								<!-- Shortcode -->
								<td class="shseq-col--shortcode" data-label="<?php esc_attr_e( 'Shortcode', 'sh-sequence-engine' ); ?>">
									<button
										type="button"
										class="shseq-copy-btn"
										data-copy="<?php echo esc_attr( $shortcode ); ?>"
										aria-label="<?php printf( esc_attr__( 'Copy shortcode for %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
									>
										<code class="shseq-shortcode-text"><?php echo esc_html( $shortcode ); ?></code>
										<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
									</button>
								</td>

								<!-- Pages Used -->
								<td class="shseq-col--pages" data-label="<?php esc_attr_e( 'Pages Used', 'sh-sequence-engine' ); ?>">
									<?php if ( ! empty( $used_pages ) ) : ?>
										<ul class="shseq-pages-list">
											<?php foreach ( array_slice( $used_pages, 0, 3 ) as $page_link ) : ?>
												<li><?php echo wp_kses_post( $page_link ); ?></li>
											<?php endforeach; ?>
											<?php if ( count( $used_pages ) > 3 ) : ?>
												<li class="shseq-pages-more">
													<?php printf(
														/* translators: %d: extra count */
														esc_html__( '+%d more', 'sh-sequence-engine' ),
														count( $used_pages ) - 3
													); ?>
												</li>
											<?php endif; ?>
										</ul>
									<?php else : ?>
										<span class="shseq-not-used" title="<?php esc_attr_e( 'Shortcode not found on any page or post', 'sh-sequence-engine' ); ?>">
											—
										</span>
									<?php endif; ?>
								</td>

								<!-- Author & Modified -->
								<td class="shseq-col--author" data-label="<?php esc_attr_e( 'Author & Modified', 'sh-sequence-engine' ); ?>">
									<div class="shseq-author-cell">
										<?php echo wp_kses_post( $avatar ); ?>
										<div class="shseq-author-details">
											<span class="shseq-author-name"><?php echo esc_html( $author_name ); ?></span>
											<time
												class="shseq-modified-time"
												datetime="<?php echo esc_attr( $modified_gmt ); ?>"
												title="<?php echo esc_attr( $modified_local ); ?>"
											>
												<?php echo esc_html( $modified_disp . ' ' . $modified_time ); ?>
											</time>
										</div>
									</div>
								</td>

								<!-- Actions -->
								<td class="shseq-col--actions" data-label="<?php esc_attr_e( 'Actions', 'sh-sequence-engine' ); ?>">
									<div class="shseq-action-btns">
										<a
											href="<?php echo esc_url( $wizard_url ); ?>"
											class="shseq-action-icon"
											title="<?php esc_attr_e( 'Edit in Wizard', 'sh-sequence-engine' ); ?>"
											aria-label="<?php printf( esc_attr__( 'Edit %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
										>
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										</a>
										<?php if ( $preview_url ) : ?>
										<a
											href="<?php echo esc_url( $preview_url ); ?>"
											class="shseq-action-icon"
											target="_blank"
											rel="noopener noreferrer"
											title="<?php esc_attr_e( 'Preview', 'sh-sequence-engine' ); ?>"
											aria-label="<?php printf( esc_attr__( 'Preview %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
										>
											<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
										</a>
										<?php endif; ?>
										<button
											type="button"
											class="shseq-action-icon shseq-duplicate-btn"
											data-post-id="<?php echo (int) $post_id; ?>"
											title="<?php esc_attr_e( 'Duplicate', 'sh-sequence-engine' ); ?>"
											aria-label="<?php printf( esc_attr__( 'Duplicate %s', 'sh-sequence-engine' ), esc_attr( $seq_title ) ); ?>"
										>
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
										</button>
									</div>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>

						<tfoot>
							<tr>
								<th class="check-column">
									<label for="shseq-select-all-bottom" class="screen-reader-text">
										<?php esc_html_e( 'Select all', 'sh-sequence-engine' ); ?>
									</label>
									<input type="checkbox" id="shseq-select-all-bottom" aria-label="<?php esc_attr_e( 'Select all sequences', 'sh-sequence-engine' ); ?>">
								</th>
								<th colspan="7"></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<!-- ── Pagination ── -->
				<?php if ( $pages > 1 ) : ?>
				<nav class="shseq-pagination" aria-label="<?php esc_attr_e( 'Sequences pagination', 'sh-sequence-engine' ); ?>">
					<?php
					$pagination_args = array(
						'base'      => add_query_arg( 'paged', '%#%', $base_url ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&lsaquo; ' . __( 'Previous', 'sh-sequence-engine' ),
						'next_text' => __( 'Next', 'sh-sequence-engine' ) . ' &rsaquo;',
						'type'      => 'plain',
					);
					echo paginate_links( $pagination_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<span class="shseq-pagination-info">
						<?php printf(
							/* translators: 1: current page, 2: total pages */
							esc_html__( 'Page %1$d of %2$d', 'sh-sequence-engine' ),
							$paged,
							$pages
						); ?>
					</span>
				</nav>
				<?php endif; ?>

				<?php else : ?>
				<!-- ── Empty state ── -->
				<div class="shseq-empty-state">
					<div class="shseq-empty-state__icon" aria-hidden="true">
						<span class="dashicons dashicons-images-alt2"></span>
					</div>
					<h2><?php
						if ( $status === 'trash' ) {
							esc_html_e( 'Trash is empty', 'sh-sequence-engine' );
						} elseif ( $status !== 'all' ) {
							esc_html_e( 'No sequences with this status', 'sh-sequence-engine' );
						} else {
							esc_html_e( 'No sequences yet', 'sh-sequence-engine' );
						}
					?></h2>
					<?php if ( $status === 'all' ) : ?>
					<p><?php esc_html_e( 'Create your first scroll-driven sequence to get started.', 'sh-sequence-engine' ); ?></p>
					<a class="button button-primary button-hero" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( 'Create first sequence', 'sh-sequence-engine' ); ?>
					</a>
					<?php endif; ?>
				</div>
				<?php endif; ?>

			</form><!-- /bulk form -->

			<!-- ── SR live region ── -->
			<div id="shseq-sr-announce" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>

			<!-- ── Copy toast ── -->
			<div id="shseq-toast" class="shseq-toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>

			<!-- ── Inline rename dialog (JS-activated) ── -->
			<div id="shseq-rename-dialog" class="shseq-rename-dialog" role="dialog" aria-modal="true" aria-labelledby="shseq-rename-dialog-title" hidden>
				<h2 id="shseq-rename-dialog-title"><?php esc_html_e( 'Rename Sequence', 'sh-sequence-engine' ); ?></h2>
				<label for="shseq-rename-input">
					<?php esc_html_e( 'New name', 'sh-sequence-engine' ); ?>
				</label>
				<input
					type="text"
					id="shseq-rename-input"
					class="regular-text"
					autocomplete="off"
					aria-describedby="shseq-rename-error"
				>
				<p id="shseq-rename-error" class="shseq-rename-error" role="alert" aria-live="assertive" hidden></p>
				<div class="shseq-rename-actions">
					<button type="button" class="button button-primary" id="shseq-rename-save"><?php esc_html_e( 'Save', 'sh-sequence-engine' ); ?></button>
					<button type="button" class="button" id="shseq-rename-cancel"><?php esc_html_e( 'Cancel', 'sh-sequence-engine' ); ?></button>
				</div>
			</div>
			<div id="shseq-rename-overlay" class="shseq-rename-overlay" hidden></div>

		</div><!-- .wrap -->
		<?php
	}

	// ── Handlers ───────────────────────────────────────────────────────────

	public function handle_bulk_delete(): void {
		if ( ! current_user_can( 'delete_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}
		check_admin_referer( self::NONCE_BULK, 'shseq_nonce' );

		$post_ids     = isset( $_POST['post_ids'] ) ? array_map( 'intval', (array) $_POST['post_ids'] ) : array();
		$status_redir = sanitize_key( $_POST['status_redirect'] ?? 'all' );
		$deleted      = 0;

		foreach ( $post_ids as $id ) {
			if ( get_post_type( $id ) === SequencePostType::POST_TYPE && current_user_can( 'delete_shseq_sequence', $id ) ) {
				wp_trash_post( $id );
				++$deleted;
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => self::PAGE_SLUG,
			'status'  => $status_redir !== 'all' ? $status_redir : null,
			'deleted' => $deleted,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_inline_rename(): void {
		check_ajax_referer( self::NONCE_RENAME, 'nonce' );
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sh-sequence-engine' ) ), 403 );
		}

		$post_id  = (int) ( $_POST['post_id'] ?? 0 );
		$new_name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $post_id || get_post_type( $post_id ) !== SequencePostType::POST_TYPE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid sequence.', 'sh-sequence-engine' ) ), 400 );
		}
		if ( empty( $new_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Name cannot be empty.', 'sh-sequence-engine' ), 'code' => 'empty' ), 422 );
		}

		// Duplicate check: same name, different post, same type
		$existing = get_posts( array(
			'post_type'      => SequencePostType::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'title'          => $new_name,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'exclude'        => array( $post_id ),
		) );
		if ( ! empty( $existing ) ) {
			wp_send_json_error( array( 'message' => __( 'A sequence with this name already exists.', 'sh-sequence-engine' ), 'code' => 'duplicate' ), 409 );
		}

		$result = wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_name ), true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'name' => esc_html( $new_name ) ) );
	}

	public function handle_duplicate(): void {
		check_ajax_referer( 'shseq_duplicate', 'nonce' );
		if ( ! current_user_can( 'create_shseq_sequences' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sh-sequence-engine' ) ), 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$orig    = get_post( $post_id );
		if ( ! $orig || $orig->post_type !== SequencePostType::POST_TYPE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid sequence.', 'sh-sequence-engine' ) ), 400 );
		}

		$new_id = wp_insert_post( array(
			'post_type'   => SequencePostType::POST_TYPE,
			'post_status' => 'draft',
			'post_title'  => $orig->post_title . ' ' . __( '(Copy)', 'sh-sequence-engine' ),
			'post_author' => get_current_user_id(),
		), true );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => $new_id->get_error_message() ), 500 );
		}

		// Copy post meta
		$meta = get_post_meta( $post_id );
		foreach ( $meta as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		wp_send_json_success( array(
			'message'  => __( 'Sequence duplicated as a draft.', 'sh-sequence-engine' ),
			'editUrl'  => add_query_arg( array( 'page' => 'shseq-new-sequence', 'id' => $new_id ), admin_url( 'admin.php' ) ),
			'listUrl'  => add_query_arg( array( 'page' => self::PAGE_SLUG, 'status' => 'draft' ), admin_url( 'admin.php' ) ),
			'newTitle' => get_the_title( $new_id ),
		) );
	}

	public function invalidate_pages_cache(): void {
		wp_cache_delete( 'all', self::CACHE_GROUP );
	}

	// ── Utilities ──────────────────────────────────────────────────────────

	/**
	 * Build a map of [sequence_id => [page_link, ...]] from shortcode scan.
	 * Results are cached for 24h in object cache; invalidated on any post save.
	 *
	 * @return array<int, string[]>
	 */
	private function get_pages_used_map(): array {
		$cached = wp_cache_get( 'all', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Query all public content containing our shortcode base
		global $wpdb;
		$like = '%storyboard_live%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_type, post_status
			 FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','private')
			   AND post_content LIKE %s",
			$like
		) );

		$map = array();
		if ( ! $rows ) {
			wp_cache_set( 'all', $map, self::CACHE_GROUP, self::CACHE_TTL );
			return $map;
		}

		$pattern = '/\[storyboard_live[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/';

		foreach ( $rows as $row ) {
			preg_match_all( $pattern, $row->post_content, $matches );
			foreach ( $matches[1] as $seq_id ) {
				$seq_id = (int) $seq_id;
				if ( ! isset( $map[ $seq_id ] ) ) {
					$map[ $seq_id ] = array();
				}
				$page_url   = get_permalink( $row->ID );
				$page_title = esc_html( $row->post_title ?: __( '(Untitled)', 'sh-sequence-engine' ) );
				$map[ $seq_id ][] = $page_url
					? '<a href="' . esc_url( $page_url ) . '" target="_blank" rel="noopener" class="shseq-page-link">' . $page_title . '</a>'
					: $page_title;
			}
		}

		wp_cache_set( 'all', $map, self::CACHE_GROUP, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Return [user_id => display_name] for authors who have sequences.
	 *
	 * @return array<int, string>
	 */
	private function get_sequence_authors(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_author FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
			SequencePostType::POST_TYPE
		) );

		$authors = array();
		foreach ( $ids as $id ) {
			$authors[ (int) $id ] = get_the_author_meta( 'display_name', (int) $id );
		}
		asort( $authors );
		return $authors;
	}

	/**
	 * Render a sortable <th> with ARIA sort attribute.
	 */
	private function sortable_th(
		string $label,
		string $col,
		string $current_orderby,
		string $current_order,
		string $base_url,
		string $class = ''
	): string {
		$is_sorted  = ( $current_orderby === $col );
		$next_order = ( $is_sorted && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
		$aria_sort  = '';
		$icon       = '';

		if ( $is_sorted ) {
			$aria_sort = $current_order === 'ASC' ? 'ascending' : 'descending';
			$icon      = $current_order === 'ASC' ? '▲' : '▼';
		}

		$sort_url = add_query_arg( array(
			'orderby' => $col,
			'order'   => $next_order,
			'paged'   => 1,
		), remove_query_arg( array( 'orderby', 'order', 'paged' ), $base_url ) );

		$class_str = 'shseq-col--sortable ' . ( $is_sorted ? 'shseq-col--sorted-' . strtolower( $current_order ) . ' ' : '' ) . $class;

		return sprintf(
			'<th scope="col" class="%s" aria-sort="%s"><a href="%s">%s <span class="shseq-sort-icon" aria-hidden="true">%s</span></a></th>',
			esc_attr( $class_str ),
			esc_attr( $aria_sort ),
			esc_url( $sort_url ),
			esc_html( $label ),
			esc_html( $icon )
		);
	}
}

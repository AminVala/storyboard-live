<?php
/**
 * Ready Templates page — Loop 3 Final (v3).
 *
 * Features:
 *  - 15 templates (5 free + 10 pro) with category filter tabs + live search
 *  - Real gradient preview thumbnails (no external images required)
 *  - Smart "Use this template" → creates draft → redirects to Wizard Step 2
 *  - Locked pro cards with upgrade tooltip (free users)
 *  - ARIA roles: tablist/tab/tabpanel, live search region, focus trap on tooltip
 *  - External CSS file; no inline <style> in render()
 *  - Full i18n, RTL-safe, mobile-responsive
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

final class TemplatesPage {

	const PAGE_SLUG = 'shseq-templates';
	const NONCE     = 'shseq_use_template';

	/** @var TemplateCatalog */
	private $catalog;

	public function __construct( TemplateCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shseq_use_template', array( $this, 'handle_use_template' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		$ver = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		wp_enqueue_style(
			'shseq-templates',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/admin/templates.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'shseq-templates',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/admin/templates.js',
			array(),
			$ver,
			true
		);
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}

		$templates  = $this->catalog->all();
		$categories = $this->get_categories( $templates );
		$is_pro     = $this->site_has_pro();
		?>
		<div class="wrap shseq-tpl-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

			<!-- ── Page Header ── -->
			<div class="shseq-tpl-page-header">
				<div class="shseq-tpl-page-header__text">
					<h1><?php esc_html_e( 'Ready Templates', 'sh-sequence-engine' ); ?></h1>
					<p><?php esc_html_e( 'Choose a template to jump-start your sequence — scenes, beats and overlay slots are pre-built. Upload your frames and publish.', 'sh-sequence-engine' ); ?></p>
				</div>
				<a href="<?php echo esc_url( $this->wizard_url( '', 1 ) ); ?>"
				   class="button button-secondary shseq-tpl-blank-btn">
					<?php esc_html_e( '＋ Start from scratch', 'sh-sequence-engine' ); ?>
				</a>
			</div>

			<!-- ── Toolbar: Search + Category Tabs ── -->
			<div class="shseq-tpl-toolbar" role="search">
				<label for="shseq-tpl-search" class="screen-reader-text">
					<?php esc_html_e( 'Search templates', 'sh-sequence-engine' ); ?>
				</label>
				<input
					type="search"
					id="shseq-tpl-search"
					class="shseq-tpl-search"
					placeholder="<?php esc_attr_e( 'Search templates…', 'sh-sequence-engine' ); ?>"
					aria-controls="shseq-tpl-grid"
					autocomplete="off"
				>
				<div class="shseq-tpl-category-mobile">
					<label for="shseq-tpl-cat-select" class="screen-reader-text">
						<?php esc_html_e( 'Filter by category', 'sh-sequence-engine' ); ?>
					</label>
					<select id="shseq-tpl-cat-select" class="shseq-tpl-cat-select">
						<option value="all"><?php esc_html_e( 'All categories', 'sh-sequence-engine' ); ?></option>
						<?php foreach ( $categories as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- ── Category Tab Strip (desktop) ── -->
			<div class="shseq-tpl-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Template categories', 'sh-sequence-engine' ); ?>">
				<button
					class="shseq-tpl-tab shseq-tpl-tab--active"
					role="tab"
					aria-selected="true"
					aria-controls="shseq-tpl-grid"
					data-cat="all"
					tabindex="0">
					<?php esc_html_e( 'All', 'sh-sequence-engine' ); ?>
					<span class="shseq-tpl-tab-count"><?php echo count( $templates ); ?></span>
				</button>
				<?php foreach ( $categories as $slug => $label ) :
					$cnt = count( array_filter( $templates, fn( $t ) => ( $t['category'] ?? '' ) === $slug ) );
					?>
					<button
						class="shseq-tpl-tab"
						role="tab"
						aria-selected="false"
						aria-controls="shseq-tpl-grid"
						data-cat="<?php echo esc_attr( $slug ); ?>"
						tabindex="-1">
						<?php echo esc_html( $label ); ?>
						<span class="shseq-tpl-tab-count"><?php echo $cnt; ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<!-- ── Pro banner (non-pro sites) ── -->
			<?php if ( ! $is_pro ) : ?>
			<div class="shseq-tpl-pro-banner" role="note">
				<svg width="18" height="18" fill="none" viewBox="0 0 24 24" aria-hidden="true"><path fill="#f5a623" d="M12 2l2.8 6.2L22 9.3l-5.2 4.9 1.3 7.1L12 18l-6.1 3.3 1.3-7.1L2 9.3l7.2-1.1z"/></svg>
				<?php printf(
					/* translators: %s: upgrade URL */
					esc_html__( '10 Pro templates are locked. %s to unlock all 15.', 'sh-sequence-engine' ),
					'<a href="https://storyboardlive.app/pro" target="_blank" rel="noopener">' . esc_html__( 'Upgrade to Pro', 'sh-sequence-engine' ) . '</a>'
				); ?>
			</div>
			<?php endif; ?>

			<!-- ── Template Grid ── -->
			<div
				id="shseq-tpl-grid"
				class="shseq-tpl-grid"
				role="tabpanel"
				aria-live="polite"
				aria-atomic="false">
				<?php
				$free_count = 0;
				foreach ( $templates as $tpl ) :
					$card_is_pro = $tpl['isPro'] ?? false;
					$locked      = $card_is_pro && ! $is_pro;
					if ( $card_is_pro && ! $is_pro ) {
						++$free_count;
					}
					$this->render_card( $tpl, $locked );
				endforeach;
				?>
			</div>

			<!-- ── Empty state (JS-controlled) ── -->
			<div id="shseq-tpl-empty" class="shseq-tpl-empty" hidden aria-live="polite">
				<div class="shseq-tpl-empty__icon" aria-hidden="true">🔍</div>
				<p><?php esc_html_e( 'No templates match your search.', 'sh-sequence-engine' ); ?></p>
				<button class="button shseq-tpl-clear-search" id="shseq-tpl-clear">
					<?php esc_html_e( 'Clear search', 'sh-sequence-engine' ); ?>
				</button>
			</div>

			<!-- ── Screen-reader announcement region ── -->
			<div id="shseq-tpl-sr-announce" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>
		</div>

		<!-- ── Pro Upgrade Tooltip (shared, moved by JS) ── -->
		<div id="shseq-pro-tooltip" class="shseq-pro-tooltip" role="tooltip" aria-hidden="true" hidden>
			<p><?php esc_html_e( 'This template is available in the Pro plan.', 'sh-sequence-engine' ); ?></p>
			<a href="https://storyboardlive.app/pro" target="_blank" rel="noopener" class="button button-primary shseq-pro-tooltip__btn">
				<?php esc_html_e( 'Upgrade to Pro', 'sh-sequence-engine' ); ?>
			</a>
			<button class="shseq-pro-tooltip__close" aria-label="<?php esc_attr_e( 'Close', 'sh-sequence-engine' ); ?>">✕</button>
		</div>
		<?php
	}

	// ── Card ─────────────────────────────────────────────────────────────────

	private function render_card( array $tpl, bool $locked ): void {
		$struct   = $tpl['structure'] ?? array();
		$frames   = (int) ( $struct['totalFrames'] ?? 0 );
		$scenes   = is_array( $struct['scenes'] ?? null ) ? count( $struct['scenes'] ) : 0;
		$beats    = is_array( $struct['beats']  ?? null ) ? count( $struct['beats']  ) : 0;
		$overlays = is_array( $struct['overlays'] ?? null ) ? $struct['overlays'] : array();
		$palette  = $tpl['palette'] ?? array( 'bg' => '#1d2327', 'accent' => '#f5a623', 'text' => '#ffffff' );
		$tags     = $tpl['tags'] ?? array();

		$wizard_url = $this->wizard_url( $tpl['id'], 1 );

		$card_classes = array( 'shseq-tpl-card' );
		if ( $locked )          { $card_classes[] = 'shseq-tpl-card--locked'; }
		if ( in_array( 'popular', $tags, true ) ) { $card_classes[] = 'shseq-tpl-card--popular'; }

		/* Build badge label: "popular" and "new" */
		$badge_html = '';
		if ( in_array( 'popular', $tags, true ) ) {
			$badge_html = '<span class="shseq-tpl-ribbon shseq-tpl-ribbon--popular">' . esc_html__( '⭐ Popular', 'sh-sequence-engine' ) . '</span>';
		} elseif ( in_array( 'new', $tags, true ) ) {
			$badge_html = '<span class="shseq-tpl-ribbon shseq-tpl-ribbon--new">' . esc_html__( '✦ New', 'sh-sequence-engine' ) . '</span>';
		}
		?>
		<article
			class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
			data-cat="<?php echo esc_attr( $tpl['category'] ?? '' ); ?>"
			data-search="<?php echo esc_attr( strtolower( $tpl['name'] . ' ' . ( $tpl['category_label'] ?? '' ) . ' ' . ( $tpl['description'] ?? '' ) ) ); ?>"
			data-locked="<?php echo $locked ? '1' : '0'; ?>"
			data-template-id="<?php echo esc_attr( $tpl['id'] ); ?>"
			aria-label="<?php echo esc_attr( $tpl['name'] ); ?>"
		>
			<!-- Thumbnail -->
			<div class="shseq-tpl-card__thumb"
			     style="--tpl-bg:<?php echo esc_attr( $palette['bg'] ); ?>;--tpl-accent:<?php echo esc_attr( $palette['accent'] ); ?>;--tpl-text:<?php echo esc_attr( $palette['text'] ); ?>;"
			     aria-hidden="true">
				<?php echo wp_kses_post( $badge_html ); ?>
				<?php $this->render_thumb_svg( $tpl['category'] ?? 'default', $palette ); ?>
				<!-- Pro badge -->
				<?php if ( $tpl['isPro'] ?? false ) : ?>
				<span class="shseq-tpl-pro-badge" aria-label="<?php esc_attr_e( 'Pro template', 'sh-sequence-engine' ); ?>">
					<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.8 6.2L22 9.3l-5.2 4.9 1.3 7.1L12 18l-6.1 3.3 1.3-7.1L2 9.3l7.2-1.1z"/></svg>
					Pro
				</span>
				<?php endif; ?>
			</div>

			<!-- Body -->
			<div class="shseq-tpl-card__body">
				<span class="shseq-tpl-category-pill">
					<?php echo esc_html( $tpl['category_label'] ?? '' ); ?>
				</span>
				<h2 class="shseq-tpl-name"><?php echo esc_html( $tpl['name'] ); ?></h2>
				<p class="shseq-tpl-desc"><?php echo esc_html( $tpl['description'] ); ?></p>

				<!-- Stats row -->
				<div class="shseq-tpl-stats" aria-label="<?php esc_attr_e( 'Template stats', 'sh-sequence-engine' ); ?>">
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">▶</span>
						<?php printf( /* translators: %d: frame count */ _n( '%d frame', '%d frames', $frames, 'sh-sequence-engine' ), $frames ); ?>
					</span>
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">◈</span>
						<?php printf( /* translators: %d: scene count */ _n( '%d scene', '%d scenes', $scenes, 'sh-sequence-engine' ), $scenes ); ?>
					</span>
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">◉</span>
						<?php printf( /* translators: %d: beat count */ _n( '%d beat', '%d beats', $beats, 'sh-sequence-engine' ), $beats ); ?>
					</span>
				</div>

				<!-- Overlay slot pills -->
				<?php if ( ! empty( $overlays ) ) : ?>
				<div class="shseq-tpl-slots" aria-label="<?php esc_attr_e( 'Overlay slots', 'sh-sequence-engine' ); ?>">
					<?php foreach ( array_slice( $overlays, 0, 5 ) as $slot ) :
						$key = is_array( $slot ) ? ( $slot['key'] ?? $slot['type'] ?? '?' ) : (string) $slot;
						?>
						<span class="shseq-tpl-slot-pill"><?php echo esc_html( $key ); ?></span>
					<?php endforeach; ?>
					<?php if ( count( $overlays ) > 5 ) : ?>
						<span class="shseq-tpl-slot-pill shseq-tpl-slot-pill--more">
							+<?php echo count( $overlays ) - 5; ?>
						</span>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<!-- Action -->
				<div class="shseq-tpl-card__action">
					<?php if ( $locked ) : ?>
						<button
							type="button"
							class="button button-primary shseq-tpl-use-btn shseq-tpl-use-btn--locked"
							data-pro-trigger="1"
							aria-haspopup="true"
							aria-expanded="false">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 8h-1V6c0-2.8-2.2-5-5-5S7 3.2 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.7 1.4-3.1 3.1-3.1 1.7 0 3.1 1.4 3.1 3.1v2z"/></svg>
							<?php esc_html_e( 'Unlock — Pro only', 'sh-sequence-engine' ); ?>
						</button>
					<?php else : ?>
						<a href="<?php echo esc_url( $wizard_url ); ?>"
						   class="button button-primary shseq-tpl-use-btn"
						   aria-label="<?php echo esc_attr( sprintf( __( 'Use template: %s', 'sh-sequence-engine' ), $tpl['name'] ) ); ?>">
							<?php esc_html_e( 'Use this template', 'sh-sequence-engine' ); ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * Render an inline SVG thumbnail that represents the template category.
	 * No external images — rendered server-side, themed by palette variables.
	 */
	private function render_thumb_svg( string $category, array $palette ): void {
		// Each category gets a distinct, recognisable SVG composition.
		$bg     = esc_attr( $palette['bg']     ?? '#1d2327' );
		$accent = esc_attr( $palette['accent'] ?? '#f5a623' );
		$text   = esc_attr( $palette['text']   ?? '#ffffff' );

		$svgs = array(
			// Cinematic: film strip bars + spotlight glow
			'cinematic' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<ellipse cx="140" cy="110" rx="80" ry="60" fill="' . $accent . '" opacity=".18"/>
				<rect x="0" y="0"   width="20" height="160" fill="rgba(0,0,0,.5)"/>
				<rect x="260" y="0" width="20" height="160" fill="rgba(0,0,0,.5)"/>
				<rect x="4" y="12" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="4" y="36" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="4" y="60" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="4" y="84" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="264" y="12" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="264" y="36" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="264" y="60" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<rect x="264" y="84" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/>
				<polygon points="140,50 118,90 162,90" fill="' . $accent . '" opacity=".9"/>
				<rect x="80" y="100" width="120" height="8" rx="4" fill="' . $text . '" opacity=".4"/>
				<rect x="100" y="116" width="80" height="6" rx="3" fill="' . $text . '" opacity=".25"/>
			</svg>',

			// E-commerce: product box + price tag
			'ecommerce' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="80" y="30" width="120" height="90" rx="6" fill="rgba(255,255,255,.08)" stroke="' . $accent . '" stroke-width="2"/>
				<rect x="100" y="50" width="80" height="40" rx="4" fill="' . $accent . '" opacity=".25"/>
				<rect x="105" y="100" width="70" height="7" rx="3" fill="' . $text . '" opacity=".5"/>
				<rect x="115" y="113" width="50" height="5" rx="2" fill="' . $text . '" opacity=".3"/>
				<rect x="108" y="122" width="64" height="18" rx="4" fill="' . $accent . '" opacity=".85"/>
				<circle cx="230" cy="32" r="14" fill="' . $accent . '" opacity=".9"/>
				<text x="230" y="37" text-anchor="middle" font-size="11" fill="' . $bg . '" font-weight="bold">$</text>
			</svg>',

			// Portfolio / Agency: grid layout
			'portfolio' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="20" y="20"  width="110" height="70" rx="3" fill="' . $accent . '" opacity=".3"/>
				<rect x="150" y="20" width="110" height="30" rx="3" fill="' . $accent . '" opacity=".2"/>
				<rect x="150" y="58" width="110" height="32" rx="3" fill="' . $accent . '" opacity=".15"/>
				<rect x="20"  y="100" width="50"  height="40" rx="3" fill="' . $text . '" opacity=".12"/>
				<rect x="78"  y="100" width="50"  height="40" rx="3" fill="' . $text . '" opacity=".08"/>
				<rect x="136" y="100" width="50"  height="40" rx="3" fill="' . $text . '" opacity=".06"/>
				<rect x="194" y="100" width="66"  height="40" rx="3" fill="' . $text . '" opacity=".04"/>
				<rect x="30" y="35"  width="60" height="6"  rx="3" fill="' . $text . '" opacity=".6"/>
				<rect x="30" y="47"  width="40" height="5"  rx="2" fill="' . $text . '" opacity=".3"/>
			</svg>',

			// Real estate: house silhouette + sun
			'realestate' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<defs><linearGradient id="re-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $accent . '" stop-opacity=".4"/><stop offset="100%" stop-color="' . $bg . '"/></linearGradient></defs>
				<rect width="280" height="160" fill="url(#re-sky)"/>
				<polygon points="80,90 140,40 200,90" fill="' . $accent . '" opacity=".7"/>
				<rect x="95" y="90" width="90" height="50" fill="' . $accent . '" opacity=".4"/>
				<rect x="124" y="108" width="32" height="32" rx="2" fill="' . $bg . '" opacity=".6"/>
				<rect x="100" y="96" width="22" height="18" rx="2" fill="rgba(255,255,255,.2)"/>
				<rect x="158" y="96" width="22" height="18" rx="2" fill="rgba(255,255,255,.2)"/>
				<circle cx="220" cy="35" r="18" fill="' . $accent . '" opacity=".85"/>
			</svg>',

			// Brand: bold typographic composition
			'brand' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="0" y="0" width="8" height="160" fill="' . $accent . '" opacity=".9"/>
				<rect x="20"  y="30"  width="180" height="18" rx="2" fill="' . $text . '" opacity=".7"/>
				<rect x="20"  y="56"  width="140" height="14" rx="2" fill="' . $text . '" opacity=".4"/>
				<rect x="20"  y="76"  width="100" height="14" rx="2" fill="' . $text . '" opacity=".3"/>
				<rect x="20"  y="110" width="80"  height="28" rx="4" fill="' . $accent . '" opacity=".85"/>
				<circle cx="230" cy="80" r="36" fill="' . $accent . '" opacity=".15"/>
				<circle cx="230" cy="80" r="22" fill="' . $accent . '" opacity=".25"/>
			</svg>',

			// Food: plate + steam curves
			'food' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<circle cx="140" cy="95" r="55" fill="rgba(255,255,255,.06)" stroke="' . $accent . '" stroke-width="2"/>
				<circle cx="140" cy="95" r="38" fill="' . $accent . '" opacity=".2"/>
				<ellipse cx="140" cy="95" rx="22" ry="16" fill="' . $accent . '" opacity=".5"/>
				<path d="M120 50 Q124 38 120 26" stroke="' . $text . '" stroke-width="2" fill="none" stroke-linecap="round" opacity=".4"/>
				<path d="M140 48 Q144 36 140 24" stroke="' . $text . '" stroke-width="2" fill="none" stroke-linecap="round" opacity=".4"/>
				<path d="M160 50 Q164 38 160 26" stroke="' . $text . '" stroke-width="2" fill="none" stroke-linecap="round" opacity=".4"/>
				<rect x="60" y="142" width="160" height="2" rx="1" fill="' . $accent . '" opacity=".4"/>
			</svg>',

			// Fashion: figure silhouette + editorial bar
			'fashion' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="0" y="0" width="280" height="6" fill="' . $accent . '"/>
				<ellipse cx="140" cy="48" rx="18" ry="22" fill="' . $text . '" opacity=".5"/>
				<path d="M110 68 Q100 100 95 150 L185 150 Q180 100 170 68 Q155 78 140 78 Q125 78 110 68Z" fill="' . $text . '" opacity=".35"/>
				<rect x="40"  y="70" width="50"  height="6"  rx="3" fill="' . $text . '" opacity=".4"/>
				<rect x="40"  y="82" width="35"  height="5"  rx="2" fill="' . $text . '" opacity=".25"/>
				<rect x="195" y="70" width="50"  height="6"  rx="3" fill="' . $text . '" opacity=".4"/>
				<rect x="195" y="82" width="35"  height="5"  rx="2" fill="' . $text . '" opacity=".25"/>
				<rect x="0" y="154" width="280" height="6" fill="' . $accent . '"/>
			</svg>',

			// SaaS / Tech: dashboard mockup
			'tech' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="15" y="15" width="250" height="130" rx="6" fill="rgba(255,255,255,.05)" stroke="rgba(255,255,255,.1)" stroke-width="1"/>
				<rect x="15" y="15" width="250" height="24" rx="6" fill="rgba(255,255,255,.08)"/>
				<circle cx="30" cy="27" r="5" fill="#e05252" opacity=".8"/>
				<circle cx="44" cy="27" r="5" fill="#e0a752" opacity=".8"/>
				<circle cx="58" cy="27" r="5" fill="#52e07a" opacity=".8"/>
				<rect x="26" y="50" width="70" height="80" rx="4" fill="rgba(255,255,255,.07)"/>
				<rect x="26" y="50" width="70" height="18" rx="4" fill="' . $accent . '" opacity=".3"/>
				<rect x="106" y="50" width="149" height="36" rx="4" fill="rgba(255,255,255,.06)"/>
				<rect x="106" y="94" width="70"  height="36" rx="4" fill="rgba(255,255,255,.06)"/>
				<rect x="184" y="94" width="71"  height="36" rx="4" fill="' . $accent . '" opacity=".2"/>
				<rect x="36" y="80" width="50" height="5" rx="2" fill="' . $text . '" opacity=".3"/>
				<rect x="36" y="92" width="35" height="4" rx="2" fill="' . $text . '" opacity=".2"/>
			</svg>',

			// Travel: horizon + mountain + sun
			'travel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<defs><linearGradient id="tr-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $bg . '"/><stop offset="100%" stop-color="' . $accent . '" stop-opacity=".6"/></linearGradient></defs>
				<rect width="280" height="160" fill="url(#tr-sky)"/>
				<circle cx="50" cy="40" r="20" fill="' . $accent . '" opacity=".9"/>
				<polygon points="0,130 60,60 120,130" fill="' . $text . '" opacity=".2"/>
				<polygon points="80,130 160,50 240,130" fill="' . $text . '" opacity=".3"/>
				<polygon points="160,130 220,75 280,130" fill="' . $text . '" opacity=".15"/>
				<rect x="0" y="130" width="280" height="30" fill="' . $accent . '" opacity=".3"/>
				<rect x="90" y="110" width="100" height="6" rx="3" fill="' . $text . '" opacity=".6"/>
				<rect x="110" y="122" width="60" height="5" rx="2" fill="' . $text . '" opacity=".4"/>
			</svg>',

			// Events: stage lights + crowd
			'events' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<polygon points="40,0 80,0 140,160 0,160" fill="' . $accent . '" opacity=".1"/>
				<polygon points="200,0 240,0 280,160 140,160" fill="' . $accent . '" opacity=".1"/>
				<circle cx="70"  cy="10" r="10" fill="' . $accent . '" opacity=".8"/>
				<circle cx="140" cy="10" r="10" fill="#ff5089" opacity=".7"/>
				<circle cx="210" cy="10" r="10" fill="' . $accent . '" opacity=".8"/>
				<ellipse cx="140" cy="155" rx="120" ry="20" fill="rgba(255,255,255,.05)"/>
				<rect x="80" y="70"  width="120" height="12" rx="3" fill="' . $text . '" opacity=".6"/>
				<rect x="95" y="88"  width="90"  height="8"  rx="3" fill="' . $text . '" opacity=".35"/>
				<rect x="105" y="105" width="70" height="22" rx="4" fill="' . $accent . '" opacity=".8"/>
			</svg>',

			// Architecture: building elevation
			'architecture' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="20"  y="40"  width="60"  height="110" fill="' . $text . '" opacity=".12"/>
				<rect x="90"  y="20"  width="100" height="130" fill="' . $text . '" opacity=".18"/>
				<rect x="200" y="55"  width="60"  height="95"  fill="' . $text . '" opacity=".10"/>
				<!-- windows -->
				<rect x="30"  y="55"  width="14" height="10" rx="1" fill="' . $accent . '" opacity=".4"/>
				<rect x="50"  y="55"  width="14" height="10" rx="1" fill="' . $accent . '" opacity=".4"/>
				<rect x="30"  y="72"  width="14" height="10" rx="1" fill="' . $accent . '" opacity=".3"/>
				<rect x="50"  y="72"  width="14" height="10" rx="1" fill="rgba(255,255,255,.15)"/>
				<rect x="105" y="35"  width="20" height="14" rx="1" fill="' . $accent . '" opacity=".5"/>
				<rect x="133" y="35"  width="20" height="14" rx="1" fill="' . $accent . '" opacity=".5"/>
				<rect x="161" y="35"  width="20" height="14" rx="1" fill="' . $accent . '" opacity=".4"/>
				<rect x="105" y="57"  width="20" height="14" rx="1" fill="rgba(255,255,255,.2)"/>
				<rect x="133" y="57"  width="20" height="14" rx="1" fill="' . $accent . '" opacity=".45"/>
				<rect x="161" y="57"  width="20" height="14" rx="1" fill="rgba(255,255,255,.15)"/>
				<rect x="0"   y="148" width="280" height="12" fill="' . $accent . '" opacity=".25"/>
			</svg>',

			// Education: open book
			'education' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<path d="M140 40 Q100 30 50 45 L50 130 Q100 115 140 125Z" fill="' . $accent . '" opacity=".3"/>
				<path d="M140 40 Q180 30 230 45 L230 130 Q180 115 140 125Z" fill="' . $accent . '" opacity=".2"/>
				<rect x="140" y="40" width="2" height="85" fill="' . $text . '" opacity=".4"/>
				<rect x="65"  y="60" width="60" height="5" rx="2" fill="' . $text . '" opacity=".4"/>
				<rect x="65"  y="72" width="45" height="4" rx="2" fill="' . $text . '" opacity=".3"/>
				<rect x="65"  y="82" width="55" height="4" rx="2" fill="' . $text . '" opacity=".25"/>
				<rect x="65"  y="92" width="40" height="4" rx="2" fill="' . $text . '" opacity=".2"/>
				<rect x="155" y="60" width="60" height="5" rx="2" fill="' . $text . '" opacity=".4"/>
				<rect x="155" y="72" width="50" height="4" rx="2" fill="' . $text . '" opacity=".3"/>
				<rect x="155" y="82" width="55" height="4" rx="2" fill="' . $text . '" opacity=".25"/>
				<circle cx="140" cy="135" r="6" fill="' . $accent . '" opacity=".6"/>
			</svg>',

			// Photography: aperture iris
			'photography' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<circle cx="140" cy="80" r="60" fill="none" stroke="' . $text . '" stroke-width="2" opacity=".2"/>
				<circle cx="140" cy="80" r="42" fill="none" stroke="' . $accent . '" stroke-width="1.5" opacity=".4"/>
				<circle cx="140" cy="80" r="26" fill="' . $accent . '" opacity=".15"/>
				<!-- aperture blades -->
				<g opacity=".5" stroke="' . $text . '" stroke-width="1.5" fill="none">
					<line x1="140" y1="38" x2="140" y2="122"/>
					<line x1="107" y1="57" x2="173" y2="103"/>
					<line x1="107" y1="103" x2="173" y2="57"/>
				</g>
				<circle cx="140" cy="80" r="12" fill="' . $accent . '" opacity=".7"/>
				<rect x="55" y="138" width="170" height="6" rx="3" fill="' . $text . '" opacity=".25"/>
			</svg>',

			// Fitness: lightning bolt + pulse
			'fitness' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<polygon points="155,15 120,85 145,85 125,145 165,65 138,65" fill="' . $accent . '" opacity=".9"/>
				<polyline points="30,90 55,90 70,50 90,130 110,70 130,90 280,90" stroke="' . $accent . '" stroke-width="2.5" fill="none" opacity=".5"/>
				<rect x="20" y="140" width="240" height="2" fill="' . $accent . '" opacity=".3"/>
			</svg>',

			// Lifestyle / events / wedding
			'lifestyle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<defs><linearGradient id="ls-bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $bg . '"/><stop offset="100%" stop-color="' . $accent . '" stop-opacity=".3"/></linearGradient></defs>
				<rect width="280" height="160" fill="url(#ls-bg)"/>
				<!-- rings -->
				<circle cx="126" cy="70" r="22" fill="none" stroke="' . $accent . '" stroke-width="3" opacity=".7"/>
				<circle cx="154" cy="70" r="22" fill="none" stroke="' . $accent . '" stroke-width="3" opacity=".7"/>
				<!-- flowers left -->
				<circle cx="50" cy="50" r="8" fill="' . $accent . '" opacity=".4"/>
				<circle cx="38" cy="62" r="7" fill="' . $accent . '" opacity=".3"/>
				<circle cx="62" cy="62" r="7" fill="' . $accent . '" opacity=".3"/>
				<!-- flowers right -->
				<circle cx="230" cy="50" r="8" fill="' . $accent . '" opacity=".4"/>
				<circle cx="218" cy="62" r="7" fill="' . $accent . '" opacity=".3"/>
				<circle cx="242" cy="62" r="7" fill="' . $accent . '" opacity=".3"/>
				<rect x="90"  y="108" width="100" height="8"  rx="4" fill="' . $text . '" opacity=".5"/>
				<rect x="105" y="122" width="70"  height="6"  rx="3" fill="' . $text . '" opacity=".3"/>
				<rect x="120" y="134" width="40"  height="6"  rx="3" fill="' . $accent . '" opacity=".6"/>
			</svg>',

			// Default fallback
			'default' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
				<rect width="280" height="160" fill="' . $bg . '"/>
				<rect x="60"  y="40"  width="160" height="12" rx="4" fill="' . $text . '" opacity=".4"/>
				<rect x="80"  y="62"  width="120" height="9"  rx="3" fill="' . $text . '" opacity=".25"/>
				<rect x="95"  y="78"  width="90"  height="9"  rx="3" fill="' . $text . '" opacity=".2"/>
				<rect x="100" y="104" width="80"  height="24" rx="5" fill="' . $accent . '" opacity=".8"/>
			</svg>',
		);

		echo wp_kses( $svgs[ $category ] ?? $svgs['default'], $this->allowed_svg_tags() );
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * Smart redirect: create a new sequence draft pre-populated with the
	 * chosen template, then forward to Wizard Step 2.
	 */
	public function handle_use_template(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}
		check_admin_referer( self::NONCE );

		$template_id = sanitize_key( wp_unslash( $_POST['template_id'] ?? '' ) );
		$template    = $this->catalog->get( $template_id );

		if ( ! $template ) {
			wp_safe_redirect( add_query_arg( 'shseq_error', 'invalid_template', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
			exit;
		}

		// Create a draft sequence pre-populated with the template structure.
		$post_id = wp_insert_post( array(
			'post_type'   => SequencePostType::POST_TYPE,
			'post_status' => 'draft',
			'post_title'  => wp_strip_all_tags( $template['name'] ),
		), true );

		if ( is_wp_error( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'shseq_error', 'create_failed', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
			exit;
		}

		// Store template structure as post meta for the wizard to read.
		update_post_meta( $post_id, '_shseq_template_id',    $template_id );
		update_post_meta( $post_id, '_shseq_wizard_step',    2 );
		update_post_meta( $post_id, '_shseq_wizard_mode',    'template' );
		update_post_meta( $post_id, '_shseq_frame_count',    $template['structure']['totalFrames'] ?? 0 );
		update_post_meta( $post_id, '_shseq_overlay_data',   wp_json_encode( $template['structure']['overlays'] ?? array() ) );
		update_post_meta( $post_id, '_shseq_animation_type', $template['structure']['animationType'] ?? 'scroll-driven' );

		// Go directly to Wizard Step 2 (canvas/overlay editor).
		wp_safe_redirect( add_query_arg( array(
			'page'    => 'shseq-create',
			'step'    => 2,
			'post_id' => $post_id,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Utilities ────────────────────────────────────────────────────────────

	/** @return array<string,string> slug → label */
	private function get_categories( array $templates ): array {
		$cats = array();
		foreach ( $templates as $t ) {
			$slug  = $t['category'] ?? '';
			$label = $t['category_label'] ?? ucfirst( $slug );
			if ( $slug && ! isset( $cats[ $slug ] ) ) {
				$cats[ $slug ] = $label;
			}
		}
		return $cats;
	}

	private function wizard_url( string $template_id, int $step ): string {
		return add_query_arg( array_filter( array(
			'page'        => 'shseq-create',
			'step'        => $step,
			'template_id' => $template_id ?: null,
		) ), admin_url( 'admin.php' ) );
	}

	private function site_has_pro(): bool {
		return (bool) get_option( 'shseq_pro_license_active', false );
	}

	/** Minimal SVG allow-list for wp_kses(). */
	private function allowed_svg_tags(): array {
		return array(
			'svg'     => array( 'xmlns' => true, 'viewbox' => true, 'class' => true, 'aria-hidden' => true, 'width' => true, 'height' => true ),
			'rect'    => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ),
			'circle'  => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ),
			'ellipse' => array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ),
			'polygon' => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ),
			'polyline'=> array( 'points' => true, 'stroke' => true, 'stroke-width' => true, 'fill' => true, 'opacity' => true ),
			'path'    => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'opacity' => true ),
			'line'    => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ),
			'text'    => array( 'x' => true, 'y' => true, 'text-anchor' => true, 'font-size' => true, 'fill' => true, 'font-weight' => true ),
			'defs'    => array(),
			'linearGradient' => array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
			'stop'    => array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ),
			'g'       => array( 'opacity' => true, 'stroke' => true, 'stroke-width' => true, 'fill' => true ),
		);
	}
}

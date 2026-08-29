<?php
/**
 * Ready Templates page — v4 Bilingual Final.
 *
 * Changes from v3:
 *  - Full fa_IR / English bilingual (ALL strings)
 *  - Fixed: "Start from scratch" → fa bilingual
 *  - Fixed: Pro banner, search placeholder, tab labels, empty state
 *  - Fixed: card action buttons bilingual
 *  - Fixed: category tabs count numbers preserved
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

	// ── Bilingual ─────────────────────────────────────────────────────────

	private function is_fa(): bool {
		return is_rtl();
	}

	private function fa( string $fa, string $en ): string {
		return $this->is_fa() ? $fa : $en;
	}

	// ── Hooks ─────────────────────────────────────────────────────────────

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
		wp_localize_script( 'shseq-templates', 'shseqTpl', array(
			'isFa' => $this->is_fa(),
			'i18n' => array(
				'noResults'  => $this->fa( 'قالبی با این جستجو یافت نشد.', 'No templates match your search.' ),
				'clearSearch'=> $this->fa( 'پاک کردن جستجو', 'Clear search' ),
			),
		) );
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html( $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ) );
		}

		$templates  = $this->catalog->all();
		$categories = $this->get_categories( $templates );
		$is_pro     = $this->site_has_pro();
		?>
		<div class="wrap shseq-tpl-wrap" dir="<?php echo $this->is_fa() ? 'rtl' : 'ltr'; ?>">

			<?php /* ── Header ─────────────────────────────────────── */ ?>
			<div class="shseq-tpl-page-header">
				<div class="shseq-tpl-page-header__text">
					<h1><?php echo esc_html( $this->fa( 'قالب‌های آماده', 'Ready Templates' ) ); ?></h1>
					<p><?php echo esc_html( $this->fa(
						'یک قالب انتخاب کنید — صحنه‌ها، بیت‌ها و اسلات‌های overlay از پیش ساخته شده‌اند. فریم‌ها را آپلود کنید و منتشر کنید.',
						'Choose a template to jump-start your sequence — scenes, beats and overlay slots are pre-built. Upload your frames and publish.'
					) ); ?></p>
				</div>
				<a href="<?php echo esc_url( $this->wizard_url( '', 1 ) ); ?>"
				   class="button button-secondary shseq-tpl-blank-btn">
					<?php echo esc_html( $this->fa( '＋ شروع از صفر', '＋ Start from scratch' ) ); ?>
				</a>
			</div>

			<?php /* ── Toolbar (search + mobile select) ───────────── */ ?>
			<div class="shseq-tpl-toolbar" role="search">
				<label for="shseq-tpl-search" class="screen-reader-text">
					<?php echo esc_html( $this->fa( 'جستجوی قالب‌ها', 'Search templates' ) ); ?>
				</label>
				<input
					type="search"
					id="shseq-tpl-search"
					class="shseq-tpl-search"
					placeholder="<?php echo esc_attr( $this->fa( 'جستجوی قالب‌ها…', 'Search templates…' ) ); ?>"
					aria-controls="shseq-tpl-grid"
					autocomplete="off"
				>
				<div class="shseq-tpl-category-mobile">
					<label for="shseq-tpl-cat-select" class="screen-reader-text">
						<?php echo esc_html( $this->fa( 'فیلتر بر اساس دسته', 'Filter by category' ) ); ?>
					</label>
					<select id="shseq-tpl-cat-select" class="shseq-tpl-cat-select">
						<option value="all"><?php echo esc_html( $this->fa( 'همه دسته‌ها', 'All categories' ) ); ?></option>
						<?php foreach ( $categories as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<?php /* ── Category tabs ──────────────────────────────── */ ?>
			<div class="shseq-tpl-tabs" role="tablist"
			     aria-label="<?php echo esc_attr( $this->fa( 'دسته‌بندی قالب‌ها', 'Template categories' ) ); ?>">
				<button
					class="shseq-tpl-tab shseq-tpl-tab--active"
					role="tab"
					aria-selected="true"
					aria-controls="shseq-tpl-grid"
					data-cat="all"
					tabindex="0">
					<?php echo esc_html( $this->fa( 'همه', 'All' ) ); ?>
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

			<?php /* ── Pro banner ─────────────────────────────────── */ ?>
			<?php if ( ! $is_pro ) : ?>
			<div class="shseq-tpl-pro-banner" role="note">
				<svg width="18" height="18" fill="none" viewBox="0 0 24 24" aria-hidden="true">
					<path fill="#f5a623" d="M12 2l2.8 6.2L22 9.3l-5.2 4.9 1.3 7.1L12 18l-6.1 3.3 1.3-7.1L2 9.3l7.2-1.1z"/>
				</svg>
				<?php
				$pro_count  = count( array_filter( $templates, fn($t) => $t['isPro'] ?? false ) );
				$free_count = count( $templates );
				printf(
					wp_kses_post( $this->fa(
						'%d قالب Pro قفل است. <a href="https://storyboardlive.app/pro" target="_blank" rel="noopener">ارتقا به Pro</a> برای دسترسی به همه %d قالب.',
						'%d Pro templates are locked. <a href="https://storyboardlive.app/pro" target="_blank" rel="noopener">Upgrade to Pro</a> to unlock all %d.'
					) ),
					$pro_count,
					$free_count
				);
				?>
			</div>
			<?php endif; ?>

			<?php /* ── Template grid ──────────────────────────────── */ ?>
			<div
				id="shseq-tpl-grid"
				class="shseq-tpl-grid"
				role="tabpanel"
				aria-live="polite"
				aria-atomic="false">
				<?php foreach ( $templates as $tpl ) :
					$card_is_pro = $tpl['isPro'] ?? false;
					$locked      = $card_is_pro && ! $is_pro;
					$this->render_card( $tpl, $locked );
				endforeach; ?>
			</div>

			<?php /* ── Empty state ──────────────────────────────────── */ ?>
			<div id="shseq-tpl-empty" class="shseq-tpl-empty" hidden aria-live="polite">
				<div class="shseq-tpl-empty__icon" aria-hidden="true">🔍</div>
				<p><?php echo esc_html( $this->fa( 'قالبی با این جستجو یافت نشد.', 'No templates match your search.' ) ); ?></p>
				<button class="button shseq-tpl-clear-search" id="shseq-tpl-clear">
					<?php echo esc_html( $this->fa( 'پاک کردن جستجو', 'Clear search' ) ); ?>
				</button>
			</div>

			<?php /* ── SR announce ──────────────────────────────────── */ ?>
			<div id="shseq-tpl-sr-announce" class="screen-reader-text" aria-live="assertive" aria-atomic="true"></div>
		</div>

		<?php /* ── Pro tooltip ──────────────────────────────────── */ ?>
		<div id="shseq-pro-tooltip" class="shseq-pro-tooltip" role="tooltip" aria-hidden="true" hidden>
			<p><?php echo esc_html( $this->fa( 'این قالب در پلن Pro در دسترس است.', 'This template is available in the Pro plan.' ) ); ?></p>
			<a href="https://storyboardlive.app/pro" target="_blank" rel="noopener" class="button button-primary shseq-pro-tooltip__btn">
				<?php echo esc_html( $this->fa( 'ارتقا به Pro', 'Upgrade to Pro' ) ); ?>
			</a>
			<button class="shseq-pro-tooltip__close" aria-label="<?php echo esc_attr( $this->fa( 'بستن', 'Close' ) ); ?>">✕</button>
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
		if ( $locked )                                          { $card_classes[] = 'shseq-tpl-card--locked'; }
		if ( in_array( 'popular', $tags, true ) )              { $card_classes[] = 'shseq-tpl-card--popular'; }

		$badge_html = '';
		if ( in_array( 'popular', $tags, true ) ) {
			$badge_html = '<span class="shseq-tpl-ribbon shseq-tpl-ribbon--popular">'
				. esc_html( $this->fa( '⭐ محبوب', '⭐ Popular' ) ) . '</span>';
		} elseif ( in_array( 'new', $tags, true ) ) {
			$badge_html = '<span class="shseq-tpl-ribbon shseq-tpl-ribbon--new">'
				. esc_html( $this->fa( '✦ جدید', '✦ New' ) ) . '</span>';
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
			<div class="shseq-tpl-card__thumb"
			     style="--tpl-bg:<?php echo esc_attr( $palette['bg'] ); ?>;--tpl-accent:<?php echo esc_attr( $palette['accent'] ); ?>;--tpl-text:<?php echo esc_attr( $palette['text'] ); ?>;"
			     aria-hidden="true">
				<?php echo wp_kses_post( $badge_html ); ?>
				<?php $this->render_thumb_svg( $tpl['category'] ?? 'default', $palette ); ?>
				<?php if ( $tpl['isPro'] ?? false ) : ?>
				<span class="shseq-tpl-pro-badge" aria-label="<?php echo esc_attr( $this->fa( 'قالب Pro', 'Pro template' ) ); ?>">
					<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
						<path d="M12 2l2.8 6.2L22 9.3l-5.2 4.9 1.3 7.1L12 18l-6.1 3.3 1.3-7.1L2 9.3l7.2-1.1z"/>
					</svg>
					Pro
				</span>
				<?php endif; ?>
			</div>

			<div class="shseq-tpl-card__body">
				<span class="shseq-tpl-category-pill">
					<?php echo esc_html( $tpl['category_label'] ?? '' ); ?>
				</span>
				<h2 class="shseq-tpl-name"><?php echo esc_html( $tpl['name'] ); ?></h2>
				<p class="shseq-tpl-desc"><?php echo esc_html( $tpl['description'] ); ?></p>

				<div class="shseq-tpl-stats" aria-label="<?php echo esc_attr( $this->fa( 'آمار قالب', 'Template stats' ) ); ?>">
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">▶</span>
						<?php printf(
							$this->fa( '%d فریم', '%d frames' ),
							$frames
						); ?>
					</span>
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">◈</span>
						<?php printf(
							$this->fa( '%d صحنه', '%d scenes' ),
							$scenes
						); ?>
					</span>
					<span class="shseq-tpl-stat">
						<span class="shseq-tpl-stat__icon" aria-hidden="true">◉</span>
						<?php printf(
							$this->fa( '%d بیت', '%d beats' ),
							$beats
						); ?>
					</span>
				</div>

				<?php if ( ! empty( $overlays ) ) : ?>
				<div class="shseq-tpl-slots" aria-label="<?php echo esc_attr( $this->fa( 'اسلات‌های overlay', 'Overlay slots' ) ); ?>">
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

				<div class="shseq-tpl-card__action">
					<?php if ( $locked ) : ?>
						<button
							type="button"
							class="button button-primary shseq-tpl-use-btn shseq-tpl-use-btn--locked"
							data-pro-trigger="1"
							aria-haspopup="true"
							aria-expanded="false">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
								<path d="M18 8h-1V6c0-2.8-2.2-5-5-5S7 3.2 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.7 1.4-3.1 3.1-3.1 1.7 0 3.1 1.4 3.1 3.1v2z"/>
							</svg>
							<?php echo esc_html( $this->fa( 'فقط Pro — قفل است', 'Unlock — Pro only' ) ); ?>
						</button>
					<?php else : ?>
						<a href="<?php echo esc_url( $wizard_url ); ?>"
						   class="button button-primary shseq-tpl-use-btn"
						   aria-label="<?php echo esc_attr( sprintf(
								$this->fa( 'استفاده از قالب: %s', 'Use template: %s' ),
								$tpl['name']
						   ) ); ?>">
							<?php echo esc_html( $this->fa( 'استفاده از این قالب', 'Use this template' ) ); ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
								<path d="M8 5v14l11-7z"/>
							</svg>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * Render an inline SVG thumbnail that represents the template category.
	 */
	private function render_thumb_svg( string $category, array $palette ): void {
		$bg     = esc_attr( $palette['bg']     ?? '#1d2327' );
		$accent = esc_attr( $palette['accent'] ?? '#f5a623' );
		$text   = esc_attr( $palette['text']   ?? '#ffffff' );

		// Default SVG for unknown categories
		$default_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true">
			<rect width="280" height="160" fill="' . $bg . '"/>
			<rect x="60" y="40" width="160" height="80" rx="8" fill="' . $accent . '" opacity=".3"/>
			<rect x="80" y="60" width="120" height="12" rx="4" fill="' . $text . '" opacity=".6"/>
			<rect x="90" y="80" width="100" height="8" rx="4" fill="' . $text . '" opacity=".3"/>
			<rect x="100" y="96" width="80" height="6" rx="3" fill="' . $text . '" opacity=".2"/>
		</svg>';

		// Map category → SVG
		$svgs = array(
			'cinematic'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><ellipse cx="140" cy="110" rx="80" ry="60" fill="' . $accent . '" opacity=".18"/><rect x="0" y="0" width="20" height="160" fill="rgba(0,0,0,.5)"/><rect x="260" y="0" width="20" height="160" fill="rgba(0,0,0,.5)"/><rect x="4" y="12" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/><rect x="4" y="36" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/><rect x="4" y="60" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/><rect x="264" y="12" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/><rect x="264" y="36" width="12" height="10" rx="2" fill="' . $accent . '" opacity=".7"/><polygon points="140,50 118,90 162,90" fill="' . $accent . '" opacity=".9"/><rect x="80" y="100" width="120" height="8" rx="4" fill="' . $text . '" opacity=".4"/></svg>',
			'ecommerce'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><rect x="80" y="30" width="120" height="80" rx="8" fill="' . $accent . '" opacity=".2"/><rect x="100" y="45" width="80" height="50" rx="4" fill="' . $text . '" opacity=".1"/><circle cx="140" cy="70" r="20" fill="' . $accent . '" opacity=".6"/><rect x="90" y="118" width="100" height="10" rx="5" fill="' . $accent . '" opacity=".8"/><rect x="110" y="134" width="60" height="6" rx="3" fill="' . $text . '" opacity=".3"/></svg>',
			'portfolio'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><rect x="20" y="20" width="110" height="70" rx="4" fill="' . $accent . '" opacity=".3"/><rect x="150" y="20" width="110" height="70" rx="4" fill="' . $accent . '" opacity=".15"/><rect x="20" y="100" width="110" height="40" rx="4" fill="' . $accent . '" opacity=".15"/><rect x="150" y="100" width="110" height="40" rx="4" fill="' . $accent . '" opacity=".3"/><rect x="30" y="110" width="70" height="6" rx="3" fill="' . $text . '" opacity=".4"/></svg>',
			'realestate'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><polygon points="140,20 60,80 220,80" fill="' . $accent . '" opacity=".4"/><rect x="70" y="80" width="140" height="60" fill="' . $accent . '" opacity=".2"/><rect x="120" y="100" width="40" height="40" fill="' . $text . '" opacity=".15"/><rect x="80" y="90" width="30" height="20" rx="2" fill="' . $text . '" opacity=".2"/><rect x="170" y="90" width="30" height="20" rx="2" fill="' . $text . '" opacity=".2"/></svg>',
			'brand'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><circle cx="140" cy="70" r="45" fill="' . $accent . '" opacity=".25"/><circle cx="140" cy="70" r="25" fill="' . $accent . '" opacity=".5"/><rect x="60" y="125" width="160" height="8" rx="4" fill="' . $text . '" opacity=".4"/><rect x="90" y="140" width="100" height="6" rx="3" fill="' . $text . '" opacity=".2"/></svg>',
			'saas'        => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 160" class="shseq-thumb-svg" aria-hidden="true"><rect width="280" height="160" fill="' . $bg . '"/><rect x="30" y="30" width="220" height="100" rx="10" fill="' . $accent . '" opacity=".1"/><rect x="30" y="30" width="220" height="24" rx="10" fill="' . $accent . '" opacity=".3"/><circle cx="48" cy="42" r="6" fill="' . $text . '" opacity=".4"/><circle cx="64" cy="42" r="6" fill="' . $text . '" opacity=".2"/><circle cx="80" cy="42" r="6" fill="' . $text . '" opacity=".2"/><rect x="40" y="65" width="200" height="6" rx="3" fill="' . $text . '" opacity=".2"/><rect x="40" y="80" width="150" height="6" rx="3" fill="' . $text . '" opacity=".15"/><rect x="40" y="95" width="180" height="6" rx="3" fill="' . $text . '" opacity=".2"/></svg>',
		);

		echo wp_kses_post( $svgs[ $category ] ?? $default_svg );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function get_categories( array $templates ): array {
		$cats = array();
		foreach ( $templates as $tpl ) {
			$slug  = $tpl['category']       ?? '';
			$label = $tpl['category_label'] ?? $slug;
			if ( $slug && ! isset( $cats[ $slug ] ) ) {
				$cats[ $slug ] = $label;
			}
		}
		return $cats;
	}

	private function site_has_pro(): bool {
		return class_exists( '\ShahreHonar\SequenceEngine\License\LicenseManager' )
			&& \ShahreHonar\SequenceEngine\License\LicenseManager::is_pro();
	}

	private function wizard_url( string $template_id, int $step = 1 ): string {
		$args = array( 'page' => SequenceWizardPage::PAGE_SLUG );
		if ( $template_id ) {
			$args['template_id'] = $template_id;
		}
		return admin_url( add_query_arg( $args, 'admin.php' ) );
	}

	// ── Handle form action ────────────────────────────────────────────────────

	public function handle_use_template(): void {
		check_admin_referer( self::NONCE, 'shseq_nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html( $this->fa( 'دسترسی غیر مجاز.', 'Permission denied.' ) ) );
		}
		$template_id = sanitize_key( $_POST['template_id'] ?? '' );
		if ( ! $template_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
		$redirect = add_query_arg(
			array( 'page' => SequenceWizardPage::PAGE_SLUG, 'template_id' => $template_id, 'from_template' => 1 ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}

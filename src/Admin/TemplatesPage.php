<?php
namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

final class TemplatesPage {

	const PAGE_SLUG = 'shseq-templates';
	const ACTION    = 'shseq_use_template';

	private $catalog;

	public function __construct( TemplateCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_use_template' ) );
	}

	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}
		$templates = $this->catalog->all();
		?>
		<div class="wrap shseq-admin shseq-templates">
			<div class="shseq-tpl-header">
				<div>
					<h1><?php esc_html_e( 'Ready Templates', 'sh-sequence-engine' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Pick a template to pre-fill the wizard with a production structure — scenes, beats, overlay slots and frame contract. You upload the frames.', 'sh-sequence-engine' ); ?></p>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SequenceWizard::PAGE_SLUG ) ); ?>" class="button button-primary">
					<?php esc_html_e( '+ Blank sequence', 'sh-sequence-engine' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $templates ) ) : ?>
			<div class="shseq-tpl-grid">
				<?php foreach ( $templates as $tpl ) : $this->render_card( $tpl ); endforeach; ?>
			</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No templates found.', 'sh-sequence-engine' ); ?></p>
			<?php endif; ?>
		</div>
		<?php $this->render_styles(); ?>
		<?php
	}

	private function render_card( array $tpl ) {
		$struct   = $tpl['structure'];
		$frames   = isset( $struct['totalFrames'] ) ? (int) $struct['totalFrames'] : 0;
		$scenes   = isset( $struct['scenes'] ) && is_array( $struct['scenes'] ) ? count( $struct['scenes'] ) : 0;
		$beats    = isset( $struct['beats']  ) && is_array( $struct['beats']  ) ? count( $struct['beats']  ) : 0;
		$overlays = isset( $struct['overlays'] ) && is_array( $struct['overlays'] ) ? $struct['overlays'] : array();

		$wizard_url = add_query_arg( array(
			'page'        => SequenceWizard::PAGE_SLUG,
			'step'        => 1,
			'template_id' => $tpl['id'],
		), admin_url( 'admin.php' ) );
		?>
		<article class="shseq-tpl-card">
			<div class="shseq-tpl-card__preview" aria-hidden="true">
				<div class="shseq-tpl-card__mock">
					<span class="shseq-tpl-card__slot--h"></span>
					<span class="shseq-tpl-card__slot--p"></span>
					<span class="shseq-tpl-card__slot--cta"></span>
				</div>
			</div>
			<div class="shseq-tpl-card__body">
				<span class="shseq-tpl-category"><?php echo esc_html( $tpl['category'] ?? '' ); ?></span>
				<h2 class="shseq-tpl-name"><?php echo esc_html( $tpl['name'] ); ?></h2>
				<p class="shseq-tpl-desc"><?php echo esc_html( $tpl['description'] ); ?></p>
				<div class="shseq-tpl-badges">
					<span class="shseq-tpl-badge"><?php echo esc_html( $frames ); ?> <?php esc_html_e( 'frames', 'sh-sequence-engine' ); ?></span>
					<span class="shseq-tpl-badge"><?php echo esc_html( $scenes ); ?> <?php esc_html_e( 'scenes', 'sh-sequence-engine' ); ?></span>
					<span class="shseq-tpl-badge"><?php echo esc_html( $beats ); ?> <?php esc_html_e( 'beats', 'sh-sequence-engine' ); ?></span>
				</div>
				<?php if ( ! empty( $overlays ) ) : ?>
				<p class="shseq-tpl-slots-label"><?php esc_html_e( 'Overlay slots:', 'sh-sequence-engine' ); ?>
					<?php foreach ( $overlays as $slot ) :
						$name = is_array( $slot ) ? ( $slot['slot'] ?? $slot['type'] ?? '?' ) : (string) $slot;
						?><code class="shseq-slot-tag"><?php echo esc_html( $name ); ?></code><?php
					endforeach; ?>
				</p>
				<?php endif; ?>
				<div class="shseq-tpl-card__actions">
					<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Use this template', 'sh-sequence-engine' ); ?> &rarr;
					</a>
				</div>
			</div>
		</article>
		<?php
	}

	public function handle_use_template() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}
		check_admin_referer( 'shseq_use_template' );
		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		wp_safe_redirect( add_query_arg( array(
			'page'        => SequenceWizard::PAGE_SLUG,
			'step'        => 1,
			'template_id' => $template_id,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render_styles() {
		echo '<style>
.shseq-templates *{box-sizing:border-box}
.shseq-tpl-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:20px 0 16px;border-bottom:1px solid #dcdcde;margin-bottom:24px}
.shseq-tpl-header h1{margin:0 0 6px}
.shseq-tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.shseq-tpl-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.shseq-tpl-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1)}
.shseq-tpl-card__preview{background:linear-gradient(135deg,#1d2327 0%,#3c434a 100%);padding:24px 20px 20px;min-height:90px;display:flex;align-items:flex-end}
.shseq-tpl-card__mock{display:flex;flex-direction:column;gap:6px;width:100%}
.shseq-tpl-card__slot--h{display:block;height:12px;width:70%;border-radius:3px;background:rgba(255,255,255,.5)}
.shseq-tpl-card__slot--p{display:block;height:8px;width:90%;border-radius:3px;background:rgba(255,255,255,.25)}
.shseq-tpl-card__slot--cta{display:block;height:28px;width:38%;border-radius:4px;background:rgba(255,255,255,.85);margin-top:4px}
.shseq-tpl-card__body{padding:18px 20px 20px;display:flex;flex-direction:column;gap:8px;flex:1}
.shseq-tpl-category{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0a4b78}
.shseq-tpl-name{margin:0;font-size:16px;font-weight:700;color:#1d2327}
.shseq-tpl-desc{margin:0;font-size:13px;color:#50575e;line-height:1.5}
.shseq-tpl-badges{display:flex;flex-wrap:wrap;gap:5px}
.shseq-tpl-badge{display:inline-block;padding:2px 8px;background:#f0f0f1;border-radius:99px;font-size:11px;color:#3c434a}
.shseq-tpl-slots-label{font-size:12px;color:#50575e;margin:0}
.shseq-slot-tag{background:#e0f0ff;color:#0a4b78;padding:1px 5px;border-radius:3px;font-size:11px;margin:0 2px}
.shseq-tpl-card__actions{margin-top:auto;padding-top:12px}
</style>';
	}
}

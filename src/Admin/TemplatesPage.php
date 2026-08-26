<?php
/**
 * Ready templates admin page.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;
use ShahreHonar\SequenceEngine\Templates\TemplateCatalog;

/**
 * Displays built-in templates and creates editable Sequence drafts from them.
 */
final class TemplatesPage {

	const PAGE_SLUG = 'shseq-templates';
	const ACTION    = 'shseq_use_template';

	/** @var TemplateCatalog */
	private $catalog;

	/** @param TemplateCatalog $catalog Template catalog. */
	public function __construct( TemplateCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	/** Register admin-post handler. */
	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_use_template' ) );
	}

	/** Render page. */
	public function render() {
		if ( ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		$templates = $this->catalog->all();
		?>
		<div class="wrap shseq-admin shseq-templates-page">
			<header class="shseq-hero">
				<div>
					<h1><?php echo esc_html__( 'Ready Templates', 'sh-sequence-engine' ); ?></h1>
					<p class="shseq-byline"><?php echo esc_html__( 'Choose a production-ready structure, create an editable draft, then adapt its scenes, beats and handoff settings to your project.', 'sh-sequence-engine' ); ?></p>
				</div>
			</header>

			<?php $this->render_notice(); ?>

			<div class="shseq-template-grid">
				<?php foreach ( $templates as $template ) : ?>
					<article class="shseq-template-card">
						<?php $this->render_production_sheet_preview( $template ); ?>
						<div class="shseq-template-card__body">
							<span class="shseq-kicker"><?php echo esc_html( $template['category'] ); ?></span>
							<h2><?php echo esc_html( $template['name'] ); ?></h2>
							<p><?php echo esc_html( $template['description'] ); ?></p>
							<ul class="shseq-template-facts">
								<li><strong><?php echo esc_html( (string) $template['structure']['totalFrames'] ); ?></strong><span><?php echo esc_html__( 'Frames', 'sh-sequence-engine' ); ?></span></li>
								<li><strong><?php echo esc_html( (string) count( $template['structure']['beats'] ) ); ?></strong><span><?php echo esc_html__( 'Beats', 'sh-sequence-engine' ); ?></span></li>
								<li><strong><?php echo esc_html( (string) count( $template['structure']['scenes'] ) ); ?></strong><span><?php echo esc_html__( 'Scenes', 'sh-sequence-engine' ); ?></span></li>
								<li><strong><?php echo esc_html( (string) $template['structure']['referenceFrame'] ); ?></strong><span><?php echo esc_html__( 'Master frame', 'sh-sequence-engine' ); ?></span></li>
							</ul>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
								<input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>">
								<?php wp_nonce_field( self::ACTION . ':' . $template['id'], '_shseq_template_nonce' ); ?>
								<button type="submit" class="button button-primary button-hero"><?php echo esc_html__( 'Use this template', 'sh-sequence-engine' ); ?></button>
							</form>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** Create an editable draft from a selected template. */
	public function handle_use_template() {
		if ( ! current_user_can( 'create_shseq_sequences' ) && ! current_user_can( 'edit_shseq_sequences' ) ) {
			wp_die( esc_html__( 'You do not have permission to create sequences.', 'sh-sequence-engine' ) );
		}

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		$nonce       = isset( $_POST['_shseq_template_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_shseq_template_nonce'] ) ) : '';

		if ( ! $template_id || ! wp_verify_nonce( $nonce, self::ACTION . ':' . $template_id ) ) {
			wp_die( esc_html__( 'The template request could not be verified.', 'sh-sequence-engine' ) );
		}

		$template = $this->catalog->get( $template_id );
		if ( ! $template ) {
			// SECURITY FIX [SEC-006]: Add explicit return after redirect_with_notice().
			// redirect_with_notice() calls wp_safe_redirect() + exit, so execution
			// never reaches the next line in practice. However, the missing return
			// means static analysis tools (and human reviewers) cannot statically
			// verify that the code path terminates. A future refactor that removes
			// the exit from redirect_with_notice() would silently introduce a logic
			// bug where code after the call continues running. Adding an explicit
			// return enforces the contract at the call site regardless of the
			// implementation of the callee.
			$this->redirect_with_notice( 'template-not-found' );
			return; // Defensive: redirect_with_notice() exits, but be explicit.
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => SequencePostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $template['name'] ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->redirect_with_notice( 'create-failed' );
			return; // Defensive.
		}

		update_post_meta( $post_id, '_shseq_template_id', sanitize_key( $template['id'] ) );
		update_post_meta( $post_id, '_shseq_template_version', absint( $template['version'] ) );
		update_post_meta( $post_id, '_shseq_structure', $template['structure'] );

		$edit_url = get_edit_post_link( $post_id, 'raw' );
		if ( ! $edit_url ) {
			$this->redirect_with_notice( 'created' );
			return; // Defensive.
		}

		wp_safe_redirect( add_query_arg( 'shseq_template_created', '1', $edit_url ) );
		exit;
	}

	/** Render simple status notice. */
	private function render_notice() {
		$notice = isset( $_GET['shseq_notice'] ) ? sanitize_key( wp_unslash( $_GET['shseq_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
		if ( ! $notice ) {
			return;
		}

		$messages = array(
			'created'            => __( 'Template draft created.', 'sh-sequence-engine' ),
			'template-not-found' => __( 'The selected template was not found.', 'sh-sequence-engine' ),
			'create-failed'      => __( 'The sequence draft could not be created.', 'sh-sequence-engine' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = 'created' === $notice ? 'notice notice-success' : 'notice notice-error';
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $messages[ $notice ] ) );
	}

	/** Redirect back to templates with a small status code. */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'shseq_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render a generic, brand-neutral production-sheet miniature.
	 *
	 * @param array<string,mixed> $template Template definition.
	 */
	private function render_production_sheet_preview( $template ) {
		$structure    = $template['structure'];
		$total_frames = (int) $structure['totalFrames'];
		$beat_count   = count( $structure['beats'] );
		$scene_count  = count( $structure['scenes'] );
		?>
		<div class="shseq-sheet-preview" aria-hidden="true">
			<div class="shseq-sheet-preview__top">
				<span>STORYBOARD PRODUCTION SHEET</span>
				<small><?php echo esc_html( $total_frames . ' FRAMES · ' . $beat_count . ' BEATS · ' . $scene_count . ' SCENES' ); ?></small>
			</div>
			<div class="shseq-sheet-preview__grid">
				<div class="shseq-sheet-preview__rules">
					<strong>RED LINES</strong>
					<i></i><i></i><i></i><i></i><i></i>
				</div>
				<div class="shseq-sheet-preview__master">
					<span>REFERENCE D — FRAME <?php echo esc_html( (string) $structure['referenceFrame'] ); ?></span>
					<div class="shseq-sheet-preview__studio">
						<b></b><b></b><b></b><b></b><b></b>
					</div>
				</div>
				<div class="shseq-sheet-preview__side">
					<div>GOLDEN MASTER<br><strong>FRAME <?php echo esc_html( (string) $structure['goldenFrame'] ); ?></strong></div>
					<div>LAYERS<br><em>09</em></div>
					<div>HANDOFF<br><em>READY</em></div>
				</div>
			</div>
			<div class="shseq-sheet-preview__timeline">
				<?php foreach ( array_slice( $structure['beats'], 0, 12 ) as $beat ) : ?>
					<span style="--w:<?php echo esc_attr( (string) max( 1, $beat['endFrame'] - $beat['startFrame'] + 1 ) ); ?>"></span>
				<?php endforeach; ?>
			</div>
			<div class="shseq-sheet-preview__frames">
				<?php foreach ( $structure['keyframes'] as $keyframe ) : ?>
					<div><strong><?php echo esc_html( $keyframe['key'] ); ?></strong><span><?php echo esc_html( sprintf( '%03d', $keyframe['frame'] ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

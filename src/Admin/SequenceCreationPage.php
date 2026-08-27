<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardStep;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * SequenceCreationPage — صفحه ادمین برای wizard ساخت سکانس
 *
 * URL: /wp-admin/post.php?post=ID&action=edit&shseq_wizard=1
 * یا متا باکس داخل edit post
 */
final class SequenceCreationPage
{
    private const SCRIPT_HANDLE = 'shseq-wizard';
    private const STYLE_HANDLE  = 'shseq-wizard-css';

    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            id:           'shseq-creation-wizard',
            title:        'ساخت سکانس — Wizard',
            callback:     [$this, 'renderMetaBox'],
            screen:       'shseq_sequence',
            context:      'normal',
            priority:     'high',
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post;

        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        if (! $post || $post->post_type !== 'shseq_sequence') {
            return;
        }

        $pluginUrl = plugins_url('/', SHSEQ_PLUGIN_FILE);
        $version   = SHSEQ_VERSION;

        // CSS
        wp_enqueue_style(
            self::STYLE_HANDLE,
            $pluginUrl . 'assets/admin/sequence-wizard/wizard.css',
            [],
            $version,
        );

        // Overlay editor
        wp_enqueue_script(
            'shseq-overlay-editor',
            $pluginUrl . 'assets/admin/sequence-wizard/overlay-editor.js',
            [],
            $version,
            true,
        );

        // Main wizard
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $pluginUrl . 'assets/admin/sequence-wizard/wizard.js',
            ['shseq-overlay-editor'],
            $version,
            true,
        );

        // WP Media
        wp_enqueue_media();

        // Config برای JS
        $sequenceId    = $post->ID;
        $state         = $this->repo->findBySequenceId($sequenceId);
        $stateArr      = $state->toArray();

        // URL پیش‌نمایش
        $previewToken  = wp_create_nonce("shseq_preview_{$sequenceId}");
        $previewUrl    = add_query_arg([
            'shseq_preview' => $sequenceId,
            '_shseq_token'  => $previewToken,
        ], home_url('/'));

        // Clean background URL
        $cleanBgUrl = '';
        if ($state->getCleanBackgroundId()) {
            $cleanBgUrl = wp_get_attachment_url($state->getCleanBackgroundId()) ?: '';
        } elseif ($state->getGoldenMasterAttachmentId()) {
            $cleanBgUrl = wp_get_attachment_url($state->getGoldenMasterAttachmentId()) ?: '';
        }

        wp_localize_script(self::SCRIPT_HANDLE, 'shseqWizardConfig', [
            'sequenceId'       => $sequenceId,
            'currentStep'      => $stateArr['step'],
            'currentMode'      => $stateArr['mode'],
            'overlayItems'     => $stateArr['overlay_items'],
            'contentSteps'     => $stateArr['content_steps'],
            'frames'           => array_map('wp_get_attachment_url', $stateArr['frame_attachment_ids']),
            'cleanBackgroundUrl' => $cleanBgUrl,
            'previewUrl'       => $previewUrl,
            'errorMessage'     => $stateArr['error_message'],
            'restUrl'          => get_rest_url(null, 'shseq/v1'),
            'restNonce'        => wp_create_nonce('wp_rest'),
            'uploadUrl'        => get_rest_url(null, 'wp/v2/media'),
        ]);
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $state    = $this->repo->findBySequenceId($post->ID);
        $stateArr = $state->toArray();
        ?>
        <div id="shseq-wizard">
          <div class="shseq-progress-track" id="shseq-progress-track">
            <!-- پر می‌شود توسط JS -->
          </div>
          <div id="shseq-panel-wrap">
            <!-- Loading state اولیه -->
            <div class="shseq-panel">
              <p style="text-align:center;color:#6b7280;padding:20px">در حال بارگذاری wizard...</p>
            </div>
          </div>
        </div>

        <?php if ($stateArr['step'] === WizardStep::FAILED->value): ?>
        <div class="notice notice-error" style="margin:10px 0">
          <p>⚠️ خطا در آخرین عملیات: <?php echo esc_html($stateArr['error_message']); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($stateArr['step'] === WizardStep::PUBLISHED->value): ?>
        <div class="notice notice-success" style="margin:10px 0">
          <p>✓ این سکانس منتشر شده است. <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank">مشاهده ←</a></p>
        </div>
        <?php endif; ?>
        <?php
    }
}

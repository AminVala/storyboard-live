<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\HTTP\REST;

/**
 * PreviewController — مدیریت پیش‌نمایش سکانس در ۳ viewport
 *
 * URL: /wp-json/shseq/v1/preview/{sequence_id}?viewport=mobile|tablet|desktop
 *
 * این endpoint یک HTML کامل برمی‌گرداند که دقیقاً همان چیزی است
 * که در frontend به کاربر نهایی نمایش داده می‌شود.
 */
final class PreviewController
{
    private const NAMESPACE = 'shseq/v1';

    /** ابعاد viewport‌ها */
    private const VIEWPORTS = [
        'mobile'  => ['width' => 375,  'height' => 812,  'label' => 'موبایل'],
        'tablet'  => ['width' => 768,  'height' => 1024, 'label' => 'تبلت'],
        'desktop' => ['width' => 1440, 'height' => 900,  'label' => 'دسکتاپ'],
    ];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);

        // همچنین query param برای iframe
        add_action('template_redirect', [$this, 'handlePreviewQuery']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/preview/(?P<sequence_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getPreviewData'],
            'permission_callback' => fn () => current_user_can('edit_posts'),
            'args' => [
                'sequence_id' => [
                    'validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
                'viewport' => [
                    'type'              => 'string',
                    'enum'              => array_keys(self::VIEWPORTS),
                    'default'           => 'desktop',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * برمی‌گرداند: فریم URLs + content steps + viewport info
     * JS wizard از این برای نمایش preview iframe استفاده می‌کند
     */
    public function getPreviewData(\WP_REST_Request $request): \WP_REST_Response
    {
        $sequenceId = $request->get_param('sequence_id');
        $viewport   = $request->get_param('viewport');

        // frame attachment IDs
        $frameIds = get_post_meta($sequenceId, '_shseq_frames', true);
        if (! is_array($frameIds) || empty($frameIds)) {
            return new \WP_REST_Response(['error' => 'No frames found'], 404);
        }

        $frames = array_map(function (int $id): array {
            return [
                'id'  => $id,
                'url' => wp_get_attachment_url($id),
            ];
        }, $frameIds);

        // content steps
        $contentStepsRaw = get_post_meta($sequenceId, '_shseq_content_steps', true);
        $contentSteps    = $contentStepsRaw ? json_decode($contentStepsRaw, true) : [];

        // overlay items
        $wizardRaw = get_post_meta($sequenceId, '_shseq_wizard_state', true);
        $overlayItems = $wizardRaw['overlay_items'] ?? [];

        return new \WP_REST_Response([
            'sequence_id'   => $sequenceId,
            'preview_url'   => $this->buildPreviewUrl($sequenceId),
            'viewport'      => self::VIEWPORTS[$viewport],
            'frames'        => $frames,
            'frame_count'   => count($frames),
            'content_steps' => $contentSteps,
            'overlay_items' => $overlayItems,
        ]);
    }

    /**
     * URL پیش‌نمایش iframe — با nonce برای امنیت
     */
    private function buildPreviewUrl(int $sequenceId): string
    {
        $token = wp_create_nonce("shseq_preview_{$sequenceId}");
        return add_query_arg([
            'shseq_preview' => $sequenceId,
            '_shseq_token'  => $token,
        ], home_url('/'));
    }

    /**
     * رهگیری ?shseq_preview=ID در frontend
     * template را با preview mode رندر می‌کند
     */
    public function handlePreviewQuery(): void
    {
        $sequenceId = absint($_GET['shseq_preview'] ?? 0);
        $token      = sanitize_text_field($_GET['_shseq_token'] ?? '');

        if (! $sequenceId || ! $token) {
            return;
        }

        if (! wp_verify_nonce($token, "shseq_preview_{$sequenceId}")) {
            wp_die('Invalid preview token', 403);
        }

        if (! current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }

        // رندر preview page — دقیقاً همان output frontend
        $this->renderPreviewPage($sequenceId);
        exit;
    }

    private function renderPreviewPage(int $sequenceId): void
    {
        $frameIds = get_post_meta($sequenceId, '_shseq_frames', true);
        if (! is_array($frameIds)) {
            $frameIds = [];
        }

        $frames       = array_map('wp_get_attachment_url', $frameIds);
        $contentRaw   = get_post_meta($sequenceId, '_shseq_content_steps', true);
        $contentSteps = $contentRaw ? json_decode($contentRaw, true) : [];
        $title        = get_the_title($sequenceId);

        // JSON data برای JS engine
        $engineData = wp_json_encode([
            'frames'       => $frames,
            'contentSteps' => $contentSteps,
            'totalFrames'  => count($frames),
        ]);

        $cssUrl = plugins_url('assets/frontend/runtime-v2.css', SHSEQ_PLUGIN_FILE);
        $jsUrl  = plugins_url('assets/frontend/single-image-engine.min.js', SHSEQ_PLUGIN_FILE);

        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html($title) . ' — پیش‌نمایش</title>';
        echo '<link rel="stylesheet" href="' . esc_url($cssUrl) . '">';
        echo '<style>body{margin:0;background:#000;overflow:hidden}</style>';
        echo '</head><body>';
        echo '<div id="shseq-preview-badge" style="position:fixed;top:10px;left:10px;'
           . 'background:rgba(0,0,0,.7);color:#fff;padding:6px 12px;border-radius:4px;'
           . 'font-family:sans-serif;font-size:12px;z-index:9999">پیش‌نمایش</div>';
        echo '<div class="shseq-sequence-container" data-engine=\'' . $engineData . '\'></div>';
        echo '<script src="' . esc_url($jsUrl) . '"></script>';
        echo '</body></html>';
    }
}

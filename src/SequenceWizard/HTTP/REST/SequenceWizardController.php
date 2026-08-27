<?php
/**
 * SequenceWizardController — REST API برای Sequence Wizard (V3 Final)
 *
 * Namespace: /wp-json/shseq/v1/wizard/{id}/*
 *
 * GET    /wizard/{id}           → state کامل
 * POST   /wizard/{id}/mode      → انتخاب حالت
 * POST   /wizard/{id}/upload    → آپلود Last Frame (Mode 1)
 * POST   /wizard/{id}/overlay   → auto-save یا confirm overlay
 * POST   /wizard/{id}/frames    → آپلود فریم‌های آماده (Mode 2)
 * POST   /wizard/{id}/ai-prompt → prompt AI (Mode 3)
 * GET    /wizard/{id}/bg-status → آیا clean bg آماده است؟ (polling)
 * GET    /wizard/{id}/job       → وضعیت async job
 * POST   /wizard/{id}/config    → save یا confirm frame config
 * POST   /wizard/{id}/content   → ذخیره content steps
 * POST   /wizard/{id}/publish   → publish
 * GET    /wizard/presets        → لیست motion presets
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\HTTP\REST
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\HTTP\REST;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration\SequenceFrameGenerator;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardRepository;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision\OpenAIVisionService;

final class SequenceWizardController
{
    private const NS = 'shseq/v1';

    private const PRESETS = [
        'zoom_out_center' => 'زوم به خارج از مرکز',
        'zoom_pan_lr'     => 'زوم + چپ به راست',
        'zoom_pan_rl'     => 'زوم + راست به چپ',
        'zoom_pan_tb'     => 'زوم + بالا به پایین',
        'blur_reveal'     => 'نمایش از blur به شارپ',
        'pan_from_left'   => 'ورود از چپ',
        'pan_from_right'  => 'ورود از راست',
        'pan_from_top'    => 'ورود از بالا',
    ];

    public function __construct(
        private readonly WizardRepository     $repo,
        private readonly OpenAIVisionService  $vision,
        private readonly SequenceFrameGenerator $generator,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $id  = ['sequence_id' => ['validate_callback' => fn ($v) => is_numeric($v) && $v > 0, 'sanitize_callback' => 'absint']];

        $routes = [
            ['/wizard/(?P<sequence_id>\d+)',            'GET',  'getState'],
            ['/wizard/(?P<sequence_id>\d+)/mode',       'POST', 'selectMode'],
            ['/wizard/(?P<sequence_id>\d+)/upload',     'POST', 'uploadLastFrame'],
            ['/wizard/(?P<sequence_id>\d+)/overlay',    'POST', 'saveOverlay'],
            ['/wizard/(?P<sequence_id>\d+)/frames',     'POST', 'uploadFrames'],
            ['/wizard/(?P<sequence_id>\d+)/ai-prompt',  'POST', 'aiPrompt'],
            ['/wizard/(?P<sequence_id>\d+)/bg-status',  'GET',  'bgStatus'],
            ['/wizard/(?P<sequence_id>\d+)/job',        'GET',  'jobStatus'],
            ['/wizard/(?P<sequence_id>\d+)/config',     'POST', 'saveConfig'],
            ['/wizard/(?P<sequence_id>\d+)/content',    'POST', 'saveContent'],
            ['/wizard/(?P<sequence_id>\d+)/publish',    'POST', 'publish'],
            ['/wizard/presets',                         'GET',  'getPresets'],
        ];

        foreach ($routes as [$path, $method, $cb]) {
            register_rest_route(self::NS, $path, [
                'methods'             => $method,
                'callback'            => [$this, $cb],
                'permission_callback' => [$this, 'checkPermission'],
            ]);
        }
    }

    /* ─────────────────────────── Handlers ─────────────────────────── */

    public function getState(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $agg = $this->repo->find($req->get_param('sequence_id'));
            $data = $agg->toArray();

            // URL‌ها را اضافه می‌کنیم
            if ($agg->getOriginalAttachmentId()) {
                $data['original_url'] = wp_get_attachment_url($agg->getOriginalAttachmentId());
            }
            if ($agg->getCleanAttachmentId()) {
                $data['clean_url'] = wp_get_attachment_url($agg->getCleanAttachmentId());
            }

            // canvas باید از کدام URL استفاده کند؟
            $data['canvas_bg_url'] = $agg->getCleanAttachmentId()
                ? wp_get_attachment_url($agg->getCleanAttachmentId())
                : ($agg->getOriginalAttachmentId() ? wp_get_attachment_url($agg->getOriginalAttachmentId()) : null);

            $data['inpainting_pending'] = $agg->isInpaintingPending();

            return $data;
        });
    }

    public function selectMode(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $mode = WizardMode::from($req->get_param('mode') ?? '');
            $agg  = $this->repo->find($req->get_param('sequence_id'));
            $agg->selectMode($mode);
            $this->repo->save($agg);
            return ['ok' => true, 'step' => $agg->getStep()->value];
        });
    }

    public function uploadLastFrame(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $postId       = $req->get_param('sequence_id');
            $attachmentId = (int) ($req->get_param('attachment_id') ?? 0);

            if ($attachmentId <= 0) {
                throw new \InvalidArgumentException('attachment_id required');
            }

            $agg = $this->repo->find($postId);
            $agg->uploadLastFrame($attachmentId);
            $this->repo->save($agg);

            // آغاز async extraction + inpainting
            $this->vision->scheduleExtraction($postId, $attachmentId);

            return [
                'ok'          => true,
                'step'        => $agg->getStep()->value,
                'original_url'=> wp_get_attachment_url($attachmentId),
                'note'        => 'Text extraction started in background',
            ];
        });
    }

    public function saveOverlay(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $items   = (array) ($req->get_param('items')   ?? []);
            $confirm = (bool)  ($req->get_param('confirm') ?? false);

            $agg = $this->repo->find($req->get_param('sequence_id'));

            if ($confirm) {
                $agg->confirmOverlay($items);
            } else {
                $agg->saveOverlayItems($items);
            }

            $this->repo->save($agg);
            return ['ok' => true, 'step' => $agg->getStep()->value];
        });
    }

    public function uploadFrames(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $ids = (array) ($req->get_param('attachment_ids') ?? []);
            $agg = $this->repo->find($req->get_param('sequence_id'));
            $agg->uploadFrames($ids);
            $this->repo->save($agg);

            update_post_meta($req->get_param('sequence_id'), '_shseq_frames', $agg->getFrameIds());

            return ['ok' => true, 'step' => $agg->getStep()->value];
        });
    }

    public function aiPrompt(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $prompt = (string) ($req->get_param('prompt') ?? '');
            $agg    = $this->repo->find($req->get_param('sequence_id'));
            $agg->setAiPrompt($prompt);
            $this->repo->save($agg);
            // AI generation جداگانه schedule می‌شود
            do_action('shseq_schedule_ai_generation', $req->get_param('sequence_id'), $prompt);
            return ['ok' => true, 'step' => $agg->getStep()->value];
        });
    }

    /**
     * GET /bg-status — آیا clean background آماده است؟
     * Canvas editor این را هر ۳ ثانیه poll می‌کند
     */
    public function bgStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $agg = $this->repo->find($req->get_param('sequence_id'));
            return [
                'clean_ready'     => $agg->getCleanAttachmentId() !== null,
                'clean_url'       => $agg->getCleanAttachmentId()
                    ? wp_get_attachment_url($agg->getCleanAttachmentId())
                    : null,
                'inpaint_pending' => $agg->isInpaintingPending(),
                'step'            => $agg->getStep()->value,
                'overlay_items'   => array_map(fn ($i) => $i->toArray(), $agg->getOverlayItems()),
            ];
        });
    }

    /** GET /job — وضعیت async job */
    public function jobStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $agg = $this->repo->find($req->get_param('sequence_id'));
            return [
                'step'        => $agg->getStep()->value,
                'step_label'  => $agg->getStep()->label(),
                'job_id'      => $agg->getJobId(),
                'progress'    => $agg->getJobProgress(),
                'checkpoint'  => $agg->getFrameCheckpoint(),
                'frame_count' => count($agg->getFrameIds()),
                'error'       => $agg->getError(),
                'is_async'    => $agg->getStep()->isAsync(),
            ];
        });
    }

    /** POST /config — ذخیره یا تأیید frame config */
    public function saveConfig(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $postId  = $req->get_param('sequence_id');
            $confirm = (bool) ($req->get_param('confirm') ?? false);

            $config = [
                'preset'      => sanitize_text_field($req->get_param('preset')      ?? 'zoom_out_center'),
                'zoom_factor' => (float)($req->get_param('zoom_factor') ?? 1.5),
                'pan_x_rel'   => (float)($req->get_param('pan_x_rel')  ?? 0.0),
                'pan_y_rel'   => (float)($req->get_param('pan_y_rel')  ?? 0.0),
                'blur_px'     => (int)  ($req->get_param('blur_px')    ?? 0),
                'frame_count' => (int)  ($req->get_param('frame_count') ?? 36),
                'easing'      => sanitize_text_field($req->get_param('easing') ?? 'ease_in_out'),
            ];

            $agg = $this->repo->find($postId);

            if ($confirm) {
                $agg->confirmFrameConfig($config);
                $this->repo->save($agg);

                $sourceId = $agg->getGenerationSourceId();
                if ($sourceId === null) {
                    throw new \RuntimeException('No source image for frame generation');
                }

                $jobId = 'shseq-' . $postId . '-' . time();
                $agg->startFrameJob($jobId);
                $this->repo->save($agg);

                $this->generator->schedule($postId, $sourceId, $config, 0);

            } else {
                $agg->saveFrameConfig($config);
                $this->repo->save($agg);
            }

            return ['ok' => true, 'step' => $agg->getStep()->value, 'config' => $config];
        });
    }

    public function saveContent(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $postId = $req->get_param('sequence_id');
            $steps  = (array) ($req->get_param('steps')   ?? []);
            $confirm= (bool)  ($req->get_param('confirm') ?? false);

            // sanitize
            $sanitized = array_map(function (array $s): array {
                return [
                    'frame_index' => (int)   ($s['frame_index'] ?? 0),
                    'html'        => wp_kses_post($s['html']      ?? ''),
                    'css_class'   => sanitize_html_class($s['css_class'] ?? ''),
                ];
            }, $steps);

            $agg = $this->repo->find($postId);
            $agg->saveContentSteps($sanitized);

            if ($confirm) {
                // content steps تأیید → برای سازگاری با frontend sync می‌کنیم
                update_post_meta($postId, '_shseq_content_steps', wp_json_encode($sanitized));
            }

            $this->repo->save($agg);
            return ['ok' => true];
        });
    }

    public function publish(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->wrap(function () use ($req) {
            $postId = $req->get_param('sequence_id');
            $agg    = $this->repo->find($postId);
            $agg->publish();
            $this->repo->save($agg);

            wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
            do_action('shseq_sequence_published', $postId);

            return ['ok' => true, 'post_url' => get_permalink($postId)];
        });
    }

    public function getPresets(\WP_REST_Request $req): \WP_REST_Response
    {
        $out = [];
        foreach (self::PRESETS as $value => $label) {
            $defaults = SequenceFrameGenerator::PRESETS[$value] ?? [];
            $out[]    = ['value' => $value, 'label' => $label, 'defaults' => $defaults];
        }
        return new \WP_REST_Response($out, 200);
    }

    public function checkPermission(): bool
    {
        return current_user_can('edit_posts');
    }

    /* ─────────────────────────── Helper ────────────────────────────── */

    private function wrap(callable $fn): \WP_REST_Response
    {
        try {
            return new \WP_REST_Response($fn(), 200);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 422);
        } catch (\LogicException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            error_log('[shseq] Wizard error: ' . $e->getMessage());
            return new \WP_REST_Response(['error' => 'Internal server error'], 500);
        }
    }
}

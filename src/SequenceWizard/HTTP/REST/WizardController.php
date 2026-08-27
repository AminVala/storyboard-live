<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\HTTP\REST;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\CommandBus;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\GenerateWithAICommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\PublishSequenceCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveContentStepsCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveOverlayLayoutCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveStartConfigCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SelectModeCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadFramesCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadGoldenMasterCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\MotionPreset;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\StartFrameConfig;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * WizardController — REST API برای Sequence Wizard (V3)
 *
 * Namespace: /wp-json/shseq/v1/wizard/{sequence_id}/*
 *
 * GET    /wizard/{id}              → وضعیت فعلی
 * POST   /wizard/{id}/mode         → انتخاب حالت
 * POST   /wizard/{id}/last-frame   → آپلود Last Frame (Mode 1)
 * POST   /wizard/{id}/overlay      → ذخیره/تأیید overlay
 * POST   /wizard/{id}/start-config → ذخیره/تأیید StartFrameConfig
 * POST   /wizard/{id}/frames       → آپلود فریم‌های آماده (Mode 2)
 * POST   /wizard/{id}/ai-prompt    → prompt + شروع AI generation (Mode 3)
 * GET    /wizard/{id}/job-status   → وضعیت job async + progress
 * POST   /wizard/{id}/content      → ذخیره content steps
 * POST   /wizard/{id}/publish      → publish نهایی
 * GET    /wizard/{id}/presets      → لیست MotionPreset‌ها برای UI
 */
final class WizardController
{
    private const NAMESPACE = 'shseq/v1';

    public function __construct(
        private readonly CommandBus            $bus,
        private readonly WizardStateRepository $repo,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $idArg = [
            'sequence_id' => [
                'validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ],
        ];

        $routes = [
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)',
                'method' => 'GET',
                'cb'     => 'getState',
                'args'   => $idArg,
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/mode',
                'method' => 'POST',
                'cb'     => 'selectMode',
                'args'   => array_merge($idArg, [
                    'mode' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => array_column(WizardMode::cases(), 'value'),
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/last-frame',
                'method' => 'POST',
                'cb'     => 'uploadLastFrame',
                'args'   => array_merge($idArg, [
                    'attachment_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/overlay',
                'method' => 'POST',
                'cb'     => 'saveOverlay',
                'args'   => array_merge($idArg, [
                    'items'   => ['required' => true, 'type' => 'array'],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/start-config',
                'method' => 'POST',
                'cb'     => 'saveStartConfig',
                'args'   => array_merge($idArg, [
                    'preset'      => ['required' => true, 'type' => 'string', 'enum' => array_column(MotionPreset::cases(), 'value')],
                    'zoom_factor' => ['type' => 'number', 'default' => 1.5, 'minimum' => 1.0, 'maximum' => 3.0],
                    'pan_x_rel'   => ['type' => 'number', 'default' => 0.0, 'minimum' => -0.5, 'maximum' => 0.5],
                    'pan_y_rel'   => ['type' => 'number', 'default' => 0.0, 'minimum' => -0.5, 'maximum' => 0.5],
                    'blur_px'     => ['type' => 'number', 'default' => 0.0, 'minimum' => 0.0, 'maximum' => 30.0],
                    'frame_count' => ['type' => 'integer', 'default' => 36, 'minimum' => 8, 'maximum' => 120],
                    'easing'      => ['type' => 'string', 'default' => 'ease_in_out', 'enum' => ['ease_in_out', 'ease_out', 'ease_in', 'linear']],
                    'confirm'     => ['type' => 'boolean', 'default' => false],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/frames',
                'method' => 'POST',
                'cb'     => 'uploadFrames',
                'args'   => array_merge($idArg, [
                    'attachment_ids' => ['required' => true, 'type' => 'array'],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/ai-prompt',
                'method' => 'POST',
                'cb'     => 'generateWithAI',
                'args'   => array_merge($idArg, [
                    'prompt' => ['required' => true, 'type' => 'string', 'minLength' => 10],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/job-status',
                'method' => 'GET',
                'cb'     => 'getJobStatus',
                'args'   => $idArg,
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/content',
                'method' => 'POST',
                'cb'     => 'saveContent',
                'args'   => array_merge($idArg, [
                    'steps'   => ['required' => true, 'type' => 'array'],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                ]),
            ],
            [
                'path'   => '/wizard/(?P<sequence_id>\d+)/publish',
                'method' => 'POST',
                'cb'     => 'publish',
                'args'   => $idArg,
            ],
            [
                'path'   => '/wizard/presets',
                'method' => 'GET',
                'cb'     => 'getPresets',
                'args'   => [],
            ],
        ];

        foreach ($routes as $r) {
            register_rest_route(self::NAMESPACE, $r['path'], [
                'methods'             => $r['method'],
                'callback'            => [$this, $r['cb']],
                'permission_callback' => [$this, 'checkPermission'],
                'args'                => $r['args'],
            ]);
        }
    }

    /* ─────────────────────────── Handlers ─────────────────────────── */

    public function getState(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $state = $this->repo->findBySequenceId($req->get_param('sequence_id'));
            return $state->toArray();
        });
    }

    public function selectMode(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new SelectModeCommand(
                sequencePostId: $req->get_param('sequence_id'),
                mode:           WizardMode::from($req->get_param('mode')),
            ));
            return ['ok' => true];
        });
    }

    public function uploadLastFrame(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new UploadGoldenMasterCommand(
                sequencePostId: $req->get_param('sequence_id'),
                attachmentId:   $req->get_param('attachment_id'),
            ));
            return ['ok' => true, 'message' => 'Text extraction scheduled'];
        });
    }

    public function saveOverlay(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new SaveOverlayLayoutCommand(
                sequencePostId: $req->get_param('sequence_id'),
                items:          (array) $req->get_param('items'),
                confirm:        (bool)  $req->get_param('confirm'),
            ));
            return ['ok' => true];
        });
    }

    public function saveStartConfig(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $preset = MotionPreset::from($req->get_param('preset'));

            // اگر پارامترهای custom نبودند، از defaults پریست استفاده کن
            $defaults = $preset->defaults();

            $config = new StartFrameConfig(
                preset:     $preset,
                zoomFactor: (float)($req->get_param('zoom_factor') ?? $defaults['zoom']),
                panXRel:    (float)($req->get_param('pan_x_rel')   ?? $defaults['pan_x']),
                panYRel:    (float)($req->get_param('pan_y_rel')   ?? $defaults['pan_y']),
                blurPx:     (float)($req->get_param('blur_px')     ?? $defaults['blur']),
                frameCount: (int)  ($req->get_param('frame_count') ?? 36),
                easing:     (string)($req->get_param('easing')     ?? 'ease_in_out'),
            );

            $this->bus->dispatch(new SaveStartConfigCommand(
                sequencePostId: $req->get_param('sequence_id'),
                config:         $config,
                confirm:        (bool) $req->get_param('confirm'),
            ));

            return ['ok' => true, 'config' => $config->toArray()];
        });
    }

    public function uploadFrames(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new UploadFramesCommand(
                sequencePostId: $req->get_param('sequence_id'),
                attachmentIds:  (array) $req->get_param('attachment_ids'),
            ));
            return ['ok' => true];
        });
    }

    public function generateWithAI(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new GenerateWithAICommand(
                sequencePostId: $req->get_param('sequence_id'),
                prompt:         $req->get_param('prompt'),
            ));
            return ['ok' => true, 'message' => 'AI generation scheduled'];
        });
    }

    public function getJobStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $state = $this->repo->findBySequenceId($req->get_param('sequence_id'));
            return [
                'step'             => $state->getStep()->value,
                'step_label'       => $state->getStep()->label(),
                'job_id'           => $state->getJobId(),
                'progress'         => $state->getJobProgress(),
                'frame_checkpoint' => $state->getFrameCheckpoint(),
                'frame_count'      => count($state->getFrameAttachmentIds()),
                'error'            => $state->getErrorMessage(),
                'is_async'         => $state->getStep()->isAsync(),
            ];
        });
    }

    public function saveContent(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new SaveContentStepsCommand(
                sequencePostId: $req->get_param('sequence_id'),
                steps:          (array) $req->get_param('steps'),
                confirm:        (bool)  $req->get_param('confirm'),
            ));
            return ['ok' => true];
        });
    }

    public function publish(\WP_REST_Request $req): \WP_REST_Response
    {
        return $this->try(function () use ($req) {
            $this->bus->dispatch(new PublishSequenceCommand(
                sequencePostId: $req->get_param('sequence_id'),
            ));
            return [
                'ok'       => true,
                'post_url' => get_permalink($req->get_param('sequence_id')),
            ];
        });
    }

    /**
     * لیست MotionPreset‌ها برای پر کردن dropdown در UI
     */
    public function getPresets(\WP_REST_Request $req): \WP_REST_Response
    {
        $presets = [];
        foreach (MotionPreset::cases() as $preset) {
            $presets[] = [
                'value'    => $preset->value,
                'label'    => $preset->label(),
                'hasZoom'  => $preset->hasZoom(),
                'hasBlur'  => $preset->hasBlur(),
                'defaults' => $preset->defaults(),
            ];
        }
        return new \WP_REST_Response($presets, 200);
    }

    /* ─────────────────────────── Helpers ───────────────────────────── */

    public function checkPermission(): bool
    {
        return current_user_can('edit_posts');
    }

    private function try(callable $fn): \WP_REST_Response
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

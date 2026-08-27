<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\HTTP\REST;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\CommandBus;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\GenerateWithAICommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\PublishSequenceCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveContentStepsCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveOverlayLayoutCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SelectModeCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadFramesCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadGoldenMasterCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * REST API controller برای Sequence Wizard
 *
 * Namespace: /wp-json/shseq/v1/wizard/{sequence_id}/*
 *
 * Endpoints:
 * GET    /wizard/{id}             → وضعیت فعلی
 * POST   /wizard/{id}/mode        → انتخاب حالت
 * POST   /wizard/{id}/golden-master → آپلود PNG
 * POST   /wizard/{id}/overlay     → ذخیره overlay
 * POST   /wizard/{id}/frames      → آپلود فریم‌ها
 * POST   /wizard/{id}/ai-prompt   → prompt AI
 * GET    /wizard/{id}/job-status  → وضعیت job async
 * POST   /wizard/{id}/content     → ذخیره content steps
 * POST   /wizard/{id}/publish     → publish نهایی
 */
final class WizardController
{
    private const NAMESPACE = 'shseq/v1';

    public function __construct(
        private readonly CommandBus             $bus,
        private readonly WizardStateRepository  $repo,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $idParam = [
            'sequence_id' => [
                'validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ],
        ];

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getState'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => $idParam,
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/mode', [
            'methods'             => 'POST',
            'callback'            => [$this, 'selectMode'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'mode' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => array_column(WizardMode::cases(), 'value'),
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/golden-master', [
            'methods'             => 'POST',
            'callback'            => [$this, 'uploadGoldenMaster'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'attachment_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/overlay', [
            'methods'             => 'POST',
            'callback'            => [$this, 'saveOverlay'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'items'   => ['required' => true, 'type' => 'array'],
                'confirm' => ['type' => 'boolean', 'default' => false],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/frames', [
            'methods'             => 'POST',
            'callback'            => [$this, 'uploadFrames'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'attachment_ids' => ['required' => true, 'type' => 'array'],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/ai-prompt', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generateWithAI'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'prompt' => ['required' => true, 'type' => 'string', 'minLength' => 10],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/job-status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getJobStatus'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => $idParam,
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/content', [
            'methods'             => 'POST',
            'callback'            => [$this, 'saveContent'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => array_merge($idParam, [
                'steps'   => ['required' => true, 'type' => 'array'],
                'confirm' => ['type' => 'boolean', 'default' => false],
            ]),
        ]);

        register_rest_route(self::NAMESPACE, '/wizard/(?P<sequence_id>\d+)/publish', [
            'methods'             => 'POST',
            'callback'            => [$this, 'publish'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => $idParam,
        ]);
    }

    /* ─────────────────────────── Handlers ─────────────────────────── */

    public function getState(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $state = $this->repo->findBySequenceId($request->get_param('sequence_id'));
            return $state->toArray();
        });
    }

    public function selectMode(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new SelectModeCommand(
                sequencePostId: $request->get_param('sequence_id'),
                mode:           WizardMode::from($request->get_param('mode')),
            ));
            return ['ok' => true];
        });
    }

    public function uploadGoldenMaster(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new UploadGoldenMasterCommand(
                sequencePostId: $request->get_param('sequence_id'),
                attachmentId:   $request->get_param('attachment_id'),
            ));
            return ['ok' => true, 'message' => 'Text extraction scheduled'];
        });
    }

    public function saveOverlay(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new SaveOverlayLayoutCommand(
                sequencePostId: $request->get_param('sequence_id'),
                items:          (array) $request->get_param('items'),
                confirm:        (bool)  $request->get_param('confirm'),
            ));
            return ['ok' => true];
        });
    }

    public function uploadFrames(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new UploadFramesCommand(
                sequencePostId: $request->get_param('sequence_id'),
                attachmentIds:  (array) $request->get_param('attachment_ids'),
            ));
            return ['ok' => true];
        });
    }

    public function generateWithAI(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new GenerateWithAICommand(
                sequencePostId: $request->get_param('sequence_id'),
                prompt:         $request->get_param('prompt'),
            ));
            return ['ok' => true, 'message' => 'AI generation scheduled'];
        });
    }

    public function getJobStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $state = $this->repo->findBySequenceId($request->get_param('sequence_id'));
            return [
                'step'        => $state->getStep()->value,
                'job_id'      => $state->getJobId(),
                'progress'    => $state->getJobProgress(),
                'frame_count' => count($state->getFrameAttachmentIds()),
                'error'       => $state->getErrorMessage(),
            ];
        });
    }

    public function saveContent(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new SaveContentStepsCommand(
                sequencePostId: $request->get_param('sequence_id'),
                steps:          (array) $request->get_param('steps'),
                confirm:        (bool)  $request->get_param('confirm'),
            ));
            return ['ok' => true];
        });
    }

    public function publish(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->try(function () use ($request) {
            $this->bus->dispatch(new PublishSequenceCommand(
                sequencePostId: $request->get_param('sequence_id'),
            ));
            return ['ok' => true, 'post_url' => get_permalink($request->get_param('sequence_id'))];
        });
    }

    /* ─────────────────────────── Helpers ───────────────────────────── */

    public function checkPermission(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Exception → WP_Error handler یکپارچه
     */
    private function try(callable $fn): \WP_REST_Response
    {
        try {
            $data = $fn();
            return new \WP_REST_Response($data, 200);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 422);
        } catch (\LogicException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            error_log('[shseq] Wizard error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new \WP_REST_Response(['error' => 'Internal server error'], 500);
        }
    }
}

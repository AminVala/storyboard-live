<?php
/**
 * WizardAggregate — Aggregate Root فرایند ساخت سکانس (V3 Final)
 *
 * Pure PHP — بدون هیچ import وردپرسی.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

final class WizardAggregate
{
    /** @var list<OverlayItem> */
    private array $overlayItems = [];

    /** @var list<int> attachment IDs فریم‌ها (از frame 0 تا frame N) */
    private array $frameIds = [];

    /** @var list<array{frame_index:int,html:string,css_class:string}> */
    private array $contentSteps = [];

    /** Job state */
    private ?string $jobId          = null;
    private int     $jobProgress    = 0;
    private int     $frameCheckpoint = 0;  // آخرین frame index موفق

    /** تصویر اصلی (با متن) */
    private ?int $originalAttachmentId = null;

    /**
     * تصویر پاک از متن (خروجی Inpainter).
     * وقتی null است، canvas از original استفاده می‌کند (Phase 1).
     * وقتی set شد، canvas به clean image سوئیچ می‌کند (Phase 2).
     */
    private ?int $cleanAttachmentId = null;

    /** attachment ID تصویر AI-generated */
    private ?int $aiImageId = null;

    /** Prompt متن در Mode AI */
    private string $aiPrompt = '';

    /** تنظیمات Frame Generator */
    private ?array $frameConfig = null;

    private string $errorMessage = '';

    public function __construct(
        private readonly int  $sequencePostId,
        private WizardMode    $mode,
        private WizardStep    $step,
    ) {}

    /* ────────────────────────── Getters ─────────────────────────── */

    public function getSequencePostId(): int      { return $this->sequencePostId; }
    public function getMode(): WizardMode          { return $this->mode; }
    public function getStep(): WizardStep          { return $this->step; }
    public function getError(): string             { return $this->errorMessage; }
    public function getJobId(): ?string            { return $this->jobId; }
    public function getJobProgress(): int          { return $this->jobProgress; }
    public function getFrameCheckpoint(): int      { return $this->frameCheckpoint; }
    public function getOriginalAttachmentId(): ?int { return $this->originalAttachmentId; }
    public function getCleanAttachmentId(): ?int   { return $this->cleanAttachmentId; }
    public function getAiImageId(): ?int           { return $this->aiImageId; }
    public function getAiPrompt(): string          { return $this->aiPrompt; }
    public function getFrameConfig(): ?array       { return $this->frameConfig; }
    public function getOverlayItems(): array       { return $this->overlayItems; }
    public function getFrameIds(): array           { return $this->frameIds; }
    public function getContentSteps(): array       { return $this->contentSteps; }

    /**
     * منبع Frame Generation = clean (اگر آماده) یا original
     * این منطق در Domain است — Infrastructure به آن نیاز دارد
     */
    public function getGenerationSourceId(): ?int
    {
        return $this->cleanAttachmentId ?? $this->originalAttachmentId;
    }

    /**
     * آیا inpainting هنوز در حال انجام است؟
     * canvas editor باید این را poll کند.
     */
    public function isInpaintingPending(): bool
    {
        return $this->originalAttachmentId !== null
            && $this->cleanAttachmentId === null
            && $this->step === WizardStep::CANVAS_EDITOR;
    }

    /* ────────────────────────── Commands ────────────────────────── */

    /** Step 1: انتخاب حالت */
    public function selectMode(WizardMode $mode): void
    {
        $this->assertStep(WizardStep::MODE_SELECT, 'selectMode');
        $this->mode = $mode;
        $this->transitionTo($this->step->allowedNext($mode)[0]);
    }

    /**
     * Step 2a (Mode LAST_FRAME): آپلود تصویر
     * → بلافاصله به VISION_EXTRACT می‌رود
     * → سرور job استخراج+inpainting را schedule می‌کند
     */
    public function uploadLastFrame(int $attachmentId): void
    {
        $this->assertStep(WizardStep::UPLOAD_LAST_FRAME, 'uploadLastFrame');
        if ($attachmentId <= 0) {
            throw new \InvalidArgumentException('attachmentId must be positive');
        }
        $this->originalAttachmentId = $attachmentId;
        $this->transitionTo(WizardStep::VISION_EXTRACT);
    }

    /**
     * وقتی Vision API detection‌ها را برگرداند
     * → overlayItems را پر می‌کند
     * → به CANVAS_EDITOR می‌رود (canvas فوراً نمایش داده می‌شود)
     *
     * @param list<array{text,x_rel,y_rel,width_rel,height_rel}> $detections
     */
    public function applyVisionDetections(array $detections): void
    {
        $this->assertStep(WizardStep::VISION_EXTRACT, 'applyVisionDetections');

        $this->overlayItems = [];
        foreach ($detections as $d) {
            $this->overlayItems[] = OverlayItem::fromDetection(
                id: $this->newId(),
                d:  $d,
            );
        }

        // حتی اگر هیچ detection نداشت → canvas editor خالی نمایش می‌دهد
        $this->transitionTo(WizardStep::CANVAS_EDITOR);
    }

    /**
     * وقتی GD Inpainter تصویر clean را آماده کرد (Phase 2 → swap background)
     * این در background اتفاق می‌افتد — step عوض نمی‌شود
     */
    public function applyCleanBackground(int $cleanAttachmentId): void
    {
        if ($cleanAttachmentId <= 0) {
            throw new \InvalidArgumentException('cleanAttachmentId must be positive');
        }
        $this->cleanAttachmentId = $cleanAttachmentId;
        // step عوض نمی‌شود — canvas editor فقط bg را swap می‌کند
    }

    /**
     * Auto-save overlay از canvas editor (بدون step transition)
     *
     * @param list<array> $items از JS
     */
    public function saveOverlayItems(array $items): void
    {
        if ($this->step !== WizardStep::CANVAS_EDITOR) {
            throw new \LogicException("saveOverlayItems requires CANVAS_EDITOR step");
        }
        $this->overlayItems = array_map(
            fn (array $d) => OverlayItem::fromArray($d),
            $items
        );
    }

    /**
     * تأیید canvas overlay → رفتن به FRAME_CONFIG
     *
     * @param list<array> $items آخرین وضعیت overlay
     */
    public function confirmOverlay(array $items): void
    {
        $this->assertStep(WizardStep::CANVAS_EDITOR, 'confirmOverlay');
        $this->overlayItems = array_map(
            fn (array $d) => OverlayItem::fromArray($d),
            $items
        );
        $this->transitionTo(WizardStep::FRAME_CONFIG);
    }

    /**
     * Step 2b (Mode FRAME_UPLOAD): آپلود فریم‌های آماده
     *
     * @param list<int> $attachmentIds به ترتیب frame 0, 1, ..., N
     */
    public function uploadFrames(array $attachmentIds): void
    {
        $this->assertStep(WizardStep::FRAME_UPLOAD, 'uploadFrames');
        if (count($attachmentIds) < 2) {
            throw new \InvalidArgumentException('At least 2 frames are required');
        }
        $this->frameIds = array_values(array_map('intval', $attachmentIds));
        $this->originalAttachmentId = end($this->frameIds) ?: null;
        $this->transitionTo(WizardStep::PREVIEW);
    }

    /** Step 2c (Mode AI): ثبت prompt */
    public function setAiPrompt(string $prompt): void
    {
        $this->assertStep(WizardStep::AI_PROMPT, 'setAiPrompt');
        $prompt = trim($prompt);
        if (strlen($prompt) < 10) {
            throw new \InvalidArgumentException('Prompt must be at least 10 characters');
        }
        $this->aiPrompt = $prompt;
        $this->transitionTo(WizardStep::AI_GENERATE);
    }

    /**
     * وقتی AI تصویر را ساخت → مثل LAST_FRAME، به CANVAS_EDITOR می‌رود
     */
    public function applyAiImage(int $attachmentId, array $detections = []): void
    {
        $this->assertStep(WizardStep::AI_GENERATE, 'applyAiImage');
        $this->aiImageId            = $attachmentId;
        $this->originalAttachmentId = $attachmentId;

        // در Mode AI، معمولاً detection‌ها از Vision بعداً اضافه می‌شوند
        // اما overlay items می‌توانند خالی باشند — ادمین خودش اضافه می‌کند
        $this->overlayItems = [];
        foreach ($detections as $d) {
            $this->overlayItems[] = OverlayItem::fromDetection($this->newId(), $d);
        }

        $this->transitionTo(WizardStep::CANVAS_EDITOR);
    }

    /** ذخیره تنظیمات Frame Generator */
    public function saveFrameConfig(array $config): void
    {
        $this->assertStep(WizardStep::FRAME_CONFIG, 'saveFrameConfig');
        $this->frameConfig = $config;
    }

    /** تأیید Frame Config و شروع generation */
    public function confirmFrameConfig(array $config): void
    {
        $this->assertStep(WizardStep::FRAME_CONFIG, 'confirmFrameConfig');
        $this->frameConfig = $config;
        $this->transitionTo(WizardStep::FRAME_GENERATE);
    }

    /** شروع job ساخت فریم */
    public function startFrameJob(string $jobId): void
    {
        $this->assertStep(WizardStep::FRAME_GENERATE, 'startFrameJob');
        $this->jobId          = $jobId;
        $this->jobProgress    = 0;
        $this->frameCheckpoint = 0;
    }

    /** بروزرسانی progress فریم‌سازی */
    public function updateFrameProgress(int $percent, int $lastIndex, array $frameIds): void
    {
        $this->jobProgress     = max(0, min(100, $percent));
        $this->frameCheckpoint = $lastIndex;
        if ($frameIds) {
            $this->frameIds = array_values(array_map('intval', $frameIds));
        }
    }

    /** تکمیل frame generation */
    public function completeFrameGeneration(array $frameIds): void
    {
        if (count($frameIds) < 2) {
            throw new \InvalidArgumentException('At least 2 frames required');
        }
        $this->frameIds        = array_values(array_map('intval', $frameIds));
        $this->jobId           = null;
        $this->jobProgress     = 100;
        $this->frameCheckpoint = count($frameIds) - 1;
        $this->transitionTo(WizardStep::PREVIEW);
    }

    /** ذخیره content steps */
    public function saveContentSteps(array $steps): void
    {
        $this->contentSteps = $steps;
    }

    /** publish */
    public function publish(): void
    {
        $this->assertStep(WizardStep::PREVIEW, 'publish');
        $this->transitionTo(WizardStep::PUBLISHED);
    }

    /** ثبت خطا */
    public function fail(string $message): void
    {
        $this->errorMessage = $message;
        $this->step         = WizardStep::FAILED;
        $this->jobId        = null;
    }

    /** Retry از FAILED */
    public function retry(): void
    {
        if ($this->step !== WizardStep::FAILED) {
            throw new \LogicException('Can only retry from FAILED state');
        }
        $this->errorMessage  = '';
        $this->step          = WizardStep::MODE_SELECT;
        $this->overlayItems  = [];
        $this->contentSteps  = [];
        $this->jobId         = null;
        $this->jobProgress   = 0;
        // frameCheckpoint حفظ می‌شود برای resume
    }

    /* ────────────────────────── Serialization ───────────────────── */

    public function toArray(): array
    {
        return [
            'sequence_post_id'      => $this->sequencePostId,
            'mode'                  => $this->mode->value,
            'step'                  => $this->step->value,
            'error_message'         => $this->errorMessage,
            'job_id'                => $this->jobId,
            'job_progress'          => $this->jobProgress,
            'frame_checkpoint'      => $this->frameCheckpoint,
            'original_attachment'   => $this->originalAttachmentId,
            'clean_attachment'      => $this->cleanAttachmentId,
            'ai_image_id'           => $this->aiImageId,
            'ai_prompt'             => $this->aiPrompt,
            'frame_config'          => $this->frameConfig,
            'overlay_items'         => array_map(fn (OverlayItem $i) => $i->toArray(), $this->overlayItems),
            'frame_ids'             => $this->frameIds,
            'content_steps'         => $this->contentSteps,
        ];
    }

    public static function fromArray(array $d): self
    {
        $agg = new self(
            sequencePostId: (int) $d['sequence_post_id'],
            mode:           WizardMode::from($d['mode'] ?? WizardMode::LAST_FRAME->value),
            step:           WizardStep::from($d['step'] ?? WizardStep::MODE_SELECT->value),
        );

        $agg->errorMessage        = (string)($d['error_message']   ?? '');
        $agg->jobId               = $d['job_id']                   ?? null;
        $agg->jobProgress         = (int)($d['job_progress']       ?? 0);
        $agg->frameCheckpoint     = (int)($d['frame_checkpoint']   ?? 0);
        $agg->originalAttachmentId= isset($d['original_attachment']) ? (int)$d['original_attachment'] : null;
        $agg->cleanAttachmentId   = isset($d['clean_attachment'])    ? (int)$d['clean_attachment']    : null;
        $agg->aiImageId           = isset($d['ai_image_id'])         ? (int)$d['ai_image_id']         : null;
        $agg->aiPrompt            = (string)($d['ai_prompt']        ?? '');
        $agg->frameConfig         = $d['frame_config']              ?? null;
        $agg->overlayItems        = array_map(
            fn (array $i) => OverlayItem::fromArray($i),
            $d['overlay_items'] ?? []
        );
        $agg->frameIds            = array_values(array_map('intval', $d['frame_ids'] ?? []));
        $agg->contentSteps        = $d['content_steps']             ?? [];

        return $agg;
    }

    /* ────────────────────────── Private ─────────────────────────── */

    private function assertStep(WizardStep $expected, string $operation): void
    {
        if ($this->step !== $expected) {
            throw new \LogicException(sprintf(
                "'%s' requires step '%s', current is '%s'",
                $operation, $expected->value, $this->step->value
            ));
        }
    }

    private function transitionTo(WizardStep $next): void
    {
        if (! $this->step->canTransitionTo($next, $this->mode)) {
            throw new \LogicException(sprintf(
                "Invalid transition '%s' → '%s' in mode '%s'",
                $this->step->value, $next->value, $this->mode->value
            ));
        }
        $this->step = $next;
    }

    private function newId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }
}

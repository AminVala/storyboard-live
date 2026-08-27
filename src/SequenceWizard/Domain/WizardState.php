<?php
/**
 * WizardState — Aggregate Root وضعیت wizard (V3 Final)
 *
 * Pure PHP — بدون هیچ import وردپرسی.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * یک overlay item — متن استخراج‌شده از Last Frame
 */
final class OverlayItem
{
    public function __construct(
        public readonly string $id,
        public string          $html,
        public OverlayPosition $position,
        public string          $fontFamily = 'inherit',
        public string          $fontSize   = '1rem',
        public string          $color      = '#ffffff',
    ) {}

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'html'       => $this->html,
            'position'   => $this->position->toArray(),
            'fontFamily' => $this->fontFamily,
            'fontSize'   => $this->fontSize,
            'color'      => $this->color,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            id:         $d['id'],
            html:       $d['html'] ?? '',
            position:   OverlayPosition::fromArray($d['position'] ?? []),
            fontFamily: $d['fontFamily'] ?? 'inherit',
            fontSize:   $d['fontSize']   ?? '1rem',
            color:      $d['color']      ?? '#ffffff',
        );
    }
}

/**
 * WizardState — Aggregate Root (V3)
 *
 * تغییرات نسبت به V2:
 * - اضافه: startFrameConfig (StartFrameConfig Value Object)
 * - اضافه: lastFrameAttachmentId (مجزا از cleanBackgroundId)
 * - اضافه: frameCheckpoint (آخرین فریم موفق برای retry)
 * - اضافه: saveStartConfig() command
 * - تصحیح: GM_OVERLAY → START_CONFIG → FRAME_GENERATE
 * - تصحیح: AI_GENERATE → GM_OVERLAY (هم‌مسیر با Mode1)
 */
final class WizardState
{
    /** @var list<OverlayItem> */
    private array $overlayItems = [];

    /** @var list<int> attachment_id فریم‌های ساخته‌شده یا آپلودشده */
    private array $frameAttachmentIds = [];

    /** @var list<array{frame_index:int, html:string, css_class:string}> */
    private array $contentSteps = [];

    /** وضعیت job فعلی async */
    private ?string $jobId       = null;
    private int     $jobProgress = 0;

    /**
     * آخرین checkpoint در frame generation — برای retry بدون از ابتدا
     * مقدار = آخرین frame index موفق
     */
    private int $frameCheckpoint = 0;

    /**
     * attachment_id فریم Last (تصویر اصلی — با متن)
     * این تصویر توسط GD برای overlay reference استفاده می‌شود
     */
    private ?int $lastFrameAttachmentId = null;

    /**
     * attachment_id پس‌زمینه پاک (بدون متن — خروجی GD Inpainter)
     * این تصویر منبع اصلی برای frame generation است
     */
    private ?int $cleanBackgroundId = null;

    /**
     * attachment_id تصویر تولیدشده توسط AI (Mode 3)
     */
    private ?int $aiGeneratedImageId = null;

    /** prompt کاربر در Mode 3 */
    private string $aiPrompt = '';

    /** تنظیمات frame اول — nullable تا زمانی که START_CONFIG پر شود */
    private ?StartFrameConfig $startFrameConfig = null;

    public function __construct(
        private readonly int    $sequencePostId,
        private WizardMode      $mode,
        private WizardStep      $step,
        private string          $errorMessage = '',
    ) {}

    /* ─────────────────────────── Getters ──────────────────────────── */

    public function getSequencePostId(): int              { return $this->sequencePostId; }
    public function getMode(): WizardMode                 { return $this->mode; }
    public function getStep(): WizardStep                 { return $this->step; }
    public function getErrorMessage(): string             { return $this->errorMessage; }
    public function getJobId(): ?string                   { return $this->jobId; }
    public function getJobProgress(): int                 { return $this->jobProgress; }
    public function getFrameCheckpoint(): int             { return $this->frameCheckpoint; }
    public function getLastFrameAttachmentId(): ?int      { return $this->lastFrameAttachmentId; }
    public function getCleanBackgroundId(): ?int          { return $this->cleanBackgroundId; }
    public function getAiGeneratedImageId(): ?int         { return $this->aiGeneratedImageId; }
    public function getAiPrompt(): string                 { return $this->aiPrompt; }
    public function getStartFrameConfig(): ?StartFrameConfig { return $this->startFrameConfig; }

    /** @return list<OverlayItem> */
    public function getOverlayItems(): array { return $this->overlayItems; }

    /** @return list<int> */
    public function getFrameAttachmentIds(): array { return $this->frameAttachmentIds; }

    /** @return list<array> */
    public function getContentSteps(): array { return $this->contentSteps; }

    /**
     * منبع اصلی frame generation:
     * Mode1 → cleanBackgroundId (پس از inpainting)
     * Mode3 → cleanBackgroundId (پس از inpainting)
     * Mode2 → null (فریم‌ها مستقیم آپلود شدند)
     */
    public function getSourceAttachmentId(): ?int
    {
        return $this->cleanBackgroundId;
    }

    /* ─────────────────────────── Commands ─────────────────────────── */

    /**
     * انتخاب حالت ساخت سکانس
     */
    public function selectMode(WizardMode $mode): void
    {
        $this->assertStep(WizardStep::MODE_SELECT, 'selectMode');
        $this->mode = $mode;
        $this->transitionTo($this->step->allowedNext($mode)[0]);
    }

    /**
     * آپلود Last Frame (Mode 1)
     * این تصویر همان frame N نهایی سکانس است
     */
    public function setLastFrameAttachment(int $attachmentId): void
    {
        $this->assertStep(WizardStep::GM_UPLOAD, 'setLastFrameAttachment');
        if ($attachmentId <= 0) {
            throw new \InvalidArgumentException('attachmentId must be positive');
        }
        $this->lastFrameAttachmentId = $attachmentId;
        $this->transitionTo(WizardStep::GM_EXTRACT);
    }

    /**
     * ثبت نتیجه استخراج متن
     *
     * @param list<array{text:string, x_rel:float, y_rel:float, width_rel:float, height_rel:float}> $detections
     */
    public function applyTextExtractionResult(array $detections): void
    {
        $this->assertStep(WizardStep::GM_EXTRACT, 'applyTextExtractionResult');
        $this->overlayItems = [];
        foreach ($detections as $d) {
            $this->overlayItems[] = new OverlayItem(
                id:       $this->generateId(),
                html:     htmlspecialchars($d['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                position: new OverlayPosition(
                    xRel:      max(0.0, min(1.0, (float)($d['x_rel']     ?? 0.0))),
                    yRel:      max(0.0, min(1.0, (float)($d['y_rel']     ?? 0.0))),
                    widthRel:  max(0.01, min(1.0, (float)($d['width_rel']  ?? 0.2))),
                    heightRel: max(0.01, min(1.0, (float)($d['height_rel'] ?? 0.1))),
                ),
            );
        }
        $this->transitionTo(WizardStep::GM_INPAINT);
    }

    /**
     * ثبت نتیجه inpainting — پس‌زمینه پاک از متن
     * این همان تصویری است که frame generation از آن استفاده می‌کند
     */
    public function applyInpaintingResult(int $cleanAttachmentId): void
    {
        $this->assertStep(WizardStep::GM_INPAINT, 'applyInpaintingResult');
        if ($cleanAttachmentId <= 0) {
            throw new \InvalidArgumentException('cleanAttachmentId must be positive');
        }
        $this->cleanBackgroundId = $cleanAttachmentId;
        $this->transitionTo(WizardStep::GM_OVERLAY);
    }

    /**
     * ذخیره موقعیت overlay items (auto-save بدون انتقال step)
     *
     * @param list<array> $items از overlay editor JS
     */
    public function saveOverlayLayout(array $items): void
    {
        // overlay editor در هر دو GM_OVERLAY step active است
        if ($this->step !== WizardStep::GM_OVERLAY) {
            throw new \LogicException("saveOverlayLayout requires GM_OVERLAY step, current: {$this->step->value}");
        }
        $this->overlayItems = array_map(
            fn (array $d) => OverlayItem::fromArray($d),
            $items,
        );
    }

    /**
     * تأیید overlay و رفتن به START_CONFIG
     */
    public function confirmOverlay(): void
    {
        $this->assertStep(WizardStep::GM_OVERLAY, 'confirmOverlay');
        // overlay خالی هم مجاز است — ادمین ممکن است هیچ متنی نخواهد
        $this->transitionTo(WizardStep::START_CONFIG);
    }

    /**
     * ذخیره تنظیمات frame اول
     * Step: START_CONFIG
     */
    public function saveStartConfig(StartFrameConfig $config): void
    {
        $this->assertStep(WizardStep::START_CONFIG, 'saveStartConfig');
        $this->startFrameConfig = $config;
    }

    /**
     * تأیید START_CONFIG و شروع frame generation
     */
    public function confirmStartConfig(): void
    {
        $this->assertStep(WizardStep::START_CONFIG, 'confirmStartConfig');
        if ($this->startFrameConfig === null) {
            throw new \LogicException('StartFrameConfig must be saved before confirming');
        }
        $this->transitionTo(WizardStep::FRAME_GENERATE);
    }

    /**
     * آپلود فریم‌های آماده (Mode 2)
     *
     * @param list<int> $attachmentIds به ترتیب frame 0, 1, ..., N
     */
    public function setFrameAttachments(array $attachmentIds): void
    {
        $this->assertStep(WizardStep::FU_UPLOAD, 'setFrameAttachments');
        if (count($attachmentIds) < 2) {
            throw new \InvalidArgumentException('At least 2 frames are required');
        }
        // frame آخر = Last Frame
        $this->lastFrameAttachmentId = end($attachmentIds) ?: null;
        $this->frameAttachmentIds    = array_values(array_map('intval', $attachmentIds));
        $this->transitionTo(WizardStep::CONTENT_STEPS);
    }

    /**
     * ثبت prompt برای Mode 3
     */
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
     * ثبت تصویر تولیدشده توسط AI (Last Frame جدید در Mode 3)
     * بعد از این، به GM_OVERLAY می‌رود — دقیقاً مثل Mode 1
     */
    public function applyAiGeneratedImage(int $attachmentId): void
    {
        $this->assertStep(WizardStep::AI_GENERATE, 'applyAiGeneratedImage');
        $this->aiGeneratedImageId    = $attachmentId;
        $this->lastFrameAttachmentId = $attachmentId; // AI image = Last Frame
        $this->transitionTo(WizardStep::GM_OVERLAY);
    }

    /**
     * ثبت شروع job ساخت فریم
     */
    public function startFrameGenerationJob(string $jobId): void
    {
        $this->assertStep(WizardStep::FRAME_GENERATE, 'startFrameGenerationJob');
        $this->jobId            = $jobId;
        $this->jobProgress      = 0;
        $this->frameCheckpoint  = 0;
    }

    /**
     * بروزرسانی progress + checkpoint
     *
     * @param list<int> $frameAttachmentIds فریم‌هایی که تا الان ساخته شدند
     */
    public function updateJobProgress(int $percent, int $lastFrameIndex, array $frameAttachmentIds = []): void
    {
        $this->jobProgress     = max(0, min(100, $percent));
        $this->frameCheckpoint = $lastFrameIndex;
        if ($frameAttachmentIds) {
            $this->frameAttachmentIds = array_values(array_map('intval', $frameAttachmentIds));
        }
    }

    /**
     * تکمیل frame generation
     *
     * @param list<int> $frameAttachmentIds از frame 0 تا frame N
     */
    public function completeFrameGeneration(array $frameAttachmentIds): void
    {
        if (count($frameAttachmentIds) < 2) {
            throw new \InvalidArgumentException('Frame generation must produce at least 2 frames');
        }
        $this->frameAttachmentIds  = array_values(array_map('intval', $frameAttachmentIds));
        $this->lastFrameAttachmentId = end($this->frameAttachmentIds) ?: null;
        $this->jobId               = null;
        $this->jobProgress         = 100;
        $this->frameCheckpoint     = count($frameAttachmentIds) - 1;
        $this->transitionTo(WizardStep::CONTENT_STEPS);
    }

    /**
     * ذخیره content steps
     *
     * @param list<array{frame_index:int, html:string, css_class:string}> $steps
     */
    public function saveContentSteps(array $steps): void
    {
        if ($this->step !== WizardStep::CONTENT_STEPS) {
            throw new \LogicException("saveContentSteps requires CONTENT_STEPS step");
        }
        $this->contentSteps = $steps;
    }

    /** تأیید محتوا و رفتن به preview */
    public function confirmContent(): void
    {
        $this->assertStep(WizardStep::CONTENT_STEPS, 'confirmContent');
        $this->transitionTo(WizardStep::PREVIEW);
    }

    /** publish */
    public function publish(): void
    {
        $this->assertStep(WizardStep::PREVIEW, 'publish');
        $this->transitionTo(WizardStep::PUBLISHED);
    }

    /**
     * ثبت خطا — از هر step مجاز
     */
    public function fail(string $message): void
    {
        $this->errorMessage = $message;
        $this->step         = WizardStep::FAILED;
        $this->jobId        = null;
    }

    /**
     * retry از FAILED — برگشت به اولین step حالت فعلی
     * frame checkpoint حفظ می‌شود تا generation ادامه دهد
     */
    public function retry(): void
    {
        if ($this->step !== WizardStep::FAILED) {
            throw new \LogicException('Can only retry from FAILED state');
        }
        $this->errorMessage = '';
        $this->step         = WizardStep::MODE_SELECT;
        // NOTE: frameCheckpoint حفظ می‌شود — generation از همانجا ادامه می‌دهد
        $this->overlayItems = [];
        $this->contentSteps = [];
        $this->jobId        = null;
        $this->jobProgress  = 0;
    }

    /* ─────────────────────────── Serialization ─────────────────────── */

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
            'last_frame_attachment' => $this->lastFrameAttachmentId,
            'clean_background_id'   => $this->cleanBackgroundId,
            'ai_generated_image'    => $this->aiGeneratedImageId,
            'ai_prompt'             => $this->aiPrompt,
            'start_frame_config'    => $this->startFrameConfig?->toArray(),
            'overlay_items'         => array_map(fn (OverlayItem $i) => $i->toArray(), $this->overlayItems),
            'frame_attachment_ids'  => $this->frameAttachmentIds,
            'content_steps'         => $this->contentSteps,
        ];
    }

    public static function fromArray(array $d): self
    {
        $state = new self(
            sequencePostId: (int)   $d['sequence_post_id'],
            mode:           WizardMode::from($d['mode'] ?? WizardMode::GOLDEN_MASTER->value),
            step:           WizardStep::from($d['step'] ?? WizardStep::MODE_SELECT->value),
            errorMessage:   (string)($d['error_message'] ?? ''),
        );
        $state->jobId                = $d['job_id']             ?? null;
        $state->jobProgress          = (int)($d['job_progress'] ?? 0);
        $state->frameCheckpoint      = (int)($d['frame_checkpoint'] ?? 0);
        $state->lastFrameAttachmentId= isset($d['last_frame_attachment']) ? (int)$d['last_frame_attachment'] : null;
        $state->cleanBackgroundId    = isset($d['clean_background_id'])   ? (int)$d['clean_background_id']   : null;
        $state->aiGeneratedImageId   = isset($d['ai_generated_image'])    ? (int)$d['ai_generated_image']    : null;
        $state->aiPrompt             = (string)($d['ai_prompt'] ?? '');
        $state->startFrameConfig     = isset($d['start_frame_config']) && is_array($d['start_frame_config'])
            ? StartFrameConfig::fromArray($d['start_frame_config'])
            : null;
        $state->overlayItems         = array_map(
            fn (array $i) => OverlayItem::fromArray($i),
            $d['overlay_items'] ?? [],
        );
        $state->frameAttachmentIds   = array_values(array_map('intval', $d['frame_attachment_ids'] ?? []));
        $state->contentSteps         = $d['content_steps'] ?? [];
        return $state;
    }

    /* ─────────────────────────── Private ───────────────────────────── */

    private function assertStep(WizardStep $expected, string $operation): void
    {
        if ($this->step !== $expected) {
            throw new \LogicException(sprintf(
                "Operation '%s' requires step '%s', but current is '%s'",
                $operation, $expected->value, $this->step->value,
            ));
        }
    }

    private function transitionTo(WizardStep $next): void
    {
        if (! $this->step->canTransitionTo($next, $this->mode)) {
            throw new \LogicException(sprintf(
                "Invalid transition from '%s' to '%s' in mode '%s'",
                $this->step->value, $next->value, $this->mode->value,
            ));
        }
        $this->step = $next;
    }

    private function generateId(): string
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

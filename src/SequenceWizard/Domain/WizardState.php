<?php
/**
 * WizardState — Aggregate Root وضعیت کامل wizard یک سکانس
 *
 * Pure PHP — بدون هیچ import وردپرسی.
 * تمام تغییرات از طریق متدهای عمومی اعمال می‌شوند.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * یک overlay item — متن استخراج‌شده از Golden Master
 */
final class OverlayItem
{
    public function __construct(
        public readonly string          $id,       // uuid v4
        public string                   $html,     // محتوای HTML قابل ویرایش
        public OverlayPosition          $position, // مختصات نسبی
        public string                   $fontFamily = 'inherit',
        public string                   $fontSize   = '1rem',
        public string                   $color      = '#ffffff',
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
 * WizardState — Aggregate Root
 *
 * این کلاس حالت کامل wizard را نگه می‌دارد و توسط
 * WizardStateRepository در post_meta ذخیره/بازیابی می‌شود.
 */
final class WizardState
{
    /** @var list<OverlayItem> */
    private array $overlayItems = [];

    /** @var list<int> attachment_id فریم‌های آپلودشده */
    private array $frameAttachmentIds = [];

    /** @var list<array{frame_index:int, html:string, css_class:string}> */
    private array $contentSteps = [];

    /** وضعیت job فعلی async */
    private ?string $jobId = null;
    private int     $jobProgress = 0; // 0–100

    /** attachment_id پس‌زمینه بدون متن (خروجی inpainter) */
    private ?int $cleanBackgroundId = null;

    /** attachment_id تصویر اولیه Golden Master */
    private ?int $goldenMasterAttachmentId = null;

    /** attachment_id تصویر تولیدشده توسط AI */
    private ?int $aiGeneratedImageId = null;

    /** متن prompt در حالت AI */
    private string $aiPrompt = '';

    public function __construct(
        private readonly int    $sequencePostId,
        private WizardMode      $mode,
        private WizardStep      $step,
        private string          $errorMessage = '',
    ) {}

    /* ─────────────────────────── Getters ──────────────────────────── */

    public function getSequencePostId(): int      { return $this->sequencePostId; }
    public function getMode(): WizardMode         { return $this->mode; }
    public function getStep(): WizardStep         { return $this->step; }
    public function getErrorMessage(): string     { return $this->errorMessage; }
    public function getJobId(): ?string           { return $this->jobId; }
    public function getJobProgress(): int         { return $this->jobProgress; }
    public function getCleanBackgroundId(): ?int  { return $this->cleanBackgroundId; }
    public function getGoldenMasterAttachmentId(): ?int { return $this->goldenMasterAttachmentId; }
    public function getAiGeneratedImageId(): ?int { return $this->aiGeneratedImageId; }
    public function getAiPrompt(): string         { return $this->aiPrompt; }

    /** @return list<OverlayItem> */
    public function getOverlayItems(): array { return $this->overlayItems; }

    /** @return list<int> */
    public function getFrameAttachmentIds(): array { return $this->frameAttachmentIds; }

    /** @return list<array> */
    public function getContentSteps(): array { return $this->contentSteps; }

    /* ─────────────────────────── Commands ─────────────────────────── */

    /**
     * انتخاب حالت — فقط از MODE_SELECT مجاز است
     */
    public function selectMode(WizardMode $mode): void
    {
        $this->assertStep(WizardStep::MODE_SELECT, 'selectMode');
        $this->mode = $mode;
        $this->transitionTo(WizardStep::MODE_SELECT->allowedNext($mode)[0]);
    }

    /**
     * ثبت آپلود Golden Master PNG
     */
    public function setGoldenMasterAttachment(int $attachmentId): void
    {
        $this->assertStep(WizardStep::GM_UPLOAD, 'setGoldenMasterAttachment');
        if ($attachmentId <= 0) {
            throw new \InvalidArgumentException('attachmentId must be positive');
        }
        $this->goldenMasterAttachmentId = $attachmentId;
        $this->transitionTo(WizardStep::GM_EXTRACT);
    }

    /**
     * ثبت نتیجه استخراج متن توسط Vision API
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
                    xRel:      (float) ($d['x_rel']      ?? 0.0),
                    yRel:      (float) ($d['y_rel']      ?? 0.0),
                    widthRel:  (float) ($d['width_rel']  ?? 0.2),
                    heightRel: (float) ($d['height_rel'] ?? 0.1),
                ),
            );
        }
        $this->transitionTo(WizardStep::GM_INPAINT);
    }

    /**
     * ثبت نتیجه inpainting (پس‌زمینه بدون متن)
     */
    public function applyInpaintingResult(int $cleanAttachmentId): void
    {
        $this->assertStep(WizardStep::GM_INPAINT, 'applyInpaintingResult');
        $this->cleanBackgroundId = $cleanAttachmentId;
        $this->transitionTo(WizardStep::GM_OVERLAY);
    }

    /**
     * ذخیره وضعیت overlay editor
     *
     * @param list<array> $items آرایه serialize‌شده OverlayItem‌ها از JS
     */
    public function saveOverlayLayout(array $items): void
    {
        $this->assertStep(WizardStep::GM_OVERLAY, 'saveOverlayLayout');
        $this->overlayItems = array_map(
            fn (array $d) => OverlayItem::fromArray($d),
            $items,
        );
    }

    /**
     * تأیید overlay و رفتن به frame generation
     */
    public function confirmOverlay(): void
    {
        $this->assertStep(WizardStep::GM_OVERLAY, 'confirmOverlay');
        if (empty($this->overlayItems)) {
            throw new \LogicException('Overlay must have at least one item before confirming');
        }
        $this->transitionTo(WizardStep::FRAME_GENERATE);
    }

    /**
     * آپلود فریم‌ها (حالت FRAME_UPLOAD)
     *
     * @param list<int> $attachmentIds به ترتیب اجرا
     */
    public function setFrameAttachments(array $attachmentIds): void
    {
        $this->assertStep(WizardStep::FU_UPLOAD, 'setFrameAttachments');
        if (count($attachmentIds) < 2) {
            throw new \InvalidArgumentException('At least 2 frames are required');
        }
        $this->frameAttachmentIds = array_values(array_map('intval', $attachmentIds));
        $this->transitionTo(WizardStep::CONTENT_STEPS);
    }

    /**
     * ثبت prompt برای AI generation
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
     * ثبت تصویر تولیدشده توسط AI
     */
    public function applyAiGeneratedImage(int $attachmentId): void
    {
        $this->assertStep(WizardStep::AI_GENERATE, 'applyAiGeneratedImage');
        $this->aiGeneratedImageId = $attachmentId;
        $this->transitionTo(WizardStep::FRAME_GENERATE);
    }

    /**
     * شروع job ساخت فریم — job_id از Action Scheduler
     */
    public function startFrameGenerationJob(string $jobId): void
    {
        $this->assertStep(WizardStep::FRAME_GENERATE, 'startFrameGenerationJob');
        $this->jobId       = $jobId;
        $this->jobProgress = 0;
    }

    /**
     * بروز progress فریم‌سازی (callback از job)
     */
    public function updateJobProgress(int $percent, array $frameAttachmentIds = []): void
    {
        $this->jobProgress = max(0, min(100, $percent));
        if ($frameAttachmentIds) {
            $this->frameAttachmentIds = array_values(array_map('intval', $frameAttachmentIds));
        }
    }

    /**
     * تکمیل frame generation
     */
    public function completeFrameGeneration(array $frameAttachmentIds): void
    {
        if (empty($frameAttachmentIds)) {
            throw new \InvalidArgumentException('Frame generation produced no frames');
        }
        $this->frameAttachmentIds = array_values(array_map('intval', $frameAttachmentIds));
        $this->jobId       = null;
        $this->jobProgress = 100;
        $this->transitionTo(WizardStep::CONTENT_STEPS);
    }

    /**
     * ذخیره content steps (متن روی هر فریم)
     *
     * @param list<array{frame_index:int, html:string, css_class:string}> $steps
     */
    public function saveContentSteps(array $steps): void
    {
        $this->assertStep(WizardStep::CONTENT_STEPS, 'saveContentSteps');
        $this->contentSteps = $steps;
    }

    /**
     * تأیید content و رفتن به preview
     */
    public function confirmContent(): void
    {
        $this->assertStep(WizardStep::CONTENT_STEPS, 'confirmContent');
        $this->transitionTo(WizardStep::PREVIEW);
    }

    /**
     * publish — فقط از preview مجاز است
     */
    public function publish(): void
    {
        $this->assertStep(WizardStep::PREVIEW, 'publish');
        $this->transitionTo(WizardStep::PUBLISHED);
    }

    /**
     * ثبت خطا — از هر step مجاز است
     */
    public function fail(string $message): void
    {
        $this->errorMessage = $message;
        $this->step         = WizardStep::FAILED;
        $this->jobId        = null;
    }

    /**
     * retry از FAILED — برگشت به اولین step حالت فعلی
     */
    public function retry(): void
    {
        if ($this->step !== WizardStep::FAILED) {
            throw new \LogicException('Can only retry from FAILED state');
        }
        $this->errorMessage = '';
        $this->step         = WizardStep::MODE_SELECT;
        $this->frameAttachmentIds = [];
        $this->overlayItems       = [];
        $this->contentSteps       = [];
        $this->jobId              = null;
        $this->jobProgress        = 0;
    }

    /* ─────────────────────────── Serialization ─────────────────────── */

    public function toArray(): array
    {
        return [
            'sequence_post_id'          => $this->sequencePostId,
            'mode'                      => $this->mode->value,
            'step'                      => $this->step->value,
            'error_message'             => $this->errorMessage,
            'job_id'                    => $this->jobId,
            'job_progress'              => $this->jobProgress,
            'clean_background_id'       => $this->cleanBackgroundId,
            'golden_master_attachment'  => $this->goldenMasterAttachmentId,
            'ai_generated_image'        => $this->aiGeneratedImageId,
            'ai_prompt'                 => $this->aiPrompt,
            'overlay_items'             => array_map(fn (OverlayItem $i) => $i->toArray(), $this->overlayItems),
            'frame_attachment_ids'      => $this->frameAttachmentIds,
            'content_steps'             => $this->contentSteps,
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
        $state->jobId                   = $d['job_id']              ?? null;
        $state->jobProgress             = (int)($d['job_progress']  ?? 0);
        $state->cleanBackgroundId       = isset($d['clean_background_id']) ? (int) $d['clean_background_id'] : null;
        $state->goldenMasterAttachmentId= isset($d['golden_master_attachment']) ? (int) $d['golden_master_attachment'] : null;
        $state->aiGeneratedImageId      = isset($d['ai_generated_image']) ? (int) $d['ai_generated_image'] : null;
        $state->aiPrompt                = (string)($d['ai_prompt'] ?? '');
        $state->overlayItems            = array_map(
            fn (array $i) => OverlayItem::fromArray($i),
            $d['overlay_items'] ?? [],
        );
        $state->frameAttachmentIds      = array_values(array_map('intval', $d['frame_attachment_ids'] ?? []));
        $state->contentSteps            = $d['content_steps'] ?? [];
        return $state;
    }

    /* ─────────────────────────── Private ───────────────────────────── */

    private function assertStep(WizardStep $expected, string $operation): void
    {
        if ($this->step !== $expected) {
            throw new \LogicException(sprintf(
                "Operation '%s' requires step '%s', but current step is '%s'",
                $operation,
                $expected->value,
                $this->step->value,
            ));
        }
    }

    private function transitionTo(WizardStep $next): void
    {
        if (! $this->step->canTransitionTo($next, $this->mode)) {
            throw new \LogicException(sprintf(
                "Invalid transition from '%s' to '%s' in mode '%s'",
                $this->step->value,
                $next->value,
                $this->mode->value,
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

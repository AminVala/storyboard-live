<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * WizardStep — State Machine مراحل ساخت سکانس (V3 Final)
 *
 * Flow:
 *
 * Mode LAST_FRAME:
 *   MODE_SELECT → UPLOAD_LAST_FRAME → VISION_EXTRACT(async)
 *   → CANVAS_EDITOR → FRAME_CONFIG → FRAME_GENERATE(async)
 *   → PREVIEW → PUBLISHED
 *
 * Mode FRAME_UPLOAD:
 *   MODE_SELECT → FRAME_UPLOAD → PREVIEW → PUBLISHED
 *
 * Mode AI_GENERATE:
 *   MODE_SELECT → AI_PROMPT → AI_GENERATE(async)
 *   → CANVAS_EDITOR → FRAME_CONFIG → FRAME_GENERATE(async)
 *   → PREVIEW → PUBLISHED
 *
 * توجه: VISION_EXTRACT در پس‌زمینه اجرا می‌شود.
 *       Canvas editor فوراً نمایش داده می‌شود (Phase 1 = تصویر اصلی).
 *       وقتی inpainting تمام شد، background به clean image تبدیل می‌شود.
 */
enum WizardStep: string
{
    case MODE_SELECT       = 'mode_select';
    case UPLOAD_LAST_FRAME = 'upload_last_frame';
    case VISION_EXTRACT    = 'vision_extract';    // async — canvas از همین لحظه قابل استفاده است
    case CANVAS_EDITOR     = 'canvas_editor';
    case FRAME_UPLOAD      = 'frame_upload';
    case AI_PROMPT         = 'ai_prompt';
    case AI_GENERATE       = 'ai_generate';       // async
    case FRAME_CONFIG      = 'frame_config';
    case FRAME_GENERATE    = 'frame_generate';    // async + checkpointed
    case PREVIEW           = 'preview';
    case PUBLISHED         = 'published';
    case FAILED            = 'failed';

    /**
     * مراحل بعدی مجاز
     * @return list<WizardStep>
     */
    public function allowedNext(WizardMode $mode): array
    {
        return match ($this) {
            self::MODE_SELECT => match ($mode) {
                WizardMode::LAST_FRAME   => [self::UPLOAD_LAST_FRAME],
                WizardMode::FRAME_UPLOAD => [self::FRAME_UPLOAD],
                WizardMode::AI_GENERATE  => [self::AI_PROMPT],
            },

            /* Mode: LAST_FRAME */
            self::UPLOAD_LAST_FRAME => [self::VISION_EXTRACT],
            self::VISION_EXTRACT    => [self::CANVAS_EDITOR],   // بلافاصله یا وقتی async تمام شد

            /* Mode: FRAME_UPLOAD */
            self::FRAME_UPLOAD => [self::PREVIEW],

            /* Mode: AI_GENERATE */
            self::AI_PROMPT   => [self::AI_GENERATE],
            self::AI_GENERATE => [self::CANVAS_EDITOR],         // AI output → canvas editor

            /* مشترک Mode1+Mode3 */
            self::CANVAS_EDITOR => [self::FRAME_CONFIG],
            self::FRAME_CONFIG  => [self::FRAME_GENERATE],
            self::FRAME_GENERATE => [self::PREVIEW],

            /* نهایی */
            self::PREVIEW    => [self::PUBLISHED],
            self::PUBLISHED  => [],
            self::FAILED     => [],
        };
    }

    public function canTransitionTo(WizardStep $next, WizardMode $mode): bool
    {
        return in_array($next, $this->allowedNext($mode), true);
    }

    public function isAsync(): bool
    {
        return in_array($this, [self::VISION_EXTRACT, self::AI_GENERATE, self::FRAME_GENERATE], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::MODE_SELECT       => 'انتخاب حالت',
            self::UPLOAD_LAST_FRAME => 'آپلود تصویر',
            self::VISION_EXTRACT    => 'استخراج متن',
            self::CANVAS_EDITOR     => 'ویرایش overlay',
            self::FRAME_UPLOAD      => 'آپلود فریم‌ها',
            self::AI_PROMPT         => 'توضیح طرح',
            self::AI_GENERATE       => 'ساخت تصویر',
            self::FRAME_CONFIG      => 'تنظیم انیمیشن',
            self::FRAME_GENERATE    => 'ساخت فریم‌ها',
            self::PREVIEW           => 'پیش‌نمایش',
            self::PUBLISHED         => 'منتشرشده',
            self::FAILED            => 'خطا',
        };
    }
}

<?php
/**
 * WizardStep — State Machine مراحل ساخت سکانس (V3 Final)
 *
 * Pure PHP enum — بدون هیچ import وردپرسی
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * مراحل wizard به ترتیب اجباری:
 *
 * Mode 1 (Last Frame):
 *   MODE_SELECT → GM_UPLOAD → GM_EXTRACT → GM_INPAINT → GM_OVERLAY → START_CONFIG
 *   → FRAME_GENERATE → CONTENT_STEPS → PREVIEW → PUBLISHED
 *
 * Mode 2 (Pre-made Frames):
 *   MODE_SELECT → FU_UPLOAD → CONTENT_STEPS → PREVIEW → PUBLISHED
 *
 * Mode 3 (AI Generate):
 *   MODE_SELECT → AI_PROMPT → AI_GENERATE → GM_OVERLAY → START_CONFIG
 *   → FRAME_GENERATE → CONTENT_STEPS → PREVIEW → PUBLISHED
 *
 * هیچ step نمی‌تواند skip شود.
 */
enum WizardStep: string
{
    /* ── مرحله مشترک اول ────────────────────────────────────────── */
    case MODE_SELECT    = 'mode_select';

    /* ── Mode 1: Last Frame ──────────────────────────────────────── */
    case GM_UPLOAD      = 'gm_upload';     // آپلود Last Frame PNG/JPG/WebP
    case GM_EXTRACT     = 'gm_extract';    // استخراج متن با Vision API (async)
    case GM_INPAINT     = 'gm_inpaint';    // پاک‌سازی متن از تصویر با GD (async)
    case GM_OVERLAY     = 'gm_overlay';    // overlay editor برای تنظیم دقیق متن‌ها

    /* ── Mode 2: Pre-made Frames ─────────────────────────────────── */
    case FU_UPLOAD      = 'fu_upload';     // آپلود فریم‌های آماده به ترتیب

    /* ── Mode 3: AI Generate ─────────────────────────────────────── */
    case AI_PROMPT      = 'ai_prompt';     // وارد کردن prompt
    case AI_GENERATE    = 'ai_generate';   // ساخت Last Frame با AI (async)

    /* ── مشترک بین Mode 1 و Mode 3 ──────────────────────────────── */
    case START_CONFIG   = 'start_config';  // تنظیم MotionPreset + تعداد فریم

    /* ── Frame generation (Mode 1 + Mode 3) ─────────────────────── */
    case FRAME_GENERATE = 'frame_generate'; // ساخت فریم 0 تا N (async, checkpointed)

    /* ── مراحل مشترک نهایی ───────────────────────────────────────── */
    case CONTENT_STEPS  = 'content_steps';  // تعریف HTML overlay per-frame
    case PREVIEW        = 'preview';         // preview در ۳ viewport
    case PUBLISHED      = 'published';       // منتشرشده

    /* ── خطا ─────────────────────────────────────────────────────── */
    case FAILED         = 'failed';

    /**
     * مراحل بعدی مجاز — قانون اصلی state machine
     *
     * @param WizardMode $mode حالت انتخاب‌شده
     * @return list<WizardStep>
     */
    public function allowedNext(WizardMode $mode): array
    {
        return match ($this) {

            self::MODE_SELECT => match ($mode) {
                WizardMode::GOLDEN_MASTER => [self::GM_UPLOAD],
                WizardMode::FRAME_UPLOAD  => [self::FU_UPLOAD],
                WizardMode::AI_GENERATE   => [self::AI_PROMPT],
            },

            /* Mode 1 */
            self::GM_UPLOAD    => [self::GM_EXTRACT],
            self::GM_EXTRACT   => [self::GM_INPAINT],
            self::GM_INPAINT   => [self::GM_OVERLAY],
            self::GM_OVERLAY   => [self::START_CONFIG],

            /* Mode 2 */
            self::FU_UPLOAD    => [self::CONTENT_STEPS],

            /* Mode 3 */
            self::AI_PROMPT    => [self::AI_GENERATE],
            self::AI_GENERATE  => [self::GM_OVERLAY], // هر دو Mode1 و Mode3 از overlay رد می‌شوند

            /* مشترک Mode1 + Mode3 */
            self::START_CONFIG   => [self::FRAME_GENERATE],
            self::FRAME_GENERATE => [self::CONTENT_STEPS],

            /* نهایی */
            self::CONTENT_STEPS  => [self::PREVIEW],
            self::PREVIEW        => [self::PUBLISHED],

            self::PUBLISHED,
            self::FAILED         => [],
        };
    }

    public function canTransitionTo(WizardStep $next, WizardMode $mode): bool
    {
        return in_array($next, $this->allowedNext($mode), true);
    }

    /** آیا این step یک عملیات async دارد؟ */
    public function isAsync(): bool
    {
        return in_array($this, [
            self::GM_EXTRACT,
            self::GM_INPAINT,
            self::AI_GENERATE,
            self::FRAME_GENERATE,
        ], true);
    }

    /** آیا این step یک editing step تعاملی است؟ */
    public function isInteractive(): bool
    {
        return in_array($this, [
            self::MODE_SELECT,
            self::GM_UPLOAD,
            self::GM_OVERLAY,
            self::FU_UPLOAD,
            self::AI_PROMPT,
            self::START_CONFIG,
            self::CONTENT_STEPS,
            self::PREVIEW,
        ], true);
    }

    /** label فارسی برای UI */
    public function label(): string
    {
        return match ($this) {
            self::MODE_SELECT    => 'انتخاب حالت',
            self::GM_UPLOAD      => 'آپلود Last Frame',
            self::GM_EXTRACT     => 'استخراج متن',
            self::GM_INPAINT     => 'پاک‌سازی تصویر',
            self::GM_OVERLAY     => 'تنظیم overlay',
            self::FU_UPLOAD      => 'آپلود فریم‌ها',
            self::AI_PROMPT      => 'توضیح طرح',
            self::AI_GENERATE    => 'ساخت تصویر',
            self::START_CONFIG   => 'تنظیم انیمیشن',
            self::FRAME_GENERATE => 'ساخت فریم‌ها',
            self::CONTENT_STEPS  => 'تعریف محتوا',
            self::PREVIEW        => 'پیش‌نمایش',
            self::PUBLISHED      => 'منتشرشده',
            self::FAILED         => 'خطا',
        };
    }

    /** آیکون برای نمایش در progress bar */
    public function icon(): string
    {
        return match ($this) {
            self::MODE_SELECT    => '🎯',
            self::GM_UPLOAD      => '🖼️',
            self::GM_EXTRACT     => '🔍',
            self::GM_INPAINT     => '🎨',
            self::GM_OVERLAY     => '✏️',
            self::FU_UPLOAD      => '📂',
            self::AI_PROMPT      => '💬',
            self::AI_GENERATE    => '✨',
            self::START_CONFIG   => '⚙️',
            self::FRAME_GENERATE => '🎬',
            self::CONTENT_STEPS  => '📝',
            self::PREVIEW        => '👁️',
            self::PUBLISHED      => '🚀',
            self::FAILED         => '⚠️',
        };
    }
}

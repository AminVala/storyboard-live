<?php
/**
 * WizardStep — State Machine برای فرایند ساخت سکانس
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
 * MODE_SELECT → (بسته به حالت) → CONTENT_STEPS → FRAME_GENERATE → PREVIEW → PUBLISHED
 *
 * قوانین انتقال در allowedNext() تعریف شده — هیچ step نمی‌تواند skip شود.
 */
enum WizardStep: string
{
    /* ── مراحل مشترک ─────────────────────────────────────────────────── */
    case MODE_SELECT   = 'mode_select';   // انتخاب یکی از 3 حالت

    /* ── حالت Golden Master ──────────────────────────────────────────── */
    case GM_UPLOAD     = 'gm_upload';     // آپلود PNG
    case GM_EXTRACT    = 'gm_extract';    // استخراج متن (async)
    case GM_INPAINT    = 'gm_inpaint';    // پاک کردن متن از تصویر (async)
    case GM_OVERLAY    = 'gm_overlay';    // overlay editor روی canvas

    /* ── حالت Frame Upload ───────────────────────────────────────────── */
    case FU_UPLOAD     = 'fu_upload';     // آپلود فریم‌ها به ترتیب

    /* ── حالت AI Generate ───────────────────────────────────────────── */
    case AI_PROMPT     = 'ai_prompt';     // وارد کردن prompt
    case AI_GENERATE   = 'ai_generate';   // ساخت تصویر اولیه (async)

    /* ── مراحل مشترک بعد از همه حالت‌ها ────────────────────────────── */
    case FRAME_GENERATE = 'frame_generate'; // ساخت فریم‌های Ken Burns (async)
    case CONTENT_STEPS  = 'content_steps';  // تعریف متن روی هر فریم
    case PREVIEW        = 'preview';         // preview موبایل/تبلت/دسکتاپ
    case PUBLISHED      = 'published';       // منتشرشده

    /* ── خطا ─────────────────────────────────────────────────────────── */
    case FAILED         = 'failed';          // خطای غیرقابل بازیابی

    /**
     * مراحل مجاز بعدی برای این step — null یعنی پایان یا خطا.
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

            self::GM_UPLOAD    => [self::GM_EXTRACT],
            self::GM_EXTRACT   => [self::GM_INPAINT],
            self::GM_INPAINT   => [self::GM_OVERLAY],
            self::GM_OVERLAY   => [self::FRAME_GENERATE],

            self::FU_UPLOAD    => [self::CONTENT_STEPS],

            self::AI_PROMPT    => [self::AI_GENERATE],
            self::AI_GENERATE  => [self::FRAME_GENERATE],

            self::FRAME_GENERATE => [self::CONTENT_STEPS],
            self::CONTENT_STEPS  => [self::PREVIEW],
            self::PREVIEW        => [self::PUBLISHED],

            self::PUBLISHED,
            self::FAILED       => [],
        };
    }

    /**
     * آیا این step می‌تواند به step بعدی برود؟
     */
    public function canTransitionTo(WizardStep $next, WizardMode $mode): bool
    {
        return in_array($next, $this->allowedNext($mode), true);
    }

    /**
     * آیا این step یک عملیات async (background job) دارد؟
     */
    public function isAsync(): bool
    {
        return in_array($this, [
            self::GM_EXTRACT,
            self::GM_INPAINT,
            self::AI_GENERATE,
            self::FRAME_GENERATE,
        ], true);
    }

    /** label فارسی */
    public function label(): string
    {
        return match ($this) {
            self::MODE_SELECT    => 'انتخاب حالت',
            self::GM_UPLOAD      => 'آپلود Golden Master',
            self::GM_EXTRACT     => 'استخراج متن',
            self::GM_INPAINT     => 'پاک‌سازی تصویر',
            self::GM_OVERLAY     => 'تنظیم overlay',
            self::FU_UPLOAD      => 'آپلود فریم‌ها',
            self::AI_PROMPT      => 'توضیح طرح',
            self::AI_GENERATE    => 'ساخت تصویر',
            self::FRAME_GENERATE => 'ساخت فریم‌ها',
            self::CONTENT_STEPS  => 'تعریف محتوا',
            self::PREVIEW        => 'پیش‌نمایش',
            self::PUBLISHED      => 'منتشرشده',
            self::FAILED         => 'خطا',
        };
    }
}

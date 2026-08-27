<?php
/**
 * WizardMode — تعریف سه حالت ساخت سکانس
 *
 * Pure PHP enum — بدون هیچ import وردپرسی
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * سه حالت ساخت سکانس:
 *
 * GOLDEN_MASTER  → ادمین PNG آپلود می‌کند؛ Vision API متن‌ها را استخراج می‌کند؛
 *                  GD inpainter متن‌ها را پاک می‌کند؛ overlay editor روی canvas.
 *
 * FRAME_UPLOAD   → ادمین فریم‌های WebP/JPEG را یک‌به‌یک آپلود می‌کند.
 *
 * AI_GENERATE    → ادمین prompt می‌نویسد؛ OpenAI + Replicate فریم‌ها را می‌سازند.
 */
enum WizardMode: string
{
    case GOLDEN_MASTER = 'golden_master';
    case FRAME_UPLOAD  = 'frame_upload';
    case AI_GENERATE   = 'ai_generate';

    /** label فارسی برای نمایش در UI */
    public function label(): string
    {
        return match ($this) {
            self::GOLDEN_MASTER => 'آپلود Golden Master PNG',
            self::FRAME_UPLOAD  => 'آپلود فریم‌های آماده',
            self::AI_GENERATE   => 'ساخت با هوش مصنوعی',
        };
    }

    /** آیکون Bootstrap Icons */
    public function icon(): string
    {
        return match ($this) {
            self::GOLDEN_MASTER => 'bi-image-fill',
            self::FRAME_UPLOAD  => 'bi-collection-fill',
            self::AI_GENERATE   => 'bi-stars',
        };
    }
}

<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * WizardMode — سه حالت ورودی برای ساخت سکانس
 */
enum WizardMode: string
{
    /** آپلود Last Frame PNG/JPG → Vision + Inpaint + Canvas */
    case LAST_FRAME   = 'last_frame';

    /** آپلود مستقیم فریم‌های آماده */
    case FRAME_UPLOAD = 'frame_upload';

    /** ساخت Last Frame از روی prompt با AI */
    case AI_GENERATE  = 'ai_generate';

    public function label(): string
    {
        return match ($this) {
            self::LAST_FRAME   => 'آپلود Last Frame',
            self::FRAME_UPLOAD => 'آپلود فریم‌های آماده',
            self::AI_GENERATE  => 'ساخت با هوش مصنوعی',
        };
    }

    /** آیا این حالت نیاز به Canvas Overlay Editor دارد؟ */
    public function needsCanvasEditor(): bool
    {
        return match ($this) {
            self::LAST_FRAME, self::AI_GENERATE => true,
            self::FRAME_UPLOAD                  => false,
        };
    }

    /** آیا این حالت نیاز به Frame Generation دارد؟ */
    public function needsFrameGeneration(): bool
    {
        return match ($this) {
            self::LAST_FRAME, self::AI_GENERATE => true,
            self::FRAME_UPLOAD                  => false,
        };
    }
}

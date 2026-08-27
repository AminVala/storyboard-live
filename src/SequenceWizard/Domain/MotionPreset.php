<?php
/**
 * MotionPreset — حالت‌های حرکت برای انیمیشن ابتدای سکانس
 *
 * Pure PHP enum — بدون import وردپرسی
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * پریست‌های حرکت بین frame 0 و frame N (Last Frame).
 *
 * در همه پریست‌ها frame N = دقیقاً همان Last Frame بدون هیچ تغییری.
 * frame 0 بسته به پریست در حالت متفاوتی شروع می‌کند.
 */
enum MotionPreset: string
{
    /** زوم به داخل در مرکز — ساده‌ترین و زیباترین */
    case ZOOM_OUT_CENTER = 'zoom_out_center';

    /** زوم به خارج + پن از چپ به راست */
    case ZOOM_OUT_PAN_LR = 'zoom_out_pan_lr';

    /** زوم به خارج + پن از راست به چپ */
    case ZOOM_OUT_PAN_RL = 'zoom_out_pan_rl';

    /** زوم به خارج + پن از بالا به پایین */
    case ZOOM_OUT_PAN_TB = 'zoom_out_pan_tb';

    /** Blur reveal — بدون zoom، فقط از blur به sharp */
    case BLUR_REVEAL = 'blur_reveal';

    /** پن خالص از چپ */
    case PAN_FROM_LEFT = 'pan_from_left';

    /** پن خالص از راست */
    case PAN_FROM_RIGHT = 'pan_from_right';

    /** پن خالص از بالا */
    case PAN_FROM_TOP = 'pan_from_top';

    /**
     * تنظیمات پیش‌فرض هر پریست:
     * [zoom_factor, pan_x_rel, pan_y_rel, blur_px]
     * همه مقادیر برای frame 0 هستند — frame N همیشه [1.0, 0.0, 0.0, 0]
     *
     * @return array{zoom:float, pan_x:float, pan_y:float, blur:float}
     */
    public function defaults(): array
    {
        return match ($this) {
            self::ZOOM_OUT_CENTER => ['zoom' => 1.5,  'pan_x' => 0.0,   'pan_y' => 0.0,   'blur' => 0.0],
            self::ZOOM_OUT_PAN_LR => ['zoom' => 1.4,  'pan_x' => -0.25, 'pan_y' => 0.0,   'blur' => 0.0],
            self::ZOOM_OUT_PAN_RL => ['zoom' => 1.4,  'pan_x' =>  0.25, 'pan_y' => 0.0,   'blur' => 0.0],
            self::ZOOM_OUT_PAN_TB => ['zoom' => 1.4,  'pan_x' => 0.0,   'pan_y' => -0.2,  'blur' => 0.0],
            self::BLUR_REVEAL     => ['zoom' => 1.0,  'pan_x' => 0.0,   'pan_y' => 0.0,   'blur' => 12.0],
            self::PAN_FROM_LEFT   => ['zoom' => 1.0,  'pan_x' => -0.35, 'pan_y' => 0.0,   'blur' => 0.0],
            self::PAN_FROM_RIGHT  => ['zoom' => 1.0,  'pan_x' =>  0.35, 'pan_y' => 0.0,   'blur' => 0.0],
            self::PAN_FROM_TOP    => ['zoom' => 1.0,  'pan_x' => 0.0,   'pan_y' => -0.25, 'blur' => 0.0],
        };
    }

    /** label فارسی */
    public function label(): string
    {
        return match ($this) {
            self::ZOOM_OUT_CENTER => 'زوم به خارج از مرکز',
            self::ZOOM_OUT_PAN_LR => 'زوم + حرکت چپ به راست',
            self::ZOOM_OUT_PAN_RL => 'زوم + حرکت راست به چپ',
            self::ZOOM_OUT_PAN_TB => 'زوم + حرکت بالا به پایین',
            self::BLUR_REVEAL     => 'نمایش از blur به شارپ',
            self::PAN_FROM_LEFT   => 'ورود از سمت چپ',
            self::PAN_FROM_RIGHT  => 'ورود از سمت راست',
            self::PAN_FROM_TOP    => 'ورود از بالا',
        };
    }

    /** آیا این پریست نیاز به zoom دارد؟ */
    public function hasZoom(): bool
    {
        return ! in_array($this, [self::BLUR_REVEAL, self::PAN_FROM_LEFT, self::PAN_FROM_RIGHT, self::PAN_FROM_TOP], true);
    }

    /** آیا این پریست blur دارد؟ */
    public function hasBlur(): bool
    {
        return $this === self::BLUR_REVEAL;
    }
}

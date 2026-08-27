<?php
/**
 * StartFrameConfig — Value Object تنظیمات frame اول (frame 0)
 *
 * Immutable — هیچ setter وجود ندارد.
 * Pure PHP — بدون import وردپرسی.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * تنظیم نقطه شروع انیمیشن.
 *
 * Frame N (Last Frame) = دقیقاً تصویر clean_last_frame — بدون هیچ transform.
 * Frame 0 = محاسبه معکوس از این تنظیمات.
 *
 * مختصات pan نسبی‌اند (0.0 = مرکز، -0.25 = ۲۵٪ به چپ/بالا).
 */
final class StartFrameConfig
{
    /**
     * @param MotionPreset $preset     پریست انتخاب‌شده
     * @param float        $zoomFactor زوم frame 0 نسبت به Last Frame (1.0 = بدون زوم، 2.0 = ۲× بزرگتر)
     * @param float        $panXRel    offset افقی frame 0 نسبت به مرکز (-0.5 تا 0.5)
     * @param float        $panYRel    offset عمودی frame 0 نسبت به مرکز (-0.5 تا 0.5)
     * @param float        $blurPx     blur اولیه (Gaussian radius، صفر = بدون blur)
     * @param int          $frameCount تعداد کل فریم‌ها (frame 0 تا frame N)
     * @param string       $easing     نوع easing function
     */
    public function __construct(
        public readonly MotionPreset $preset,
        public readonly float        $zoomFactor  = 1.5,
        public readonly float        $panXRel     = 0.0,
        public readonly float        $panYRel     = 0.0,
        public readonly float        $blurPx      = 0.0,
        public readonly int          $frameCount  = 36,
        public readonly string       $easing      = 'ease_in_out',
    ) {
        if ($zoomFactor < 1.0 || $zoomFactor > 3.0) {
            throw new \InvalidArgumentException("zoomFactor must be 1.0–3.0, got {$zoomFactor}");
        }
        if ($panXRel < -0.5 || $panXRel > 0.5) {
            throw new \InvalidArgumentException("panXRel must be -0.5–0.5, got {$panXRel}");
        }
        if ($panYRel < -0.5 || $panYRel > 0.5) {
            throw new \InvalidArgumentException("panYRel must be -0.5–0.5, got {$panYRel}");
        }
        if ($blurPx < 0.0 || $blurPx > 30.0) {
            throw new \InvalidArgumentException("blurPx must be 0–30, got {$blurPx}");
        }
        if ($frameCount < 8 || $frameCount > 120) {
            throw new \InvalidArgumentException("frameCount must be 8–120, got {$frameCount}");
        }
        if (! in_array($easing, ['ease_in_out', 'ease_out', 'ease_in', 'linear'], true)) {
            throw new \InvalidArgumentException("Invalid easing: {$easing}");
        }
    }

    /** ساخت از پریست با مقادیر پیش‌فرض */
    public static function fromPreset(MotionPreset $preset, int $frameCount = 36): self
    {
        $d = $preset->defaults();
        return new self(
            preset:     $preset,
            zoomFactor: $d['zoom'],
            panXRel:    $d['pan_x'],
            panYRel:    $d['pan_y'],
            blurPx:     $d['blur'],
            frameCount: $frameCount,
        );
    }

    /** ساخت از آرایه ذخیره‌شده */
    public static function fromArray(array $d): self
    {
        return new self(
            preset:     MotionPreset::from($d['preset'] ?? MotionPreset::ZOOM_OUT_CENTER->value),
            zoomFactor: (float) ($d['zoom_factor']  ?? 1.5),
            panXRel:    (float) ($d['pan_x_rel']    ?? 0.0),
            panYRel:    (float) ($d['pan_y_rel']    ?? 0.0),
            blurPx:     (float) ($d['blur_px']      ?? 0.0),
            frameCount: (int)   ($d['frame_count']  ?? 36),
            easing:     (string)($d['easing']       ?? 'ease_in_out'),
        );
    }

    /** serialize برای ذخیره در post_meta */
    public function toArray(): array
    {
        return [
            'preset'      => $this->preset->value,
            'zoom_factor' => $this->zoomFactor,
            'pan_x_rel'   => $this->panXRel,
            'pan_y_rel'   => $this->panYRel,
            'blur_px'     => $this->blurPx,
            'frame_count' => $this->frameCount,
            'easing'      => $this->easing,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}

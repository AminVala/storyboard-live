<?php
/**
 * OverlayPosition — Value Object موقعیت یک عنصر overlay
 *
 * Immutable — هیچ setter وجود ندارد.
 * Pure PHP — بدون import وردپرسی.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * موقعیت و اندازه هر overlay item روی canvas.
 *
 * مختصات نسبی هستند (0.0–1.0) نسبت به عرض/ارتفاع تصویر
 * تا در هر اندازه‌ای (موبایل/تبلت/دسکتاپ) درست رندر شود.
 */
final class OverlayPosition
{
    public function __construct(
        /** موقعیت افقی — 0.0 چپ، 1.0 راست */
        public readonly float $xRel,
        /** موقعیت عمودی — 0.0 بالا، 1.0 پایین */
        public readonly float $yRel,
        /** عرض نسبی — 0.0 تا 1.0 */
        public readonly float $widthRel,
        /** ارتفاع نسبی — 0.0 تا 1.0 */
        public readonly float $heightRel,
        /** زاویه چرخش به درجه */
        public readonly float $rotation = 0.0,
    ) {
        if ($xRel < 0.0 || $xRel > 1.0) {
            throw new \InvalidArgumentException("xRel must be 0.0–1.0, got {$xRel}");
        }
        if ($yRel < 0.0 || $yRel > 1.0) {
            throw new \InvalidArgumentException("yRel must be 0.0–1.0, got {$yRel}");
        }
        if ($widthRel <= 0.0 || $widthRel > 1.0) {
            throw new \InvalidArgumentException("widthRel must be >0.0 and ≤1.0, got {$widthRel}");
        }
        if ($heightRel <= 0.0 || $heightRel > 1.0) {
            throw new \InvalidArgumentException("heightRel must be >0.0 and ≤1.0, got {$heightRel}");
        }
    }

    /** ساخت از آرایه ذخیره‌شده در post_meta */
    public static function fromArray(array $data): self
    {
        return new self(
            xRel:      (float) ($data['x_rel']      ?? 0.0),
            yRel:      (float) ($data['y_rel']      ?? 0.0),
            widthRel:  (float) ($data['width_rel']  ?? 0.2),
            heightRel: (float) ($data['height_rel'] ?? 0.1),
            rotation:  (float) ($data['rotation']   ?? 0.0),
        );
    }

    /** تبدیل به آرایه برای ذخیره در post_meta / JSON */
    public function toArray(): array
    {
        return [
            'x_rel'      => $this->xRel,
            'y_rel'      => $this->yRel,
            'width_rel'  => $this->widthRel,
            'height_rel' => $this->heightRel,
            'rotation'   => $this->rotation,
        ];
    }

    /**
     * تبدیل به CSS inline style — px با دانستن اندازه container
     *
     * @param int $containerWidth  عرض container به پیکسل
     * @param int $containerHeight ارتفاع container به پیکسل
     */
    public function toCssStyle(int $containerWidth, int $containerHeight): string
    {
        $left   = round($this->xRel * $containerWidth);
        $top    = round($this->yRel * $containerHeight);
        $width  = round($this->widthRel * $containerWidth);
        $height = round($this->heightRel * $containerHeight);
        $rotate = $this->rotation !== 0.0 ? "rotate({$this->rotation}deg)" : '';

        return "position:absolute;left:{$left}px;top:{$top}px;"
             . "width:{$width}px;height:{$height}px;"
             . ($rotate ? "transform:{$rotate};" : '');
    }

    /** مقایسه equality */
    public function equals(self $other): bool
    {
        return $this->xRel === $other->xRel
            && $this->yRel === $other->yRel
            && $this->widthRel === $other->widthRel
            && $this->heightRel === $other->heightRel
            && $this->rotation === $other->rotation;
    }
}

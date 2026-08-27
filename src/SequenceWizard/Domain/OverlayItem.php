<?php
/**
 * OverlayItem — Value Object یک عنصر متنی روی canvas
 *
 * Pure PHP — بدون import وردپرسی.
 * Immutable position + mutable style (توسط Aggregate تغییر می‌کند).
 *
 * مختصات: همیشه normalized (0.0–1.0) نسبت به ابعاد تصویر.
 * CSS: left = x_rel×100%, top = y_rel×100%, ...
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Domain
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Domain;

/**
 * یک overlay item:
 *   - موقعیت و اندازه normalized (0.0–1.0)
 *   - محتوای HTML قابل ویرایش (sanitized)
 *   - استایل font
 */
final class OverlayItem
{
    /**
     * @param string  $id         uuid v4 — تغییر نمی‌کند
     * @param string  $html       محتوای HTML (sanitized, فقط تگ‌های مجاز)
     * @param float   $xRel       موقعیت افقی لبه چپ (0.0=چپ, 1.0=راست)
     * @param float   $yRel       موقعیت عمودی لبه بالا (0.0=بالا, 1.0=پایین)
     * @param float   $widthRel   عرض (0.0–1.0)
     * @param float   $heightRel  ارتفاع (0.0–1.0)
     * @param string  $fontFamily نام فونت (inherit, Vazirmatn, ...)
     * @param string  $fontSize   اندازه فونت (مثلاً: 2.5rem, 24px, ...)
     * @param string  $fontWeight وزن فونت (400, 700, bold, ...)
     * @param string  $color      رنگ CSS (مثلاً: #ffffff, rgba(0,0,0,.8))
     * @param string  $textAlign  تراز (right, left, center)
     * @param float   $rotation   چرخش به درجه (0.0 = بدون چرخش)
     */
    public function __construct(
        public readonly string $id,
        public string          $html,
        public float           $xRel,
        public float           $yRel,
        public float           $widthRel,
        public float           $heightRel,
        public string          $fontFamily  = 'inherit',
        public string          $fontSize    = '1.5rem',
        public string          $fontWeight  = '700',
        public string          $color       = '#ffffff',
        public string          $textAlign   = 'right',
        public float           $rotation    = 0.0,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->xRel < 0.0 || $this->xRel > 1.0) {
            throw new \InvalidArgumentException("xRel must be 0.0–1.0");
        }
        if ($this->yRel < 0.0 || $this->yRel > 1.0) {
            throw new \InvalidArgumentException("yRel must be 0.0–1.0");
        }
        if ($this->widthRel <= 0.0 || $this->widthRel > 1.0) {
            throw new \InvalidArgumentException("widthRel must be >0.0 and ≤1.0");
        }
        if ($this->heightRel <= 0.0 || $this->heightRel > 1.0) {
            throw new \InvalidArgumentException("heightRel must be >0.0 and ≤1.0");
        }
        if ($this->rotation < -360.0 || $this->rotation > 360.0) {
            throw new \InvalidArgumentException("rotation must be -360–360");
        }
    }

    /**
     * CSS inline style برای overlay div
     * همه‌چیز با % — بنابراین با هر اندازه‌ای scale می‌شود
     */
    public function toCssStyle(): string
    {
        $parts = [
            'position:absolute',
            sprintf('left:%.4f%%', $this->xRel * 100),
            sprintf('top:%.4f%%', $this->yRel * 100),
            sprintf('width:%.4f%%', $this->widthRel * 100),
            sprintf('height:%.4f%%', $this->heightRel * 100),
            "font-family:{$this->fontFamily}",
            "font-size:{$this->fontSize}",
            "font-weight:{$this->fontWeight}",
            "color:{$this->color}",
            "text-align:{$this->textAlign}",
            'box-sizing:border-box',
            'overflow:hidden',
            'word-break:break-word',
        ];

        if ($this->rotation !== 0.0) {
            $parts[] = "transform:rotate({$this->rotation}deg)";
        }

        return implode(';', $parts);
    }

    /** JSON برای JS canvas editor */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'html'       => $this->html,
            'x_rel'      => $this->xRel,
            'y_rel'      => $this->yRel,
            'width_rel'  => $this->widthRel,
            'height_rel' => $this->heightRel,
            'fontFamily' => $this->fontFamily,
            'fontSize'   => $this->fontSize,
            'fontWeight' => $this->fontWeight,
            'color'      => $this->color,
            'textAlign'  => $this->textAlign,
            'rotation'   => $this->rotation,
            'cssStyle'   => $this->toCssStyle(),
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            id:         $d['id'],
            html:       $d['html']        ?? '',
            xRel:       (float)($d['x_rel']      ?? 0.0),
            yRel:       (float)($d['y_rel']      ?? 0.0),
            widthRel:   (float)($d['width_rel']  ?? 0.2),
            heightRel:  (float)($d['height_rel'] ?? 0.08),
            fontFamily: $d['fontFamily']  ?? 'inherit',
            fontSize:   $d['fontSize']    ?? '1.5rem',
            fontWeight: $d['fontWeight']  ?? '700',
            color:      $d['color']       ?? '#ffffff',
            textAlign:  $d['textAlign']   ?? 'right',
            rotation:   (float)($d['rotation']   ?? 0.0),
        );
    }

    /** ساخت از detection Vision API */
    public static function fromDetection(string $id, array $d): self
    {
        // Vision API text را به HTML تبدیل می‌کنیم
        $text = htmlspecialchars($d['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // font size تخمینی از height detection (نسبی)
        $heightRel = (float)($d['height_rel'] ?? 0.08);
        $fontSize  = self::estimateFontSize($heightRel);

        return new self(
            id:        $id,
            html:      $text,
            xRel:      max(0.0, min(1.0, (float)($d['x_rel']      ?? 0.0))),
            yRel:      max(0.0, min(1.0, (float)($d['y_rel']      ?? 0.0))),
            widthRel:  max(0.02, min(1.0, (float)($d['width_rel']  ?? 0.3))),
            heightRel: max(0.02, min(1.0, $heightRel)),
            fontSize:  $fontSize,
        );
    }

    /**
     * تخمین font size از height نسبی.
     * Height ≈ 0.04 → ~1rem, Height ≈ 0.08 → ~2rem, ...
     */
    private static function estimateFontSize(float $heightRel): string
    {
        // تقریباً: font_size_rem = heightRel / 0.04
        $rem = round($heightRel / 0.04, 1);
        $rem = max(0.6, min(6.0, $rem));
        return "{$rem}rem";
    }

    public function withHtml(string $html): self
    {
        $clone = clone $this;
        $clone->html = $html;
        return $clone;
    }
}

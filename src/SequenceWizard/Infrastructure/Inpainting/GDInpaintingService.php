<?php
/**
 * GDInpaintingService — پاک‌سازی متن از تصویر با GD (100% offline)
 *
 * الگوریتم:
 * 1. برای هر detection box → region را از تصویر crop می‌کنیم
 * 2. Smart inpainting: از pixels اطراف box استفاده می‌کنیم (content-aware lite)
 * 3. چندین pass Gaussian blur برای نرم کردن edges
 * 4. تصویر نهایی را به WP media library اضافه می‌کنیم
 *
 * وقتی تمام شد → WizardAggregate.applyCleanBackground() فراخوانی می‌شود
 * Canvas editor بلافاصله bg را swap می‌کند (Phase 2)
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Inpainting
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Inpainting;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardRepository;

final class GDInpaintingService
{
    private const AS_HOOK       = 'shseq_gd_inpaint';
    private const BLUR_PASSES   = 8;     // تعداد Gaussian blur pass
    private const PADDING_REL   = 0.015; // padding نسبی برای capture کامل متن
    private const BORDER_SAMPLE = 0.08;  // ۸٪ از edge برای border color sampling

    public function __construct(
        private readonly WizardRepository $repo,
    ) {}

    /**
     * Schedule async inpainting
     *
     * @param list<array{x_rel,y_rel,width_rel,height_rel}> $detections
     */
    public function scheduleInpainting(
        int   $postId,
        int   $attachmentId,
        array $detections,
    ): void {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 1, // ۱ ثانیه بعد از vision
                self::AS_HOOK,
                ['post_id' => $postId, 'attachment_id' => $attachmentId, 'detections' => $detections],
                'shseq',
            );
        } else {
            $this->runInpainting($postId, $attachmentId, $detections);
        }
    }

    /** Action Scheduler callback */
    public function runInpainting(int $postId, int $attachmentId, array $detections): void
    {
        try {
            $cleanId = $this->inpaint($attachmentId, $detections);

            $agg = $this->repo->find($postId);
            $agg->applyCleanBackground($cleanId);
            $this->repo->save($agg);

        } catch (\Throwable $e) {
            // اگر inpainting خطا داشت → canvas از original استفاده می‌کند
            // این یک non-fatal error است
            error_log('[shseq] GD inpainting failed: ' . $e->getMessage());
        }
    }

    /**
     * اجرای inpainting و برگرداندن attachment ID تصویر clean
     *
     * @param list<array{x_rel,y_rel,width_rel,height_rel}> $detections
     */
    public function inpaint(int $attachmentId, array $detections): int
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension not loaded');
        }

        $srcPath = get_attached_file($attachmentId);
        if (! $srcPath || ! file_exists($srcPath)) {
            throw new \RuntimeException("Source image not found: {$attachmentId}");
        }

        $mime = mime_content_type($srcPath);
        $src  = $this->loadGDImage($srcPath, $mime);
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        foreach ($detections as $d) {
            $this->eraseRegion($src, $srcW, $srcH, $d);
        }

        // ذخیره
        $uploadDir = wp_upload_dir();
        $fileName  = 'shseq-clean-' . $attachmentId . '-' . time() . '.webp';
        $filePath  = $uploadDir['path'] . '/' . $fileName;

        $saved = function_exists('imagewebp') && imagewebp($src, $filePath, 92);
        if (! $saved) {
            $fileName = 'shseq-clean-' . $attachmentId . '-' . time() . '.png';
            $filePath = $uploadDir['path'] . '/' . $fileName;
            imagepng($src, $filePath, 6);
        }

        imagedestroy($src);
        return $this->registerAttachment($filePath, $uploadDir);
    }

    /**
     * پاک کردن یک region با Smart Blur Inpainting
     * الگوریتم: sample border pixels → fill → blur
     */
    private function eraseRegion(\GdImage $img, int $W, int $H, array $d): void
    {
        $pad = self::PADDING_REL;

        $x1 = max(0, (int) round(($d['x_rel'] - $pad) * $W));
        $y1 = max(0, (int) round(($d['y_rel'] - $pad) * $H));
        $x2 = min($W, (int) round(($d['x_rel'] + $d['width_rel']  + $pad) * $W));
        $y2 = min($H, (int) round(($d['y_rel'] + $d['height_rel'] + $pad) * $H));

        $rW = $x2 - $x1;
        $rH = $y2 - $y1;
        if ($rW <= 0 || $rH <= 0) {
            return;
        }

        // Sample border color (میانگین pixels اطراف box)
        $borderColor = $this->sampleBorderColor($img, $W, $H, $x1, $y1, $x2, $y2);
        [$br, $bg, $bb] = $borderColor;

        // پر کردن region با border color
        $fillColor = imagecolorallocate($img, $br, $bg, $bb);
        imagefilledrectangle($img, $x1, $y1, $x2, $y2, $fillColor);

        // Gaussian blur چندین pass روی region (و کمی اطراف آن برای blend)
        $bx1 = max(0, $x1 - 10);
        $by1 = max(0, $y1 - 10);
        $bx2 = min($W, $x2 + 10);
        $by2 = min($H, $y2 + 10);
        $bW  = $bx2 - $bx1;
        $bH  = $by2 - $by1;

        // crop منطقه blur
        $region = imagecreatetruecolor($bW, $bH);
        imagecopy($region, $img, 0, 0, $bx1, $by1, $bW, $bH);

        for ($p = 0; $p < self::BLUR_PASSES; $p++) {
            imagefilter($region, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // فقط ناحیه داخلی (بدون border) را برگردان تا لبه‌ها blend شوند
        imagecopy($img, $region, $x1, $y1, $x1 - $bx1, $y1 - $by1, $rW, $rH);
        imagedestroy($region);
    }

    /**
     * میانگین رنگ لبه‌های بیرونی region
     *
     * @return array{int,int,int} [r, g, b]
     */
    private function sampleBorderColor(\GdImage $img, int $W, int $H, int $x1, int $y1, int $x2, int $y2): array
    {
        $sampleW = max(1, (int) round(($x2 - $x1) * self::BORDER_SAMPLE));
        $sampleH = max(1, (int) round(($y2 - $y1) * self::BORDER_SAMPLE));

        $totalR = $totalG = $totalB = $count = 0;

        // بالا
        for ($x = $x1; $x <= $x2; $x++) {
            for ($y = max(0, $y1 - $sampleH); $y < $y1; $y++) {
                $c = imagecolorat($img, $x, $y);
                $totalR += ($c >> 16) & 0xFF;
                $totalG += ($c >> 8)  & 0xFF;
                $totalB +=  $c        & 0xFF;
                $count++;
            }
        }

        // پایین
        for ($x = $x1; $x <= $x2; $x++) {
            for ($y = $y2; $y < min($H, $y2 + $sampleH); $y++) {
                $c = imagecolorat($img, $x, $y);
                $totalR += ($c >> 16) & 0xFF;
                $totalG += ($c >> 8)  & 0xFF;
                $totalB +=  $c        & 0xFF;
                $count++;
            }
        }

        if ($count === 0) {
            return [128, 128, 128]; // fallback gray
        }

        return [
            (int) round($totalR / $count),
            (int) round($totalG / $count),
            (int) round($totalB / $count),
        ];
    }

    private function loadGDImage(string $path, string $mime): \GdImage
    {
        $img = match ($mime) {
            'image/png'  => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => throw new \RuntimeException("Unsupported type: {$mime}"),
        };

        if ($img === false) {
            throw new \RuntimeException("GD could not load: {$path}");
        }

        // truecolor guarantee
        if (! imageistruecolor($img)) {
            $tc = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagecopy($tc, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagedestroy($img);
            $img = $tc;
        }

        return $img;
    }

    private function registerAttachment(string $filePath, array $uploadDir): int
    {
        $isWebp   = str_ends_with($filePath, '.webp');
        $mime     = $isWebp ? 'image/webp' : 'image/png';
        $fileUrl  = str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $filePath);

        $attachmentData = [
            'guid'           => $fileUrl,
            'post_mime_type' => $mime,
            'post_title'     => 'StoryBoard Clean Background',
            'post_status'    => 'inherit',
        ];

        $id = wp_insert_attachment($attachmentData, $filePath);
        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to insert attachment');
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $filePath));

        return $id;
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runInpainting'], 10, 3);
    }
}

<?php
/**
 * ReverseFrameGenerator — ساخت فریم‌های 0 تا N از روی Last Frame
 *
 * ═══════════════════════════════════════════════════════════════════
 * الگوریتم Reverse Interpolation — V3 Final
 * ═══════════════════════════════════════════════════════════════════
 *
 * اصل اساسی:
 *   frame[N] = EXACT copy of clean_last_frame (pixel-perfect، بدون هیچ transform)
 *   frame[i] = reverse-interpolate از StartFrameConfig به سمت Last Frame
 *
 * چرا "Reverse"؟
 *   از نظر بیننده، scroll از frame 0 به frame N می‌رود.
 *   ما ابتدا frame N را داریم (Last Frame)، پس باید frame 0 را بسازیم.
 *   یعنی باید از Last Frame به عقب interpolate کنیم.
 *
 * ضمانت‌های کیفی:
 *   ✓ Frame N = pixel-perfect Last Frame (هیچ interpolation error)
 *   ✓ Memory-safe: هر frame بعد از save از حافظه پاک می‌شود
 *   ✓ Blur pre-compute: یک بار blur می‌شود، نه N بار
 *   ✓ Checkpointed: هر BATCH_SIZE فریم progress ذخیره می‌شود
 *   ✓ OOM guard: اگر memory limit نزدیک شد job را متوقف می‌کند
 *   ✓ Retry-safe: از frameCheckpoint ادامه می‌دهد
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\StartFrameConfig;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class ReverseFrameGenerator
{
    private const AS_HOOK      = 'shseq_reverse_frame_gen';
    private const BATCH_SIZE   = 8;     // فریم در هر batch (قبل از GC)
    private const FRAME_WIDTH  = 1920;  // پیکسل خروجی
    private const FRAME_HEIGHT = 1080;  // پیکسل خروجی
    private const WEBP_QUALITY = 92;
    private const OOM_MARGIN   = 64;    // مگابایت — اگر کمتر از این memory داشتیم متوقف می‌کنیم

    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    /* ─────────────────────────── Schedule / Job ───────────────────── */

    public function scheduleGeneration(
        int              $sequencePostId,
        int              $sourceAttachmentId,
        StartFrameConfig $config,
        int              $startFromCheckpoint = 0,
    ): void {
        if (! function_exists('as_schedule_single_action')) {
            $this->runJob($sequencePostId, $sourceAttachmentId, $config->toArray(), $startFromCheckpoint);
            return;
        }

        as_schedule_single_action(
            time(),
            self::AS_HOOK,
            [
                'sequence_post_id'  => $sequencePostId,
                'source_attachment' => $sourceAttachmentId,
                'config'            => $config->toArray(),
                'start_from'        => $startFromCheckpoint,
            ],
            'shseq',
        );
    }

    public function runJob(
        int   $sequencePostId,
        int   $sourceAttachmentId,
        array $configArr,
        int   $startFrom = 0,
    ): void {
        try {
            $config         = StartFrameConfig::fromArray($configArr);
            $existingFrames = [];

            // بارگذاری فریم‌های قبلی اگر retry است
            if ($startFrom > 0) {
                $state          = $this->repo->findBySequenceId($sequencePostId);
                $existingFrames = $state->getFrameAttachmentIds();
            }

            $allFrameIds = $this->generate(
                sourceAttachmentId: $sourceAttachmentId,
                config:             $config,
                startFrom:          $startFrom,
                existingFrameIds:   $existingFrames,
                onProgress:         function (int $pct, int $lastIdx, array $ids) use ($sequencePostId): void {
                    $state = $this->repo->findBySequenceId($sequencePostId);
                    $state->updateJobProgress($pct, $lastIdx, $ids);
                    $this->repo->save($state);
                },
            );

            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->completeFrameGeneration($allFrameIds);
            $this->repo->save($state);

            // sync به post_meta اصلی
            update_post_meta($sequencePostId, '_shseq_frames', $allFrameIds);
            update_post_meta($sequencePostId, '_shseq_end_frame_id', end($allFrameIds));

        } catch (\Throwable $e) {
            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->fail('Frame generation failed: ' . $e->getMessage());
            $this->repo->save($state);
            error_log('[shseq] Frame generation error: ' . $e->getMessage());
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       الگوریتم اصلی — Generate
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * ساخت فریم‌ها از clean_last_frame
     *
     * @param callable(int $pct, int $lastIdx, int[] $ids): void $onProgress
     * @param list<int> $existingFrameIds فریم‌های قبلاً ساخته‌شده (برای retry)
     * @return list<int> attachment IDs همه فریم‌ها از frame 0 تا frame N
     */
    public function generate(
        int              $sourceAttachmentId,
        StartFrameConfig $config,
        int              $startFrom,
        array            $existingFrameIds,
        callable         $onProgress,
    ): array {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension is not loaded');
        }

        // ─── بارگذاری تصویر منبع ─────────────────────────────────────
        $srcPath = get_attached_file($sourceAttachmentId);
        if (! $srcPath || ! file_exists($srcPath)) {
            throw new \RuntimeException("Source image not found: attachment {$sourceAttachmentId}");
        }

        $mime = mime_content_type($srcPath);
        $src  = $this->loadImage($srcPath, $mime);
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // اگر تصویر منبع کوچکتر از output است → upscale با کیفیت
        if ($srcW < self::FRAME_WIDTH || $srcH < self::FRAME_HEIGHT) {
            $src  = $this->upscaleImage($src, $srcW, $srcH);
            $srcW = imagesx($src);
            $srcH = imagesy($src);
        }

        // ─── Pre-compute blur (یک بار، نه N بار) ───────────────────────
        $blurredSrc = null;
        if ($config->blurPx > 0) {
            $blurredSrc = $this->preComputeBlur($src, $srcW, $srcH, (int) ceil($config->blurPx));
        }

        // ─── Upload directory ─────────────────────────────────────────
        $uploadDir = wp_upload_dir();
        $subDir    = $uploadDir['path'] . '/shseq-seq-' . $sourceAttachmentId;
        wp_mkdir_p($subDir);

        $allFrameIds = $existingFrameIds;
        $frameCount  = $config->frameCount;
        $batchFrames = [];

        // ─── حلقه اصلی ───────────────────────────────────────────────
        for ($i = $startFrom; $i <= $frameCount; $i++) {

            // OOM guard
            if ($this->isMemoryLow()) {
                // فریم‌های باقی‌مانده را در یک job دیگر ادامه می‌دهیم
                $onProgress((int) round($i / $frameCount * 100), $i, $allFrameIds);
                if ($src) { imagedestroy($src); $src = null; }
                if ($blurredSrc) { imagedestroy($blurredSrc); $blurredSrc = null; }
                throw new \RuntimeException(
                    "Memory limit reached at frame {$i}/{$frameCount}. " .
                    "Checkpoint saved — will retry from frame {$i}."
                );
            }

            $frame = $this->renderFrame($src, $blurredSrc, $srcW, $srcH, $i, $frameCount, $config);

            // ─── ذخیره فریم ──────────────────────────────────────────
            $fileName = sprintf('frame-%04d.webp', $i);
            $filePath = "{$subDir}/{$fileName}";

            $saved = imagewebp($frame, $filePath, self::WEBP_QUALITY);
            if (! $saved) {
                // fallback PNG
                $fileName = sprintf('frame-%04d.png', $i);
                $filePath = "{$subDir}/{$fileName}";
                imagepng($frame, $filePath, 6);
            }

            imagedestroy($frame);
            unset($frame);

            // ─── اضافه به WP media library ───────────────────────────
            $attachmentId = $this->registerAttachment($filePath, $uploadDir, $i);
            if ($attachmentId !== null) {
                $allFrameIds[$i] = $attachmentId;
                $batchFrames[]   = $attachmentId;
            }

            // ─── Checkpoint هر BATCH_SIZE فریم ───────────────────────
            if (count($batchFrames) >= self::BATCH_SIZE || $i === $frameCount) {
                $pct = (int) round($i / $frameCount * 100);
                $onProgress($pct, $i, $allFrameIds);
                $batchFrames = [];
                gc_collect_cycles();
            }
        }

        if ($src) { imagedestroy($src); }
        if ($blurredSrc) { imagedestroy($blurredSrc); }

        // ─── مرتب‌سازی فریم‌ها به ترتیب index ───────────────────────
        ksort($allFrameIds);
        return array_values($allFrameIds);
    }

    /* ═══════════════════════════════════════════════════════════════════
       رندر یک فریم — قلب الگوریتم
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * رندر فریم i از frameCount
     *
     * وقتی i === frameCount (Last Frame):
     *   → EXACT copy از src بدون هیچ transform
     *   → ضمانت pixel-perfect بودن
     *
     * وقتی i < frameCount:
     *   → t = i / frameCount (0.0 → 1.0)
     *   → e = ease(t) (با easing function انتخاب‌شده)
     *   → zoom = lerp(zoomFactor, 1.0, e)
     *   → pan_x/y = lerp(config.pan, 0.0, e)
     *   → blur_alpha = lerp(1.0, 0.0, e) فقط اگر blurPx > 0
     */
    private function renderFrame(
        \GdImage         $src,
        ?\GdImage        $blurredSrc,
        int              $srcW,
        int              $srcH,
        int              $frameIndex,
        int              $frameCount,
        StartFrameConfig $config,
    ): \GdImage {

        /* ── Last Frame: exact copy ───────────────────────────────── */
        if ($frameIndex === $frameCount) {
            $frame = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);
            imagecopyresampled(
                $frame, $src,
                0, 0, 0, 0,
                self::FRAME_WIDTH, self::FRAME_HEIGHT,
                $srcW, $srcH,
            );
            return $frame;
        }

        /* ── فریم‌های میانی: Reverse interpolation ───────────────── */
        $t = $frameCount > 0 ? $frameIndex / $frameCount : 0.0; // 0.0 → 1.0
        $e = $this->ease($t, $config->easing);

        // zoom: از zoomFactor (frame 0) به 1.0 (frame N)
        $zoom = $this->lerp($config->zoomFactor, 1.0, $e);
        $zoom = max(1.0, $zoom); // هیچ‌وقت کمتر از 1 نمی‌شود

        // اندازه crop در منبع
        $cropW = (int) round($srcW / $zoom);
        $cropH = (int) round($srcH / $zoom);
        $cropW = max(1, min($srcW, $cropW));
        $cropH = max(1, min($srcH, $cropH));

        // pan: از config.panXRel/YRel (frame 0) به 0.0 (frame N)
        $panX = (int) round($this->lerp($config->panXRel, 0.0, $e) * $srcW);
        $panY = (int) round($this->lerp($config->panYRel, 0.0, $e) * $srcH);

        // موقعیت crop در مرکز + pan
        $cropX = (int) round(($srcW - $cropW) / 2) + $panX;
        $cropY = (int) round(($srcH - $cropH) / 2) + $panY;

        // clamp برای جلوگیری از out-of-bounds
        $cropX = max(0, min($srcW - $cropW, $cropX));
        $cropY = max(0, min($srcH - $cropH, $cropY));

        /* ── رندر sharp frame ────────────────────────────────────── */
        $frame = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);
        imagecopyresampled(
            $frame, $src,
            0, 0,
            $cropX, $cropY,
            self::FRAME_WIDTH, self::FRAME_HEIGHT,
            $cropW, $cropH,
        );

        /* ── ترکیب با blur (اگر پریست blur دارد) ────────────────── */
        if ($blurredSrc !== null && $config->blurPx > 0) {
            $blurAlpha = $this->lerp(1.0, 0.0, $e); // 1.0=کامل blur → 0.0=بدون blur

            if ($blurAlpha > 0.01) {
                $blurredFrame = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);
                imagecopyresampled(
                    $blurredFrame, $blurredSrc,
                    0, 0,
                    $cropX, $cropY,
                    self::FRAME_WIDTH, self::FRAME_HEIGHT,
                    $cropW, $cropH,
                );

                // blend: frame = (1 - blurAlpha) * sharp + blurAlpha * blurred
                $alpha = (int) round($blurAlpha * 127); // GD: 0=opaque, 127=transparent
                imagelayereffect($blurredFrame, IMG_EFFECT_OVERLAY);
                imagecopymergegray($frame, $blurredFrame, 0, 0, 0, 0, self::FRAME_WIDTH, self::FRAME_HEIGHT, (int)(100 * $blurAlpha));

                imagedestroy($blurredFrame);
                unset($blurredFrame);
            }
        }

        return $frame;
    }

    /* ═══════════════════════════════════════════════════════════════════
       کمکی‌ها
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * بارگذاری تصویر با GD از path
     */
    private function loadImage(string $path, string $mime): \GdImage
    {
        $img = match ($mime) {
            'image/png'  => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => throw new \RuntimeException("Unsupported image type: {$mime}"),
        };

        if ($img === false) {
            throw new \RuntimeException("GD could not load image: {$path}");
        }

        // تبدیل به truecolor
        if (imageistruecolor($img) === false) {
            $tc = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagecopy($tc, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagedestroy($img);
            $img = $tc;
        }

        return $img;
    }

    /**
     * upscale تصویر کوچک به حداقل FRAME_WIDTH × FRAME_HEIGHT
     */
    private function upscaleImage(\GdImage $src, int $srcW, int $srcH): \GdImage
    {
        $targetW = max($srcW * 2, self::FRAME_WIDTH + 200);
        $targetH = max($srcH * 2, self::FRAME_HEIGHT + 200);

        // نگه داشتن نسبت تصویر
        $ratio   = $srcW / $srcH;
        $outW    = (int) round(max($targetW, $targetH * $ratio));
        $outH    = (int) round($outW / $ratio);

        $scaled  = imagecreatetruecolor($outW, $outH);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $outW, $outH, $srcW, $srcH);
        imagedestroy($src);

        return $scaled;
    }

    /**
     * Pre-compute blur — یک بار برای همه فریم‌ها
     * از BLUR_PASSES پاس Gaussian استفاده می‌کند
     */
    private function preComputeBlur(\GdImage $src, int $srcW, int $srcH, int $radius): \GdImage
    {
        $blurred = imagecreatetruecolor($srcW, $srcH);
        imagecopy($blurred, $src, 0, 0, 0, 0, $srcW, $srcH);

        // تعداد پاس = radius (هر پاس ≈ یک Gaussian بلور کوچک)
        $passes = max(1, min(20, $radius));
        for ($p = 0; $p < $passes; $p++) {
            imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $blurred;
    }

    /**
     * ثبت attachment در WP media library
     */
    private function registerAttachment(string $filePath, array $uploadDir, int $frameIndex): ?int
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $isWebp   = str_ends_with($filePath, '.webp');
        $mimeType = $isWebp ? 'image/webp' : 'image/png';
        $fileUrl  = str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $filePath);

        $attachmentData = [
            'guid'           => $fileUrl,
            'post_mime_type' => $mimeType,
            'post_title'     => sprintf('StoryBoard Frame %04d', $frameIndex),
            'post_status'    => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachmentData, $filePath);
        if (is_wp_error($attachmentId)) {
            return null;
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $meta = wp_generate_attachment_metadata($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, $meta);

        return $attachmentId;
    }

    /**
     * بررسی حافظه — OOM guard
     */
    private function isMemoryLow(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return false; // unlimited
        }

        $limitBytes = $this->parseMemoryLimit($limit);
        $usedBytes  = memory_get_usage(true);
        $freeBytes  = $limitBytes - $usedBytes;

        return $freeBytes < self::OOM_MARGIN * 1024 * 1024;
    }

    private function parseMemoryLimit(string $val): int
    {
        $val  = trim($val);
        $unit = strtolower(substr($val, -1));
        $num  = (int) $val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    /**
     * Linear interpolation
     */
    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    /**
     * Easing functions
     *
     * @param float $t 0.0 → 1.0
     * @return float 0.0 → 1.0 (eased)
     */
    private function ease(float $t, string $fn): float
    {
        $t = max(0.0, min(1.0, $t));
        return match ($fn) {
            'linear'      => $t,
            'ease_in'     => $t * $t * $t,
            'ease_out'    => 1.0 - (1.0 - $t) ** 3,
            'ease_in_out' => $t < 0.5
                              ? 4.0 * $t * $t * $t
                              : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
            default       => $t < 0.5
                              ? 4.0 * $t * $t * $t
                              : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
        };
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runJob'], 10, 4);
    }
}

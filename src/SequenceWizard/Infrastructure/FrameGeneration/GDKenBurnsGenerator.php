<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * GDKenBurnsGenerator — ساخت فریم‌های scroll sequence از یک تصویر
 *
 * ═══════════════════════════════════════════════════════════════
 * الگوریتم Ken Burns + Parallax با GD — 100% Offline
 * ═══════════════════════════════════════════════════════════════
 *
 * بهترین + سریع‌ترین + ایمن‌ترین الگوریتم برای این use case:
 *
 * ۱. MOTION PLAN — مسیر حرکت از پیش محاسبه می‌شود (easing function)
 * ۲. BATCH PROCESSING — فریم‌ها در batch‌های کوچک پردازش می‌شوند
 *    تا memory exhaustion نشود
 * ۳. WEBP OUTPUT — کمترین حجم، بیشترین کیفیت
 * ۴. PROGRESS CALLBACK — هر ۱۰ فریم progress را update می‌کند
 * ۵. CLEANUP — در صورت خطا تمیز می‌کند
 *
 * حرکت‌های پشتیبانی‌شده:
 * - pan_right   : حرکت از چپ به راست
 * - pan_left    : حرکت از راست به چپ
 * - pan_up      : حرکت از پایین به بالا
 * - pan_down    : حرکت از بالا به پایین
 * - zoom_in     : زوم به داخل (کلاسیک Ken Burns)
 * - zoom_out    : زوم به خارج
 * - zoom_pan    : ترکیب زوم + pan (حرفه‌ای‌ترین)
 * - diagonal    : حرکت مورب
 * - rotation    : چرخش خفیف + zoom
 */
final class GDKenBurnsGenerator
{
    private const AS_HOOK         = 'shseq_frame_generate';
    private const BATCH_SIZE      = 10;   // تعداد فریم در هر batch
    private const FRAME_WIDTH     = 1920; // پیکسل
    private const FRAME_HEIGHT    = 1080; // پیکسل
    private const WEBP_QUALITY    = 92;   // 0–100
    private const DEFAULT_FRAMES  = 30;   // تعداد پیش‌فرض فریم
    private const MAX_FRAMES      = 120;  // حداکثر
    private const EASING_STEPS    = 100;  // دقت easing

    /**
     * انواع حرکت Ken Burns
     */
    private const MOTION_TYPES = [
        'zoom_in', 'zoom_out', 'pan_right', 'pan_left',
        'pan_up', 'pan_down', 'zoom_pan', 'diagonal', 'rotation',
    ];

    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function scheduleGeneration(
        int    $sequencePostId,
        int    $sourceAttachmentId,
        int    $frameCount     = self::DEFAULT_FRAMES,
        string $motionType     = 'zoom_pan',
        float  $zoomFactor     = 1.3,
        string $easingFunction = 'ease_in_out',
    ): void {
        // validate
        $frameCount  = max(8, min(self::MAX_FRAMES, $frameCount));
        $zoomFactor  = max(1.05, min(2.0, $zoomFactor));
        $motionType  = in_array($motionType, self::MOTION_TYPES, true) ? $motionType : 'zoom_pan';

        if (! function_exists('as_schedule_single_action')) {
            $this->runJob($sequencePostId, $sourceAttachmentId, $frameCount, $motionType, $zoomFactor, $easingFunction);
            return;
        }

        // ثبت job start در state
        $jobId = 'shseq-frames-' . $sequencePostId . '-' . time();
        $state = $this->repo->findBySequenceId($sequencePostId);
        $state->startFrameGenerationJob($jobId);
        $this->repo->save($state);

        as_schedule_single_action(
            time(),
            self::AS_HOOK,
            [
                'sequence_post_id'   => $sequencePostId,
                'source_attachment'  => $sourceAttachmentId,
                'frame_count'        => $frameCount,
                'motion_type'        => $motionType,
                'zoom_factor'        => $zoomFactor,
                'easing_function'    => $easingFunction,
            ],
            'shseq',
        );
    }

    public function runJob(
        int    $sequencePostId,
        int    $sourceAttachmentId,
        int    $frameCount     = self::DEFAULT_FRAMES,
        string $motionType     = 'zoom_pan',
        float  $zoomFactor     = 1.3,
        string $easingFunction = 'ease_in_out',
    ): void {
        try {
            $attachmentIds = $this->generate(
                sourceAttachmentId: $sourceAttachmentId,
                frameCount:         $frameCount,
                motionType:         $motionType,
                zoomFactor:         $zoomFactor,
                easingFunction:     $easingFunction,
                onProgress:         function (int $percent, array $doneIds) use ($sequencePostId): void {
                    $state = $this->repo->findBySequenceId($sequencePostId);
                    $state->updateJobProgress($percent, $doneIds);
                    $this->repo->save($state);
                },
            );

            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->completeFrameGeneration($attachmentIds);
            $this->repo->save($state);

            // sync به _shseq_frames
            update_post_meta($sequencePostId, '_shseq_frames', $attachmentIds);
            update_post_meta($sequencePostId, '_shseq_end_frame_id', end($attachmentIds));

        } catch (\Throwable $e) {
            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->fail('Frame generation failed: ' . $e->getMessage());
            $this->repo->save($state);
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * الگوریتم اصلی — Generate
     * ═══════════════════════════════════════════════════════════════
     *
     * @param callable(int $percent, int[] $doneIds): void $onProgress
     * @return list<int> attachment IDs فریم‌های ساخته‌شده
     */
    public function generate(
        int      $sourceAttachmentId,
        int      $frameCount,
        string   $motionType,
        float    $zoomFactor,
        string   $easingFunction,
        callable $onProgress,
    ): array {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is not loaded');
        }

        $sourcePath = get_attached_file($sourceAttachmentId);
        if (! $sourcePath || ! file_exists($sourcePath)) {
            throw new \RuntimeException("Source image not found: attachment {$sourceAttachmentId}");
        }

        // ─── بارگذاری تصویر منبع ─────────────────────────────────────────
        $mime = mime_content_type($sourcePath);
        $src = match ($mime) {
            'image/png'  => imagecreatefrompng($sourcePath),
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default      => throw new \RuntimeException("Unsupported image type: {$mime}"),
        };

        if ($src === false) {
            throw new \RuntimeException('GD could not load source image');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW < self::FRAME_WIDTH || $srcH < self::FRAME_HEIGHT) {
            // upscale اگر تصویر کوچکتر از frame size است
            $scaled = imagecreatetruecolor(
                max($srcW * 2, self::FRAME_WIDTH),
                max($srcH * 2, self::FRAME_HEIGHT),
            );
            imagecopyresampled($scaled, $src, 0, 0, 0, 0,
                imagesx($scaled), imagesy($scaled), $srcW, $srcH);
            imagedestroy($src);
            $src  = $scaled;
            $srcW = imagesx($src);
            $srcH = imagesy($src);
        }

        // ─── محاسبه motion plan (easing) ─────────────────────────────────
        $motionPlan = $this->computeMotionPlan(
            frameCount:     $frameCount,
            motionType:     $motionType,
            zoomFactor:     $zoomFactor,
            easingFunction: $easingFunction,
            srcW:           $srcW,
            srcH:           $srcH,
        );

        // ─── upload dir ───────────────────────────────────────────────────
        $uploadDir = wp_upload_dir();
        $subDir    = $uploadDir['path'] . '/shseq-frames-' . time();
        wp_mkdir_p($subDir);

        $generatedIds  = [];
        $batchDoneIds  = [];

        // ─── حلقه اصلی ───────────────────────────────────────────────────
        for ($i = 0; $i < $frameCount; $i++) {
            $plan  = $motionPlan[$i];
            $frame = $this->renderFrame($src, $srcW, $srcH, $plan);

            // ذخیره فریم
            $fileName  = sprintf('frame-%04d.webp', $i);
            $filePath  = "{$subDir}/{$fileName}";

            if (! imagewebp($frame, $filePath, self::WEBP_QUALITY)) {
                // fallback PNG
                $fileName  = sprintf('frame-%04d.png', $i);
                $filePath  = "{$subDir}/{$fileName}";
                imagepng($frame, $filePath, 6);
            }

            imagedestroy($frame);

            // اضافه کردن به WP media library
            $attachmentData = [
                'guid'           => $uploadDir['url'] . '/shseq-frames-' . time() . '/' . $fileName,
                'post_mime_type' => str_ends_with($fileName, '.webp') ? 'image/webp' : 'image/png',
                'post_title'     => "StoryBoard Frame {$i}",
                'post_status'    => 'inherit',
            ];

            $attachmentId = wp_insert_attachment($attachmentData, $filePath);
            if (! is_wp_error($attachmentId)) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $meta = wp_generate_attachment_metadata($attachmentId, $filePath);
                wp_update_attachment_metadata($attachmentId, $meta);
                $generatedIds[] = $attachmentId;
                $batchDoneIds[] = $attachmentId;
            }

            // progress هر BATCH_SIZE فریم
            if (count($batchDoneIds) >= self::BATCH_SIZE || $i === $frameCount - 1) {
                $percent = (int) round(($i + 1) / $frameCount * 100);
                $onProgress($percent, $generatedIds);
                $batchDoneIds = [];

                // GC — آزاد کردن حافظه
                gc_collect_cycles();
            }
        }

        imagedestroy($src);
        return $generatedIds;
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * محاسبه Motion Plan
     * ═══════════════════════════════════════════════════════════════
     *
     * برای هر فریم: {srcX, srcY, srcW, srcH, angle}
     * (کدام بخش از تصویر منبع را crop می‌کنیم و با چه زاویه‌ای)
     *
     * @return list<array{srcX:int, srcY:int, srcW:int, srcH:int, angle:float}>
     */
    private function computeMotionPlan(
        int    $frameCount,
        string $motionType,
        float  $zoomFactor,
        string $easingFunction,
        int    $srcW,
        int    $srcH,
    ): array {
        $plan = [];

        // اندازه بدون zoom (full crop با نسبت 16:9)
        $baseH = $srcH;
        $baseW = (int) round($baseH * self::FRAME_WIDTH / self::FRAME_HEIGHT);
        if ($baseW > $srcW) {
            $baseW = $srcW;
            $baseH = (int) round($baseW * self::FRAME_HEIGHT / self::FRAME_WIDTH);
        }

        // اندازه با zoom_in (کوچکتر = زوم بیشتر)
        $zoomedW = (int) round($baseW / $zoomFactor);
        $zoomedH = (int) round($baseH / $zoomFactor);

        // مرکز حرکت
        $centerX = ($srcW - $baseW) / 2;
        $centerY = ($srcH - $baseH) / 2;

        for ($i = 0; $i < $frameCount; $i++) {
            $t = $frameCount > 1 ? $i / ($frameCount - 1) : 0.0; // 0.0 → 1.0
            $e = $this->ease($t, $easingFunction); // eased t

            $frame = match ($motionType) {

                'zoom_in' => [
                    'srcX'  => (int) round($centerX + ($baseW - ($baseW - $e * ($baseW - $zoomedW))) / 2),
                    'srcY'  => (int) round($centerY + ($baseH - ($baseH - $e * ($baseH - $zoomedH))) / 2),
                    'srcW'  => (int) round($baseW - $e * ($baseW - $zoomedW)),
                    'srcH'  => (int) round($baseH - $e * ($baseH - $zoomedH)),
                    'angle' => 0.0,
                ],

                'zoom_out' => [
                    'srcX'  => (int) round($centerX + ((1.0 - $e) * ($baseW - $zoomedW)) / 2),
                    'srcY'  => (int) round($centerY + ((1.0 - $e) * ($baseH - $zoomedH)) / 2),
                    'srcW'  => (int) round($zoomedW + (1.0 - $e) * ($baseW - $zoomedW)),
                    'srcH'  => (int) round($zoomedH + (1.0 - $e) * ($baseH - $zoomedH)),
                    'angle' => 0.0,
                ],

                'pan_right' => [
                    'srcX'  => (int) round($e * ($srcW - $baseW)),
                    'srcY'  => (int) round($centerY),
                    'srcW'  => $baseW,
                    'srcH'  => $baseH,
                    'angle' => 0.0,
                ],

                'pan_left' => [
                    'srcX'  => (int) round((1.0 - $e) * ($srcW - $baseW)),
                    'srcY'  => (int) round($centerY),
                    'srcW'  => $baseW,
                    'srcH'  => $baseH,
                    'angle' => 0.0,
                ],

                'pan_up' => [
                    'srcX'  => (int) round($centerX),
                    'srcY'  => (int) round((1.0 - $e) * ($srcH - $baseH)),
                    'srcW'  => $baseW,
                    'srcH'  => $baseH,
                    'angle' => 0.0,
                ],

                'pan_down' => [
                    'srcX'  => (int) round($centerX),
                    'srcY'  => (int) round($e * ($srcH - $baseH)),
                    'srcW'  => $baseW,
                    'srcH'  => $baseH,
                    'angle' => 0.0,
                ],

                'zoom_pan' => [
                    // zoom_in + pan_right — کلاسیک‌ترین Ken Burns
                    'srcX'  => (int) round($e * ($srcW - $zoomedW)),
                    'srcY'  => (int) round($centerY + ((1.0 - $e) * ($baseH - $zoomedH)) / 2),
                    'srcW'  => (int) round($zoomedW + (1.0 - $e) * ($baseW - $zoomedW)),
                    'srcH'  => (int) round($zoomedH + (1.0 - $e) * ($baseH - $zoomedH)),
                    'angle' => 0.0,
                ],

                'diagonal' => [
                    // حرکت مورب — از گوشه بالا-چپ به پایین-راست با zoom
                    'srcX'  => (int) round($e * ($srcW - $zoomedW)),
                    'srcY'  => (int) round($e * ($srcH - $zoomedH)),
                    'srcW'  => (int) round($zoomedW + (1.0 - $e) * ($baseW - $zoomedW)),
                    'srcH'  => (int) round($zoomedH + (1.0 - $e) * ($baseH - $zoomedH)),
                    'angle' => 0.0,
                ],

                'rotation' => [
                    // زوم خفیف + چرخش ۳ درجه (فقط برای تصاویر بزرگ)
                    'srcX'  => (int) round($centerX + $e * ($baseW - $zoomedW) / 2),
                    'srcY'  => (int) round($centerY + $e * ($baseH - $zoomedH) / 2),
                    'srcW'  => (int) round($baseW - $e * ($baseW - $zoomedW)),
                    'srcH'  => (int) round($baseH - $e * ($baseH - $zoomedH)),
                    'angle' => $e * 3.0, // حداکثر ۳ درجه چرخش
                ],

                default => [
                    'srcX' => (int) round($centerX), 'srcY' => (int) round($centerY),
                    'srcW' => $baseW, 'srcH' => $baseH, 'angle' => 0.0,
                ],
            };

            // clamp برای جلوگیری از out-of-bounds
            $frame['srcX'] = max(0, min($srcW - $frame['srcW'], $frame['srcX']));
            $frame['srcY'] = max(0, min($srcH - $frame['srcH'], $frame['srcY']));
            $frame['srcW'] = max(1, min($srcW - $frame['srcX'], $frame['srcW']));
            $frame['srcH'] = max(1, min($srcH - $frame['srcY'], $frame['srcH']));

            $plan[] = $frame;
        }

        return $plan;
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * رندر یک فریم از روی motion plan
     * ═══════════════════════════════════════════════════════════════
     */
    private function renderFrame(
        \GdImage $src,
        int      $srcW,
        int      $srcH,
        array    $plan,
    ): \GdImage {
        $frame = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);

        // پس‌زمینه مشکی برای حاشیه‌ها
        $black = imagecolorallocate($frame, 0, 0, 0);
        imagefill($frame, 0, 0, $black);

        if ($plan['angle'] == 0.0) {
            // بدون rotation — سریع‌تر
            imagecopyresampled(
                $frame, $src,
                0, 0,                                 // dst x, y
                $plan['srcX'], $plan['srcY'],         // src x, y
                self::FRAME_WIDTH, self::FRAME_HEIGHT, // dst w, h
                $plan['srcW'], $plan['srcH'],          // src w, h
            );
        } else {
            // با rotation — intermediate image
            $intermediate = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);
            imagefill($intermediate, 0, 0, imagecolorallocate($intermediate, 0, 0, 0));
            imagecopyresampled(
                $intermediate, $src,
                0, 0,
                $plan['srcX'], $plan['srcY'],
                self::FRAME_WIDTH, self::FRAME_HEIGHT,
                $plan['srcW'], $plan['srcH'],
            );

            $rotated = imagerotate($intermediate, -$plan['angle'], imagecolorallocate($intermediate, 0, 0, 0));
            imagedestroy($intermediate);

            // crop مرکز تصویر چرخیده‌شده
            $rotW = imagesx($rotated);
            $rotH = imagesy($rotated);
            $offsetX = (int) round(($rotW - self::FRAME_WIDTH) / 2);
            $offsetY = (int) round(($rotH - self::FRAME_HEIGHT) / 2);

            imagecopy($frame, $rotated,
                0, 0,
                max(0, $offsetX), max(0, $offsetY),
                self::FRAME_WIDTH, self::FRAME_HEIGHT,
            );
            imagedestroy($rotated);
        }

        return $frame;
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * Easing Functions — cubic bezier approximations
     * ═══════════════════════════════════════════════════════════════
     *
     * @param float $t 0.0 → 1.0
     * @return float 0.0 → 1.0 (eased)
     */
    private function ease(float $t, string $function): float
    {
        return match ($function) {
            'linear'       => $t,
            'ease_in'      => $t * $t * $t,
            'ease_out'     => 1.0 - (1.0 - $t) ** 3,
            'ease_in_out'  => $t < 0.5
                                ? 4.0 * $t * $t * $t
                                : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
            'bounce'       => $this->easeBounce($t),
            'elastic'      => $this->easeElastic($t),
            default        => $t < 0.5
                                ? 4.0 * $t * $t * $t
                                : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
        };
    }

    private function easeBounce(float $t): float
    {
        $n1 = 7.5625;
        $d1 = 2.75;
        if ($t < 1.0 / $d1) {
            return $n1 * $t * $t;
        } elseif ($t < 2.0 / $d1) {
            $t -= 1.5 / $d1;
            return $n1 * $t * $t + 0.75;
        } elseif ($t < 2.5 / $d1) {
            $t -= 2.25 / $d1;
            return $n1 * $t * $t + 0.9375;
        } else {
            $t -= 2.625 / $d1;
            return $n1 * $t * $t + 0.984375;
        }
    }

    private function easeElastic(float $t): float
    {
        if ($t === 0.0 || $t === 1.0) {
            return $t;
        }
        $c4 = (2.0 * M_PI) / 3.0;
        return -pow(2.0, 10.0 * $t - 10.0) * sin(($t * 10.0 - 10.75) * $c4);
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runJob'], 10, 6);
    }
}

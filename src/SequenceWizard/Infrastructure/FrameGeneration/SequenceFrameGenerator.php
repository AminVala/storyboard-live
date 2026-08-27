<?php
/**
 * SequenceFrameGenerator — ساخت فریم 0 تا N (Reverse Interpolation)
 *
 * ═══════════════════════════════════════════════════════════════════
 * Guarantee: frame[N] = pixel-perfect copy of clean_last_frame
 * ═══════════════════════════════════════════════════════════════════
 *
 * الگوریتم:
 *   source = clean_last_frame (بدون متن)
 *   frame[N] = exact copy (بدون transform)
 *   frame[i] = reverse interpolation از config.frame0 به frame[N]
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardRepository;

final class SequenceFrameGenerator
{
    private const AS_HOOK      = 'shseq_generate_frames';
    private const FRAME_W      = 1920;
    private const FRAME_H      = 1080;
    private const WEBP_QUALITY = 92;
    private const BATCH        = 8;    // فریم در هر GC cycle
    private const OOM_MB       = 64;   // مگابایت آستانه OOM

    /** Motion presets: zoom_out_center, zoom_pan_lr, blur_reveal, pan_left, pan_right, pan_top */
    private const PRESETS = [
        'zoom_out_center' => ['zoom' => 1.5,  'pan_x' => 0.0,   'pan_y' => 0.0,   'blur' => 0],
        'zoom_pan_lr'     => ['zoom' => 1.4,  'pan_x' => -0.25, 'pan_y' => 0.0,   'blur' => 0],
        'zoom_pan_rl'     => ['zoom' => 1.4,  'pan_x' =>  0.25, 'pan_y' => 0.0,   'blur' => 0],
        'zoom_pan_tb'     => ['zoom' => 1.4,  'pan_x' => 0.0,   'pan_y' => -0.2,  'blur' => 0],
        'blur_reveal'     => ['zoom' => 1.0,  'pan_x' => 0.0,   'pan_y' => 0.0,   'blur' => 12],
        'pan_from_left'   => ['zoom' => 1.0,  'pan_x' => -0.35, 'pan_y' => 0.0,   'blur' => 0],
        'pan_from_right'  => ['zoom' => 1.0,  'pan_x' =>  0.35, 'pan_y' => 0.0,   'blur' => 0],
        'pan_from_top'    => ['zoom' => 1.0,  'pan_x' => 0.0,   'pan_y' => -0.25, 'blur' => 0],
    ];

    public function __construct(
        private readonly WizardRepository $repo,
    ) {}

    /* ─── Schedule ───────────────────────────────────────────────── */

    public function schedule(
        int    $postId,
        int    $sourceAttachmentId,
        array  $config,
        int    $resumeFrom = 0,
    ): void {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                self::AS_HOOK,
                [
                    'post_id'     => $postId,
                    'source'      => $sourceAttachmentId,
                    'config'      => $config,
                    'resume_from' => $resumeFrom,
                ],
                'shseq',
            );
        } else {
            $this->run($postId, $sourceAttachmentId, $config, $resumeFrom);
        }
    }

    /** Action Scheduler callback */
    public function run(int $postId, int $source, array $config, int $resumeFrom = 0): void
    {
        try {
            $existing = [];
            if ($resumeFrom > 0) {
                $agg = $this->repo->find($postId);
                foreach ($agg->getFrameIds() as $idx => $id) {
                    $existing[$idx] = $id;
                }
            }

            $allIds = $this->generate(
                sourceAttachmentId: $source,
                config:             $config,
                resumeFrom:         $resumeFrom,
                existingIds:        $existing,
                onProgress:         function (int $pct, int $lastIdx, array $ids) use ($postId): void {
                    $agg = $this->repo->find($postId);
                    $agg->updateFrameProgress($pct, $lastIdx, $ids);
                    $this->repo->save($agg);
                },
            );

            $agg = $this->repo->find($postId);
            $agg->completeFrameGeneration($allIds);
            $this->repo->save($agg);

            update_post_meta($postId, '_shseq_frames', $allIds);
            update_post_meta($postId, '_shseq_end_frame_id', end($allIds));

        } catch (\Throwable $e) {
            $agg = $this->repo->find($postId);
            $agg->fail('Frame generation error: ' . $e->getMessage());
            $this->repo->save($agg);
            error_log('[shseq] Frame gen error: ' . $e->getMessage());
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       الگوریتم اصلی
       ═══════════════════════════════════════════════════════════════ */

    /**
     * @param callable(int,int,int[]): void $onProgress
     * @param array<int,int> $existingIds  indexed by frame index (for resume)
     * @return list<int> attachment IDs از frame 0 تا frame N
     */
    public function generate(
        int      $sourceAttachmentId,
        array    $config,
        int      $resumeFrom,
        array    $existingIds,
        callable $onProgress,
    ): array {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension not loaded');
        }

        /* ── parse config ── */
        $preset     = $config['preset']      ?? 'zoom_out_center';
        $presetData = self::PRESETS[$preset]  ?? self::PRESETS['zoom_out_center'];
        $zoomFactor = (float)($config['zoom_factor'] ?? $presetData['zoom']);
        $panXRel    = (float)($config['pan_x_rel']   ?? $presetData['pan_x']);
        $panYRel    = (float)($config['pan_y_rel']   ?? $presetData['pan_y']);
        $blurPx     = (int)  ($config['blur_px']     ?? $presetData['blur']);
        $frameCount = (int)  ($config['frame_count'] ?? 36);
        $easing     = (string)($config['easing']     ?? 'ease_in_out');

        $frameCount = max(8, min(120, $frameCount));

        /* ── load source ── */
        $srcPath = get_attached_file($sourceAttachmentId);
        if (! $srcPath || ! file_exists($srcPath)) {
            throw new \RuntimeException("Source not found: {$sourceAttachmentId}");
        }

        $src = $this->loadImage($srcPath, mime_content_type($srcPath));
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // upscale اگر تصویر کوچک است
        if ($srcW < self::FRAME_W || $srcH < self::FRAME_H) {
            $src  = $this->upscale($src, $srcW, $srcH);
            $srcW = imagesx($src);
            $srcH = imagesy($src);
        }

        // pre-compute blur (یک بار)
        $blurSrc = null;
        if ($blurPx > 0) {
            $blurSrc = $this->preBlur($src, $srcW, $srcH, $blurPx);
        }

        /* ── upload dir ── */
        $uploadDir = wp_upload_dir();
        $subDir    = $uploadDir['path'] . '/shseq-seq-' . $sourceAttachmentId . '-' . time();
        wp_mkdir_p($subDir);

        $allIds  = $existingIds;
        $batch   = [];

        /* ── main loop ── */
        for ($i = $resumeFrom; $i <= $frameCount; $i++) {

            if ($this->memLow()) {
                $onProgress((int) round($i / $frameCount * 100), $i, $allIds);
                imagedestroy($src);
                if ($blurSrc) imagedestroy($blurSrc);
                throw new \RuntimeException(
                    "OOM at frame {$i}. Checkpoint saved."
                );
            }

            $frame = $this->renderFrame($src, $blurSrc, $srcW, $srcH, $i, $frameCount, [
                'zoom' => $zoomFactor, 'pan_x' => $panXRel, 'pan_y' => $panYRel,
                'blur' => $blurPx, 'easing' => $easing,
            ]);

            /* save */
            $fName = sprintf('frame-%04d.webp', $i);
            $fPath = "{$subDir}/{$fName}";
            if (! imagewebp($frame, $fPath, self::WEBP_QUALITY)) {
                $fName = sprintf('frame-%04d.png', $i);
                $fPath = "{$subDir}/{$fName}";
                imagepng($frame, $fPath, 6);
            }
            imagedestroy($frame);

            $attId = $this->insertAttachment($fPath, $uploadDir, $i);
            if ($attId) {
                $allIds[$i] = $attId;
                $batch[]    = $attId;
            }

            if (count($batch) >= self::BATCH || $i === $frameCount) {
                $pct = (int) round($i / $frameCount * 100);
                $onProgress($pct, $i, $allIds);
                $batch = [];
                gc_collect_cycles();
            }
        }

        imagedestroy($src);
        if ($blurSrc) imagedestroy($blurSrc);

        ksort($allIds);
        return array_values($allIds);
    }

    /* ─── رندر یک فریم ───────────────────────────────────────────── */

    private function renderFrame(
        \GdImage $src,
        ?\GdImage $blurSrc,
        int $srcW,
        int $srcH,
        int $idx,
        int $total,
        array $params,
    ): \GdImage {

        /* frame[N] = pixel-perfect exact copy */
        if ($idx === $total) {
            $f = imagecreatetruecolor(self::FRAME_W, self::FRAME_H);
            imagecopyresampled($f, $src, 0, 0, 0, 0, self::FRAME_W, self::FRAME_H, $srcW, $srcH);
            return $f;
        }

        $t = $total > 0 ? $idx / $total : 0.0;
        $e = $this->ease($t, $params['easing']);

        $zoom  = $this->lerp($params['zoom'], 1.0, $e);
        $zoom  = max(1.0, $zoom);
        $cropW = (int) round($srcW / $zoom);
        $cropH = (int) round($srcH / $zoom);
        $cropW = max(1, min($srcW, $cropW));
        $cropH = max(1, min($srcH, $cropH));

        $panX  = (int) round($this->lerp($params['pan_x'], 0.0, $e) * $srcW);
        $panY  = (int) round($this->lerp($params['pan_y'], 0.0, $e) * $srcH);
        $cropX = max(0, min($srcW - $cropW, (int) round(($srcW - $cropW) / 2) + $panX));
        $cropY = max(0, min($srcH - $cropH, (int) round(($srcH - $cropH) / 2) + $panY));

        $f = imagecreatetruecolor(self::FRAME_W, self::FRAME_H);
        imagecopyresampled($f, $src, 0, 0, $cropX, $cropY, self::FRAME_W, self::FRAME_H, $cropW, $cropH);

        /* blur blend */
        if ($blurSrc !== null && $params['blur'] > 0) {
            $blurAlpha = $this->lerp(1.0, 0.0, $e);
            if ($blurAlpha > 0.02) {
                $bf = imagecreatetruecolor(self::FRAME_W, self::FRAME_H);
                imagecopyresampled($bf, $blurSrc, 0, 0, $cropX, $cropY, self::FRAME_W, self::FRAME_H, $cropW, $cropH);
                imagecopymergegray($f, $bf, 0, 0, 0, 0, self::FRAME_W, self::FRAME_H, (int)(100 * $blurAlpha));
                imagedestroy($bf);
            }
        }

        return $f;
    }

    /* ─── کمکی‌ها ────────────────────────────────────────────────── */

    private function loadImage(string $path, string $mime): \GdImage
    {
        $img = match ($mime) {
            'image/png'  => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => throw new \RuntimeException("Unsupported: {$mime}"),
        };
        if ($img === false) throw new \RuntimeException("GD load failed: {$path}");
        if (! imageistruecolor($img)) {
            $tc = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagecopy($tc, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagedestroy($img);
            return $tc;
        }
        return $img;
    }

    private function upscale(\GdImage $src, int $w, int $h): \GdImage
    {
        $scale = max(self::FRAME_W / $w, self::FRAME_H / $h, 1.1);
        $nW    = (int) round($w * $scale) + 200;
        $nH    = (int) round($h * $scale) + 200;
        $new   = imagecreatetruecolor($nW, $nH);
        imagecopyresampled($new, $src, 0, 0, 0, 0, $nW, $nH, $w, $h);
        imagedestroy($src);
        return $new;
    }

    private function preBlur(\GdImage $src, int $w, int $h, int $px): \GdImage
    {
        $b = imagecreatetruecolor($w, $h);
        imagecopy($b, $src, 0, 0, 0, 0, $w, $h);
        $passes = max(1, min(20, $px));
        for ($p = 0; $p < $passes; $p++) {
            imagefilter($b, IMG_FILTER_GAUSSIAN_BLUR);
        }
        return $b;
    }

    private function insertAttachment(string $filePath, array $uploadDir, int $idx): ?int
    {
        if (! file_exists($filePath)) return null;
        $isWebp  = str_ends_with($filePath, '.webp');
        $fileUrl = str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $filePath);
        $id = wp_insert_attachment([
            'guid'           => $fileUrl,
            'post_mime_type' => $isWebp ? 'image/webp' : 'image/png',
            'post_title'     => sprintf('StoryBoard Frame %04d', $idx),
            'post_status'    => 'inherit',
        ], $filePath);
        if (is_wp_error($id)) return null;
        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $filePath));
        return $id;
    }

    private function memLow(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') return false;
        $bytes = $this->parseMemLimit($limit);
        return ($bytes - memory_get_usage(true)) < self::OOM_MB * 1024 * 1024;
    }

    private function parseMemLimit(string $v): int
    {
        $u = strtolower(substr(trim($v), -1));
        $n = (int) $v;
        return match ($u) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    private function ease(float $t, string $fn): float
    {
        $t = max(0.0, min(1.0, $t));
        return match ($fn) {
            'linear'      => $t,
            'ease_in'     => $t ** 3,
            'ease_out'    => 1.0 - (1.0 - $t) ** 3,
            'ease_in_out' => $t < 0.5 ? 4 * $t ** 3 : 1.0 - (-2 * $t + 2) ** 3 / 2,
            default       => $t < 0.5 ? 4 * $t ** 3 : 1.0 - (-2 * $t + 2) ** 3 / 2,
        };
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'run'], 10, 4);
    }
}

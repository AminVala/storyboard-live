<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Inpainting;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * GDInpainter — پاک کردن متن از تصویر با استفاده از GD
 *
 * الگوریتم:
 * 1. برای هر text detection box → یک region از تصویر می‌گیریم
 * 2. Gaussian blur شدید روی آن region اعمال می‌کنیم
 * 3. Region را با blur شده جایگزین می‌کنیم
 * 4. تصویر نهایی را به WP media library اضافه می‌کنیم
 *
 * 100% offline — بدون API خارجی
 */
final class GDInpainter
{
    private const AS_HOOK    = 'shseq_gd_inpaint';
    private const BLUR_PASSES = 5;  // تعداد blur pass — بیشتر = نرم‌تر

    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    /**
     * @param list<array{text:string, x_rel:float, y_rel:float, width_rel:float, height_rel:float}> $detections
     */
    public function scheduleInpainting(
        int    $sequencePostId,
        int    $attachmentId,
        array  $detections,
    ): void {
        if (! function_exists('as_schedule_single_action')) {
            $this->runJob($sequencePostId, $attachmentId, $detections);
            return;
        }

        as_schedule_single_action(
            time(),
            self::AS_HOOK,
            [
                'sequence_post_id' => $sequencePostId,
                'attachment_id'    => $attachmentId,
                'detections'       => $detections,
            ],
            'shseq',
        );
    }

    public function runJob(int $sequencePostId, int $attachmentId, array $detections): void
    {
        try {
            $cleanAttachmentId = $this->inpaint($attachmentId, $detections);

            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->applyInpaintingResult($cleanAttachmentId);
            $this->repo->save($state);

        } catch (\Throwable $e) {
            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->fail('GD inpainting failed: ' . $e->getMessage());
            $this->repo->save($state);
        }
    }

    /**
     * اجرای inpainting و بازگشت attachment_id تصویر پاک‌شده
     *
     * @param list<array{x_rel:float, y_rel:float, width_rel:float, height_rel:float}> $detections
     */
    public function inpaint(int $attachmentId, array $detections): int
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is not loaded');
        }

        $imagePath = get_attached_file($attachmentId);
        if (! $imagePath || ! file_exists($imagePath)) {
            throw new \RuntimeException("Source image not found for attachment {$attachmentId}");
        }

        $mimeType = mime_content_type($imagePath);
        $src = match ($mimeType) {
            'image/png'  => imagecreatefrompng($imagePath),
            'image/jpeg' => imagecreatefromjpeg($imagePath),
            'image/webp' => imagecreatefromwebp($imagePath),
            default      => throw new \RuntimeException("Unsupported image type: {$mimeType}"),
        };

        if ($src === false) {
            throw new \RuntimeException('GD could not load the image');
        }

        $width  = imagesx($src);
        $height = imagesy($src);

        foreach ($detections as $d) {
            // مختصات پیکسلی با padding ۵٪ برای capture کامل
            $padding = 0.05;
            $x1 = max(0, (int) round(($d['x_rel'] - $padding) * $width));
            $y1 = max(0, (int) round(($d['y_rel'] - $padding) * $height));
            $x2 = min($width,  (int) round(($d['x_rel'] + $d['width_rel']  + $padding) * $width));
            $y2 = min($height, (int) round(($d['y_rel'] + $d['height_rel'] + $padding) * $height));

            $regionW = $x2 - $x1;
            $regionH = $y2 - $y1;

            if ($regionW <= 0 || $regionH <= 0) {
                continue;
            }

            // یک کپی از region می‌گیریم، blur می‌کنیم، برمی‌گردانیم
            $region = imagecreatetruecolor($regionW, $regionH);
            imagecopy($region, $src, 0, 0, $x1, $y1, $regionW, $regionH);

            for ($i = 0; $i < self::BLUR_PASSES; $i++) {
                imagefilter($region, IMG_FILTER_GAUSSIAN_BLUR);
            }

            imagecopy($src, $region, $x1, $y1, 0, 0, $regionW, $regionH);
            imagedestroy($region);
        }

        // ذخیره در فایل موقت
        $uploadDir = wp_upload_dir();
        $subDir    = $uploadDir['path'];
        $fileName  = 'shseq-clean-' . $attachmentId . '-' . time() . '.webp';
        $filePath  = $subDir . '/' . $fileName;

        // WebP با کیفیت ۹۵
        if (! imagewebp($src, $filePath, 95)) {
            // fallback به PNG
            $fileName = 'shseq-clean-' . $attachmentId . '-' . time() . '.png';
            $filePath = $subDir . '/' . $fileName;
            imagepng($src, $filePath, 6);
        }

        imagedestroy($src);

        // اضافه کردن به media library
        $attachmentData = [
            'guid'           => $uploadDir['url'] . '/' . $fileName,
            'post_mime_type' => file_exists($filePath) && str_ends_with($filePath, '.webp') ? 'image/webp' : 'image/png',
            'post_title'     => 'StoryBoard Clean Background',
            'post_status'    => 'inherit',
        ];

        $newAttachmentId = wp_insert_attachment($attachmentData, $filePath);

        if (is_wp_error($newAttachmentId)) {
            throw new \RuntimeException('Failed to insert clean background to media library');
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($newAttachmentId, $filePath);
        wp_update_attachment_metadata($newAttachmentId, $metadata);

        return $newAttachmentId;
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runJob'], 10, 3);
    }
}

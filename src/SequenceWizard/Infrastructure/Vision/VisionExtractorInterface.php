<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision;

/**
 * قرارداد استخراج متن از تصویر
 *
 * Implementations:
 * - OpenAIVisionExtractor (پیش‌فرض — نیاز به API Key)
 * - FallbackManualExtractor (وقتی API Key ندارد — کاربر خودش متن را وارد می‌کند)
 */
interface VisionExtractorInterface
{
    /**
     * زمان‌بندی extraction (async via Action Scheduler)
     */
    public function scheduleExtraction(int $sequencePostId, int $attachmentId): void;

    /**
     * اجرای مستقیم extraction (داخل job)
     *
     * @return list<array{text:string, x_rel:float, y_rel:float, width_rel:float, height_rel:float}>
     */
    public function extractTexts(int $attachmentId): array;
}

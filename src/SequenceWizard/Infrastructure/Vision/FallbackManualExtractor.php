<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * Fallback: وقتی OpenAI API Key ندارد
 * کاربر در overlay editor خودش متن‌ها را اضافه می‌کند — extraction خالی برمی‌گردد
 */
final class FallbackManualExtractor implements VisionExtractorInterface
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function scheduleExtraction(int $sequencePostId, int $attachmentId): void
    {
        // بلافاصله: نتیجه خالی — کاربر خودش overlay اضافه می‌کند
        $state = $this->repo->findBySequenceId($sequencePostId);
        $state->applyTextExtractionResult([]);  // step می‌رود به GM_INPAINT
        $this->repo->save($state);

        // inpainting هم با GD (بدون mask) یک کپی ساده می‌کند
        (new GDInpainter($this->repo))->scheduleInpainting($sequencePostId, $attachmentId, []);
    }

    public function extractTexts(int $attachmentId): array
    {
        return []; // fallback: خالی
    }
}

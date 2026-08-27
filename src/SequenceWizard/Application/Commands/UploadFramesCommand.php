<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: آپلود فریم‌های آماده (حالت FRAME_UPLOAD) */
final class UploadFramesCommand
{
    /**
     * @param list<int> $attachmentIds attachment IDs به ترتیب اجرا
     */
    public function __construct(
        public readonly int   $sequencePostId,
        public readonly array $attachmentIds,
    ) {}
}

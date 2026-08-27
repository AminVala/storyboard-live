<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: آپلود تصویر Golden Master PNG */
final class UploadGoldenMasterCommand
{
    public function __construct(
        public readonly int    $sequencePostId,
        public readonly int    $attachmentId,
    ) {}
}

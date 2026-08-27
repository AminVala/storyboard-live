<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: ذخیره content steps و اختیاراً تأیید برای رفتن به preview */
final class SaveContentStepsCommand
{
    /**
     * @param list<array{frame_index:int, html:string, css_class:string}> $steps
     */
    public function __construct(
        public readonly int   $sequencePostId,
        public readonly array $steps,
        public readonly bool  $confirm = false,
    ) {}
}

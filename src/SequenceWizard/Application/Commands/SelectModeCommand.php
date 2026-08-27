<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;

/** Command: انتخاب حالت ساخت سکانس */
final class SelectModeCommand
{
    public function __construct(
        public readonly int        $sequencePostId,
        public readonly WizardMode $mode,
    ) {}
}

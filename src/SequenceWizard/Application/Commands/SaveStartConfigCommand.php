<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\StartFrameConfig;

/** Command: ذخیره تنظیمات انیمیشن (MotionPreset + frameCount + easing) */
final class SaveStartConfigCommand
{
    public function __construct(
        public readonly int              $sequencePostId,
        public readonly StartFrameConfig $config,
        public readonly bool             $confirm = false, // اگر true باشد → FRAME_GENERATE
    ) {}
}

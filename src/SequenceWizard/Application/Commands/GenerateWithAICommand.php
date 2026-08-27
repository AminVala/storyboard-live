<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: ساخت تصویر اولیه با AI از روی prompt */
final class GenerateWithAICommand
{
    public function __construct(
        public readonly int    $sequencePostId,
        public readonly string $prompt,
    ) {}
}

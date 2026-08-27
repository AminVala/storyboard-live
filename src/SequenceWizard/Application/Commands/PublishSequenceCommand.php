<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: publish نهایی سکانس */
final class PublishSequenceCommand
{
    public function __construct(
        public readonly int $sequencePostId,
    ) {}
}

<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SelectModeCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class SelectModeHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function handle(SelectModeCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->selectMode($command->mode);
        $this->repo->save($state);
    }
}

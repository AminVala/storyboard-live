<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveOverlayLayoutCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class SaveOverlayLayoutHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function handle(SaveOverlayLayoutCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->saveOverlayLayout($command->items);

        if ($command->confirm) {
            $state->confirmOverlay();
        }

        $this->repo->save($state);
    }
}

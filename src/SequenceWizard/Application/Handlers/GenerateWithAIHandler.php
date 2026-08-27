<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\GenerateWithAICommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;
use ShahreHonar\SequenceEngine\AI\ProviderInterface as AIProvider;

final class GenerateWithAIHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
        private readonly AIProvider            $ai,
    ) {}

    public function handle(GenerateWithAICommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->setAiPrompt($command->prompt);
        $this->repo->save($state);

        // async job برای AI generation
        $this->ai->scheduleImageGeneration(
            sequencePostId: $command->sequencePostId,
            prompt:         $command->prompt,
        );
    }
}

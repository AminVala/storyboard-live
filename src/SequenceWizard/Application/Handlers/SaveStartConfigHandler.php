<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveStartConfigCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\FrameGeneration\ReverseFrameGenerator;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * Handler: ذخیره StartFrameConfig + اختیاراً شروع frame generation
 */
final class SaveStartConfigHandler
{
    public function __construct(
        private readonly WizardStateRepository  $repo,
        private readonly ReverseFrameGenerator  $generator,
    ) {}

    public function handle(SaveStartConfigCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->saveStartConfig($command->config);

        if ($command->confirm) {
            $state->confirmStartConfig();
            $this->repo->save($state);

            // منبع = clean background (پس از inpainting)
            $sourceId = $state->getSourceAttachmentId();
            if ($sourceId === null) {
                $state->fail('No source image found for frame generation');
                $this->repo->save($state);
                return;
            }

            $jobId = 'shseq-frames-' . $command->sequencePostId . '-' . time();
            $state->startFrameGenerationJob($jobId);
            $this->repo->save($state);

            $this->generator->scheduleGeneration(
                sequencePostId:      $command->sequencePostId,
                sourceAttachmentId:  $sourceId,
                config:              $command->config,
                startFromCheckpoint: 0,
            );
        } else {
            $this->repo->save($state);
        }
    }
}

<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadGoldenMasterCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision\VisionExtractorInterface;

/**
 * Handler: آپلود Golden Master + آغاز async استخراج متن
 */
final class UploadGoldenMasterHandler
{
    public function __construct(
        private readonly WizardStateRepository   $repo,
        private readonly VisionExtractorInterface $vision,
    ) {}

    public function handle(UploadGoldenMasterCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->setGoldenMasterAttachment($command->attachmentId);
        $this->repo->save($state);

        // آغاز async extraction (Action Scheduler job)
        $this->vision->scheduleExtraction(
            sequencePostId: $command->sequencePostId,
            attachmentId:   $command->attachmentId,
        );
    }
}

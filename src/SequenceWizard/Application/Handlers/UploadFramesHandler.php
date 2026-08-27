<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\UploadFramesCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class UploadFramesHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function handle(UploadFramesCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->setFrameAttachments($command->attachmentIds);
        $this->repo->save($state);

        // frame_attachment_ids را به post_meta اصلی هم منتقل کن
        update_post_meta(
            $command->sequencePostId,
            '_shseq_frames',
            $command->attachmentIds,
        );
    }
}

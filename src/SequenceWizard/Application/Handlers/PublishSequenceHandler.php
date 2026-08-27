<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\PublishSequenceCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class PublishSequenceHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function handle(PublishSequenceCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);
        $state->publish();
        $this->repo->save($state);

        // post را publish می‌کنیم
        wp_update_post([
            'ID'          => $command->sequencePostId,
            'post_status' => 'publish',
        ]);

        do_action('shseq_sequence_published', $command->sequencePostId);
    }
}

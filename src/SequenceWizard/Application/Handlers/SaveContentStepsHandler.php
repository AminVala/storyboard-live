<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Handlers;

use ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands\SaveContentStepsCommand;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

final class SaveContentStepsHandler
{
    public function __construct(
        private readonly WizardStateRepository $repo,
    ) {}

    public function handle(SaveContentStepsCommand $command): void
    {
        $state = $this->repo->findBySequenceId($command->sequencePostId);

        // sanitize HTML — فقط تگ‌های مجاز
        $allowedTags = '<strong><em><span><br><a><h1><h2><h3><h4><p>';
        $sanitized = array_map(function (array $step) use ($allowedTags): array {
            return [
                'frame_index' => (int) ($step['frame_index'] ?? 0),
                'html'        => wp_kses_post($step['html'] ?? ''),
                'css_class'   => sanitize_html_class($step['css_class'] ?? ''),
            ];
        }, $command->steps);

        $state->saveContentSteps($sanitized);

        if ($command->confirm) {
            $state->confirmContent();
        }

        $this->repo->save($state);

        // sync به _shseq_content_steps برای frontend
        update_post_meta($command->sequencePostId, '_shseq_content_steps', wp_json_encode($sanitized));
    }
}

<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardAggregate;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardStep;

/**
 * WizardRepository — ذخیره/بازیابی WizardAggregate از post_meta
 */
final class WizardRepository
{
    private const META_KEY = '_shseq_wizard_v3';

    public function find(int $sequencePostId): WizardAggregate
    {
        if (get_post_status($sequencePostId) === false) {
            throw new \RuntimeException("Post {$sequencePostId} does not exist");
        }

        $raw = get_post_meta($sequencePostId, self::META_KEY, true);

        if (! is_array($raw) || empty($raw)) {
            return new WizardAggregate(
                sequencePostId: $sequencePostId,
                mode:           WizardMode::LAST_FRAME,
                step:           WizardStep::MODE_SELECT,
            );
        }

        return WizardAggregate::fromArray($raw);
    }

    public function save(WizardAggregate $agg): void
    {
        update_post_meta($agg->getSequencePostId(), self::META_KEY, $agg->toArray());
    }

    public function delete(int $sequencePostId): void
    {
        delete_post_meta($sequencePostId, self::META_KEY);
    }
}

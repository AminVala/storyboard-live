<?php
/**
 * WizardStateRepository — ذخیره و بازیابی WizardState از post_meta وردپرس
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence;

use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardMode;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardState;
use ShahreHonar\SequenceEngine\SequenceWizard\Domain\WizardStep;

/**
 * کلیه تعاملات با وردپرس در این کلاس محدود است.
 * Domain objects از وردپرس خبر ندارند.
 */
final class WizardStateRepository
{
    private const META_KEY = '_shseq_wizard_state';

    /**
     * بازیابی وضعیت برای یک سکانس — اگر وجود نداشت، وضعیت اولیه برمی‌گردد.
     *
     * @throws \RuntimeException اگر sequence_id وجود نداشته باشد
     */
    public function findBySequenceId(int $sequencePostId): WizardState
    {
        if (get_post_status($sequencePostId) === false) {
            throw new \RuntimeException("Sequence post {$sequencePostId} does not exist");
        }

        $raw = get_post_meta($sequencePostId, self::META_KEY, true);

        if (! is_array($raw) || empty($raw)) {
            // وضعیت اولیه
            return new WizardState(
                sequencePostId: $sequencePostId,
                mode:           WizardMode::GOLDEN_MASTER,
                step:           WizardStep::MODE_SELECT,
            );
        }

        return WizardState::fromArray($raw);
    }

    /**
     * ذخیره وضعیت
     */
    public function save(WizardState $state): void
    {
        update_post_meta(
            $state->getSequencePostId(),
            self::META_KEY,
            $state->toArray(),
        );
    }

    /**
     * حذف کامل wizard state (مثلاً وقتی سکانس حذف می‌شود)
     */
    public function delete(int $sequencePostId): void
    {
        delete_post_meta($sequencePostId, self::META_KEY);
    }
}

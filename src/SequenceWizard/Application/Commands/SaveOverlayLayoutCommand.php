<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application\Commands;

/** Command: ذخیره موقعیت overlay itemها */
final class SaveOverlayLayoutCommand
{
    /**
     * @param list<array{id:string, html:string, position:array, fontFamily:string, fontSize:string, color:string}> $items
     */
    public function __construct(
        public readonly int   $sequencePostId,
        public readonly array $items,
        public readonly bool  $confirm = false, // اگر true باشد به FRAME_GENERATE می‌رود
    ) {}
}

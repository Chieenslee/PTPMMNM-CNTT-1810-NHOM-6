<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class UnassignedEntry
{
    public const TYPE_SLOT      = 'SLOT';
    public const TYPE_VOLUNTEER = 'VOLUNTEER';

    public function __construct(
        public readonly string $itemType,
        public readonly ?int $shiftId,
        public readonly ?int $requirementId,
        public readonly ?int $volunteerId,
        public readonly int $missingCount,
        public readonly string $reasonCode,
        public readonly string $explanationText
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_type' => $this->itemType,
            'shift_id' => $this->shiftId,
            'requirement_id' => $this->requirementId,
            'volunteer_id' => $this->volunteerId,
            'missing_count' => $this->missingCount,
            'reason_code' => $this->reasonCode,
            'reason_message_vi' => ReasonCode::messageVi($this->reasonCode),
            'explanation_text' => $this->explanationText,
        ];
    }
}

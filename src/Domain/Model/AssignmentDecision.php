<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class AssignmentDecision
{
    /**
     * @param list<string> $violationCodes
     */
    public function __construct(
        public readonly int $shiftId,
        public readonly ?int $requirementId,
        public readonly int $volunteerId,
        public readonly float $score = 0.0,
        public readonly bool $isLockedFixed = false,
        public readonly array $violationCodes = []
    ) {
    }

    public function hasViolation(): bool
    {
        return $this->violationCodes !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'requirement_id' => $this->requirementId,
            'volunteer_id' => $this->volunteerId,
            'score' => round($this->score, 4),
            'is_locked_fixed' => $this->isLockedFixed,
            'has_violation' => $this->hasViolation(),
            'violation_codes' => $this->violationCodes,
        ];
    }
}

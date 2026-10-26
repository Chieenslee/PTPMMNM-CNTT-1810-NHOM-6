<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class SkillRequirementSpec
{
    public function __construct(
        public readonly int $id,
        public readonly int $shiftId,
        public readonly int $skillId,
        public readonly string $skillCode,
        public readonly string $skillName,
        public readonly int $minLevel,
        public readonly int $requiredCount,
        public readonly bool $requireVerified = false
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shiftId,
            'skill_id' => $this->skillId,
            'skill_code' => $this->skillCode,
            'skill_name' => $this->skillName,
            'min_level' => $this->minLevel,
            'required_count' => $this->requiredCount,
            'require_verified' => $this->requireVerified,
        ];
    }
}

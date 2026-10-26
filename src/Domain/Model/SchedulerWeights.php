<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class SchedulerWeights
{
    public const MODE_MAX_COVERAGE = 'MAX_COVERAGE';
    public const MODE_FAIRNESS     = 'FAIRNESS';
    public const MODE_PREFERENCE   = 'PREFERENCE';
    public const MODE_REPUTATION   = 'REPUTATION';

    public function __construct(
        public readonly string $priorityMode = self::MODE_MAX_COVERAGE,
        public readonly float $wPriority = 5.0,
        public readonly float $wPreference = 2.0,
        public readonly float $wSkillWaste = 3.0,
        public readonly float $wLoad = 1.0,
        public readonly float $wAbsentRisk = 0.0,
        public readonly float $wReputation = 0.0,
        public readonly int $randomSeed = 202606
    ) {
    }

    public static function fromMode(string $mode, int $seed = 202606): self
    {
        return match ($mode) {
            self::MODE_FAIRNESS => new self(
                self::MODE_FAIRNESS,
                wPriority: 3.0,
                wPreference: 2.0,
                wSkillWaste: 2.5,
                wLoad: 6.0,
                wAbsentRisk: 0.0,
                wReputation: 0.0,
                randomSeed: $seed
            ),
            self::MODE_PREFERENCE => new self(
                self::MODE_PREFERENCE,
                wPriority: 2.5,
                wPreference: 6.0,
                wSkillWaste: 2.0,
                wLoad: 2.0,
                wAbsentRisk: 0.0,
                wReputation: 0.0,
                randomSeed: $seed
            ),
            self::MODE_REPUTATION => new self(
                self::MODE_REPUTATION,
                wPriority: 4.0,
                wPreference: 2.0,
                wSkillWaste: 2.5,
                wLoad: 2.0,
                wAbsentRisk: 0.0,
                wReputation: 3.5,
                randomSeed: $seed
            ),
            default => new self(
                self::MODE_MAX_COVERAGE,
                wPriority: 5.0,
                wPreference: 2.0,
                wSkillWaste: 3.0,
                wLoad: 1.0,
                wAbsentRisk: 0.0,
                wReputation: 0.0,
                randomSeed: $seed
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'priority_mode' => $this->priorityMode,
            'weights' => [
                'w_priority' => $this->wPriority,
                'w_preference' => $this->wPreference,
                'w_skill_waste' => $this->wSkillWaste,
                'w_load' => $this->wLoad,
                'w_absent_risk' => $this->wAbsentRisk,
                'w_reputation' => $this->wReputation,
            ],
            'random_seed' => $this->randomSeed,
            'algorithm_version' => 'xanhhub-v1.1-2phase-3step',
        ];
    }
}

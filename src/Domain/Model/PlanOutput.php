<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class PlanOutput
{
    /**
     * @param list<AssignmentDecision> $decisions
     * @param list<UnassignedEntry> $unassigned
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $algorithmCode, // 'B0', 'B1', 'X'
        public readonly string $priorityMode,
        public readonly string $snapshotHash,
        public readonly array $decisions,
        public readonly array $unassigned,
        public readonly array $metrics,
        public readonly array $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'algorithm_code' => $this->algorithmCode,
            'priority_mode' => $this->priorityMode,
            'snapshot_hash' => $this->snapshotHash,
            'config' => $this->config,
            'metrics' => $this->metrics,
            'decisions' => array_map(static fn(AssignmentDecision $d) => $d->toArray(), $this->decisions),
            'unassigned' => array_map(static fn(UnassignedEntry $u) => $u->toArray(), $this->unassigned),
        ];
    }
}

<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class ScheduleDataset
{
    /** @var array<int, ShiftSpec> */
    public readonly array $shiftsById;

    /** @var array<int, VolunteerSpec> */
    public readonly array $volunteersById;

    /**
     * @param list<ShiftSpec> $shifts
     * @param list<VolunteerSpec> $volunteers
     * @param list<RegistrationSpec> $registrations
     * @param list<AssignmentDecision> $fixedAssignments Phân công đã CONFIRMED hoặc thuộc ca LOCKED
     */
    public function __construct(
        public readonly int $activityId,
        public readonly string $datasetCode,
        public readonly array $shifts,
        public readonly array $volunteers,
        public readonly array $registrations,
        public readonly array $fixedAssignments = []
    ) {
        $sMap = [];
        foreach ($shifts as $s) {
            $sMap[$s->id] = $s;
        }
        $this->shiftsById = $sMap;

        $vMap = [];
        foreach ($volunteers as $v) {
            $vMap[$v->id] = $v;
        }
        $this->volunteersById = $vMap;
    }

    /**
     * Tính mã băm SHA-256 chuẩn hóa của toàn bộ đầu vào để đảm bảo tính toàn vẹn (SNAPSHOT_STALE).
     */
    public function computeSnapshotHash(): string
    {
        return hash('sha256', json_encode($this->toCanonicalArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        $shifts = array_map(static fn(ShiftSpec $s) => $s->toArray(), $this->shifts);
        usort($shifts, static fn(array $a, array $b) => $a['id'] <=> $b['id']);

        $volunteers = array_map(static fn(VolunteerSpec $v) => $v->toArray(), $this->volunteers);
        usort($volunteers, static fn(array $a, array $b) => $a['id'] <=> $b['id']);

        $regs = array_map(static fn(RegistrationSpec $r) => $r->toArray(), $this->registrations);
        usort($regs, static fn(array $a, array $b) => $a['id'] <=> $b['id']);

        $fixed = array_map(static fn(AssignmentDecision $d) => $d->toArray(), $this->fixedAssignments);

        return [
            'activity_id' => $this->activityId,
            'dataset_code' => $this->datasetCode,
            'shifts' => $shifts,
            'volunteers' => $volunteers,
            'registrations' => $regs,
            'fixed_assignments' => $fixed,
        ];
    }
}

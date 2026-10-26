<?php

declare(strict_types=1);

namespace XanhHub\Domain\Scheduling;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\PlanOutput;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\RegistrationSpec;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\UnassignedEntry;

/**
 * Phương án cơ sở B1: Gán theo thứ tự đăng ký có kiểm tra ràng buộc (Fair Baseline).
 * Duyệt đơn theo thời gian đăng ký tăng dần và sử dụng ConstraintGuard để bỏ qua đơn vi phạm H1–H5.
 * Tuy nhiên vì không xét độ khan hiếm của ca và không có chuỗi đổi chỗ, B1 dễ để người đa kỹ năng
 * bị chiếm mất bởi ca phổ thông đăng ký trước (như ví dụ Linh – Phúc).
 */
final class FifoCheckedScheduler
{
    public function __construct(
        private readonly ConstraintGuard $guard = new ConstraintGuard(),
        private readonly PlanComparator $comparator = new PlanComparator()
    ) {
    }

    public function schedule(ScheduleDataset $dataset, int $seed = 202606): PlanOutput
    {
        $registrations = array_values(array_filter(
            $dataset->registrations,
            static fn(RegistrationSpec $r) => $r->status === 'ACTIVE'
        ));

        usort($registrations, static function (RegistrationSpec $a, RegistrationSpec $b): int {
            if ($a->registeredAtEpoch !== $b->registeredAtEpoch) {
                return $a->registeredAtEpoch <=> $b->registeredAtEpoch;
            }
            return $a->id <=> $b->id;
        });

        $decisions = $dataset->fixedAssignments;

        foreach ($registrations as $reg) {
            $check = $this->guard->validateAssignment(
                dataset: $dataset,
                currentAssignments: $decisions,
                shiftId: $reg->shiftId,
                volunteerId: $reg->volunteerId,
                requirementId: null,
                isManualEdit: false
            );

            if ($check['allowed']) {
                $decisions[] = new AssignmentDecision(
                    shiftId: $reg->shiftId,
                    requirementId: $check['resolved_requirement_id'],
                    volunteerId: $reg->volunteerId,
                    score: $reg->preferenceScore()
                );
            }
        }

        $evaluated = $this->comparator->evaluate($dataset, $decisions);
        $unassigned = $this->buildUnassignedList($dataset, $evaluated['annotated_decisions']);

        return new PlanOutput(
            algorithmCode: 'B1',
            priorityMode: 'FIFO_CHECKED',
            snapshotHash: $dataset->computeSnapshotHash(),
            decisions: $evaluated['annotated_decisions'],
            unassigned: $unassigned,
            metrics: $evaluated['metrics'],
            config: [
                'algorithm' => 'B1_FIFO_CHECKED',
                'description' => 'Gán theo thứ tự đăng ký có kiểm tra đầy đủ H1–H5, bỏ qua đơn vi phạm.',
                'random_seed' => $seed,
            ]
        );
    }

    /**
     * @param list<AssignmentDecision> $decisions
     * @return list<UnassignedEntry>
     */
    private function buildUnassignedList(ScheduleDataset $dataset, array $decisions): array
    {
        $unassigned = [];
        $byShift = [];
        foreach ($decisions as $d) {
            $byShift[$d->shiftId][] = $d;
        }

        foreach ($dataset->shifts as $shift) {
            $assigned = $byShift[$shift->id] ?? [];
            if (count($assigned) < $shift->minPeople) {
                $missing = $shift->minPeople - count($assigned);
                $unassigned[] = new UnassignedEntry(
                    UnassignedEntry::TYPE_SLOT,
                    $shift->id,
                    $shift->requirements[0]->id ?? null,
                    null,
                    $missing,
                    ReasonCode::TIME_CONFLICT,
                    sprintf('Ca %s còn thiếu %d người do gán theo thứ tự đăng ký làm ứng viên phù hợp bị kẹt ở ca khác hoặc thiếu kỹ năng.', $shift->code, $missing)
                );
            }
        }

        return $unassigned;
    }
}

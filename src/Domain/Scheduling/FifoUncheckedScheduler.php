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
 * Phương án cơ sở B0: Gán theo thứ tự đăng ký thuần (Naive First-Come-First-Served).
 * Duyệt đơn theo thời gian đăng ký tăng dần, gán vào ca nếu ca chưa đầy max_people,
 * BỎ QUA kiểm tra kỹ năng (H2), trùng giờ (H1), lịch rảnh (H4) và giới hạn tải (H5).
 * Dùng để đối chiếu theo đúng Yêu cầu kỹ thuật 2 (KT2) của đề bài.
 */
final class FifoUncheckedScheduler
{
    public function __construct(
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
        $shiftCounts = [];
        $assignedInSameShift = [];

        foreach ($decisions as $d) {
            $shiftCounts[$d->shiftId] = ($shiftCounts[$d->shiftId] ?? 0) + 1;
            $assignedInSameShift[$d->shiftId][$d->volunteerId] = true;
        }

        foreach ($registrations as $reg) {
            $shift = $dataset->shiftsById[$reg->shiftId] ?? null;
            if ($shift === null) {
                continue;
            }
            $currentCount = $shiftCounts[$shift->id] ?? 0;
            if ($currentCount >= $shift->maxPeople) {
                continue;
            }
            if (isset($assignedInSameShift[$shift->id][$reg->volunteerId])) {
                continue;
            }

            // Gán vào vị trí yêu cầu đầu tiên của ca (không kiểm tra kỹ năng hay trùng giờ)
            $firstReqId = $shift->requirements[0]->id ?? null;
            $decisions[] = new AssignmentDecision(
                shiftId: $shift->id,
                requirementId: $firstReqId,
                volunteerId: $reg->volunteerId,
                score: $reg->preferenceScore()
            );

            $shiftCounts[$shift->id] = $currentCount + 1;
            $assignedInSameShift[$shift->id][$reg->volunteerId] = true;
        }

        $evaluated = $this->comparator->evaluate($dataset, $decisions);
        $unassigned = [];

        foreach ($dataset->shifts as $shift) {
            $cnt = $shiftCounts[$shift->id] ?? 0;
            if ($cnt < $shift->minPeople) {
                $missing = $shift->minPeople - $cnt;
                $unassigned[] = new UnassignedEntry(
                    UnassignedEntry::TYPE_SLOT,
                    $shift->id,
                    $shift->requirements[0]->id ?? null,
                    null,
                    $missing,
                    ReasonCode::NO_AVAILABLE,
                    sprintf('Ca %s còn thiếu %d người so với mức tối thiểu %d.', $shift->code, $missing, $shift->minPeople)
                );
            }
        }

        return new PlanOutput(
            algorithmCode: 'B0',
            priorityMode: 'FIFO_UNCHECKED',
            snapshotHash: $dataset->computeSnapshotHash(),
            decisions: $evaluated['annotated_decisions'],
            unassigned: $unassigned,
            metrics: $evaluated['metrics'],
            config: [
                'algorithm' => 'B0_FIFO_UNCHECKED',
                'description' => 'Gán theo thứ tự thời gian đăng ký thuần, chỉ dừng khi ca đầy max_people, không kiểm tra kỹ năng và trùng giờ.',
                'random_seed' => $seed,
            ]
        );
    }
}

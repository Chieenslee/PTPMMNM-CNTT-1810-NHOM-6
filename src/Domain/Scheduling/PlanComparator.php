<?php

declare(strict_types=1);

namespace XanhHub\Domain\Scheduling;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\ScheduleDataset;

/**
 * Bộ đánh giá & so sánh các phương án phân công (KT2, T7, T8).
 * Tính đồng thời:
 * 1. Tỷ lệ ca đủ người theo đầu người (Headcount coverage)
 * 2. Tỷ lệ ca đủ người hợp lệ (Valid coverage - đủ min_people, đủ kỹ năng, 0 vi phạm)
 * 3. Số lượng vi phạm theo từng loại (TIME_CONFLICT, NO_SKILL, CAPACITY_FULL, NO_AVAILABLE, LOAD_LIMIT)
 * 4. Độ lệch chuẩn số ca (std_dev) và khoảng chênh lệch (max - min) giữa các tình nguyện viên.
 */
final class PlanComparator
{
    /**
     * Đánh giá một tập phân công và gắn danh sách mã vi phạm cho từng dòng phân công.
     *
     * @param list<AssignmentDecision> $rawDecisions
     * @return array{
     *   annotated_decisions: list<AssignmentDecision>,
     *   metrics: array<string, mixed>
     * }
     */
    public function evaluate(ScheduleDataset $dataset, array $rawDecisions): array
    {
        $byShift = [];
        $byVolunteer = [];
        foreach ($dataset->shifts as $s) {
            $byShift[$s->id] = [];
        }
        foreach ($dataset->volunteers as $v) {
            $byVolunteer[$v->id] = [];
        }

        foreach ($rawDecisions as $idx => $d) {
            $byShift[$d->shiftId][$idx] = $d;
            $byVolunteer[$d->volunteerId][$idx] = $d;
        }

        $violationBreakdown = [
            ReasonCode::TIME_CONFLICT => 0,
            ReasonCode::NO_SKILL => 0,
            ReasonCode::CAPACITY_FULL => 0,
            ReasonCode::NO_AVAILABLE => 0,
            ReasonCode::LOAD_LIMIT => 0,
        ];

        $decisionViolations = array_fill(0, count($rawDecisions), []);

        // 1. Kiểm tra theo từng tình nguyện viên: TIME_CONFLICT và LOAD_LIMIT
        foreach ($byVolunteer as $volId => $items) {
            $vol = $dataset->volunteersById[$volId] ?? null;
            if ($vol === null) {
                continue;
            }
            $itemList = array_values($items);
            $indices = array_keys($items);
            $count = count($itemList);

            for ($i = 0; $i < $count; $i++) {
                $d1 = $itemList[$i];
                $idx1 = $indices[$i];
                $s1 = $dataset->shiftsById[$d1->shiftId] ?? null;

                if ($i + 1 > $vol->maxShiftsPerWeek) {
                    $decisionViolations[$idx1][] = ReasonCode::LOAD_LIMIT;
                }

                if ($s1 !== null && !$vol->isAvailableForShift($s1)) {
                    $decisionViolations[$idx1][] = ReasonCode::NO_AVAILABLE;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    $d2 = $itemList[$j];
                    $idx2 = $indices[$j];
                    $s2 = $dataset->shiftsById[$d2->shiftId] ?? null;
                    if ($s1 !== null && $s2 !== null && ($s1->id === $s2->id || $s1->overlapsWith($s2))) {
                        $decisionViolations[$idx1][] = ReasonCode::TIME_CONFLICT;
                        $decisionViolations[$idx2][] = ReasonCode::TIME_CONFLICT;
                    }
                }
            }
        }

        // 2. Kiểm tra theo từng ca: CAPACITY_FULL và NO_SKILL
        $headcountCoveredShifts = 0;
        $validCoveredShifts = 0;
        $totalMinRequired = 0;
        $totalMaxCapacity = 0;
        $totalValidAssigned = 0;
        $shiftSummaries = [];

        foreach ($dataset->shifts as $shift) {
            $totalMinRequired += $shift->minPeople;
            $totalMaxCapacity += $shift->maxPeople;

            $items = $byShift[$shift->id] ?? [];
            $headcount = count($items);
            if ($headcount >= $shift->minPeople) {
                $headcountCoveredShifts++;
            }

            // Kiểm tra vượt max_people
            $pos = 0;
            foreach ($items as $idx => $d) {
                $pos++;
                if ($pos > $shift->maxPeople) {
                    $decisionViolations[$idx][] = ReasonCode::CAPACITY_FULL;
                }
            }

            // Kiểm tra kỹ năng theo vị trí yêu cầu của ca
            $reqMap = [];
            foreach ($shift->requirements as $req) {
                $reqMap[$req->id] = $req;
            }

            $reqFilledValid = [];
            $totalReqSlots = 0;
            foreach ($shift->requirements as $req) {
                $reqFilledValid[$req->id] = 0;
                $totalReqSlots += $req->requiredCount;
            }
            $generalCapacity = max(0, $shift->maxPeople - $totalReqSlots);
            $usedGeneralSlots = 0;

            foreach ($items as $idx => $d) {
                $vol = $dataset->volunteersById[$d->volunteerId] ?? null;
                if ($vol === null) {
                    $decisionViolations[$idx][] = ReasonCode::NO_SKILL;
                    continue;
                }

                if ($d->requirementId !== null && isset($reqMap[$d->requirementId])) {
                    $req = $reqMap[$d->requirementId];
                    if (!$vol->satisfiesRequirement($req)) {
                        $decisionViolations[$idx][] = ReasonCode::NO_SKILL;
                    } else {
                        $reqFilledValid[$req->id]++;
                    }
                } elseif ($shift->requirements !== []) {
                    // Nếu phân công không gắn requirement_id, thử ghép vào vị trí chuyên môn còn thiếu hoặc vị trí hỗ trợ chung
                    $matchedAny = false;
                    foreach ($shift->requirements as $req) {
                        if ($vol->satisfiesRequirement($req) && $reqFilledValid[$req->id] < $req->requiredCount) {
                            $reqFilledValid[$req->id]++;
                            $matchedAny = true;
                            break;
                        }
                    }
                    if (!$matchedAny) {
                        if ($usedGeneralSlots < $generalCapacity) {
                            $usedGeneralSlots++;
                        } else {
                            $decisionViolations[$idx][] = ReasonCode::NO_SKILL;
                        }
                    }
                }
            }

            // Tính số phân công hợp lệ (0 vi phạm) trong ca này
            $validInShift = 0;
            $shiftViolationCount = 0;
            foreach ($items as $idx => $d) {
                $vCodes = array_values(array_unique($decisionViolations[$idx]));
                if ($vCodes === []) {
                    $validInShift++;
                } else {
                    $shiftViolationCount += count($vCodes);
                }
            }

            $totalValidAssigned += $validInShift;

            // Một ca được tính là "Đủ người hợp lệ" (Valid Covered - T7) khi:
            // - Số người hợp lệ (0 vi phạm) >= min_people
            // - Không có phân công nào vi phạm trong ca
            $isShiftValidCovered = ($validInShift >= $shift->minPeople) && ($shiftViolationCount === 0);
            if ($isShiftValidCovered) {
                $validCoveredShifts++;
            }

            $shiftSummaries[$shift->id] = [
                'shift_id' => $shift->id,
                'shift_code' => $shift->code,
                'min_people' => $shift->minPeople,
                'max_people' => $shift->maxPeople,
                'assigned_headcount' => $headcount,
                'valid_assigned_count' => $validInShift,
                'is_headcount_covered' => $headcount >= $shift->minPeople,
                'is_valid_covered' => $isShiftValidCovered,
                'violation_count' => $shiftViolationCount,
            ];
        }

        // 3. Tạo danh sách AssignmentDecision đã gắn mã vi phạm và tổng hợp số vi phạm
        $annotated = [];
        $totalViolations = 0;
        $violatingDecisionsCount = 0;

        foreach ($rawDecisions as $idx => $d) {
            $codes = array_values(array_unique(array_merge($d->violationCodes, $decisionViolations[$idx])));
            if ($codes !== []) {
                $violatingDecisionsCount++;
            }
            foreach ($codes as $code) {
                if (isset($violationBreakdown[$code])) {
                    $violationBreakdown[$code]++;
                }
                $totalViolations++;
            }
            $annotated[] = new AssignmentDecision(
                $d->shiftId,
                $d->requirementId,
                $d->volunteerId,
                $d->score,
                $d->isLockedFixed,
                $codes
            );
        }

        // 4. Tính độ lệch chuẩn (std_dev) và khoảng chênh lệch số ca giữa các tình nguyện viên
        $registeredVolunteerIds = [];
        foreach ($dataset->registrations as $reg) {
            if ($reg->status === 'ACTIVE') {
                $registeredVolunteerIds[$reg->volunteerId] = true;
            }
        }
        if ($registeredVolunteerIds === []) {
            foreach ($dataset->volunteers as $v) {
                $registeredVolunteerIds[$v->id] = true;
            }
        }

        $loads = [];
        foreach (array_keys($registeredVolunteerIds) as $vid) {
            $loads[$vid] = count($byVolunteer[$vid] ?? []);
        }

        $numVols = max(1, count($loads));
        $meanLoad = array_sum($loads) / $numVols;
        $variance = 0.0;
        foreach ($loads as $l) {
            $variance += ($l - $meanLoad) ** 2;
        }
        $stdDev = sqrt($variance / $numVols);
        $maxLoad = $loads !== [] ? max($loads) : 0;
        $minLoad = $loads !== [] ? min($loads) : 0;

        $totalShifts = max(1, count($dataset->shifts));

        return [
            'annotated_decisions' => $annotated,
            'metrics' => [
                'total_shifts' => count($dataset->shifts),
                'headcount_covered_shifts' => $headcountCoveredShifts,
                'headcount_coverage_rate' => round(($headcountCoveredShifts / $totalShifts) * 100.0, 2),
                'valid_covered_shifts' => $validCoveredShifts,
                'valid_coverage_rate' => round(($validCoveredShifts / $totalShifts) * 100.0, 2),
                'total_assigned' => count($rawDecisions),
                'total_valid_assigned' => $totalValidAssigned,
                'total_min_required' => $totalMinRequired,
                'total_max_capacity' => $totalMaxCapacity,
                'min_slot_fill_rate' => round(($totalValidAssigned / max(1, $totalMinRequired)) * 100.0, 2),
                'total_violations' => $totalViolations,
                'violating_assignments_count' => $violatingDecisionsCount,
                'violation_breakdown' => $violationBreakdown,
                'load_mean' => round($meanLoad, 3),
                'load_std_dev' => round($stdDev, 4),
                'load_max' => $maxLoad,
                'load_min' => $minLoad,
                'load_spread' => $maxLoad - $minLoad,
                'volunteer_loads' => $loads,
                'shift_summaries' => $shiftSummaries,
            ],
        ];
    }
}

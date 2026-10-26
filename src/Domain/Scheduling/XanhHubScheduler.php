<?php

declare(strict_types=1);

namespace XanhHub\Domain\Scheduling;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\PlanOutput;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\RegistrationSpec;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\UnassignedEntry;
use XanhHub\Domain\Model\VolunteerSpec;

/**
 * Thuật toán Phân công Lõi của Xanh Hub (Phương án X - KT1 & KT2).
 *
 * Đặc tả kỹ thuật v1.1:
 * - Xét đồng thời kỹ năng (H2), thời gian không trùng (H1), lịch rảnh (H4), sĩ số ca (H3) và giới hạn số ca/tuần (H5).
 * - Chỉ xét ứng viên có đơn đăng ký ACTIVE vào ca đó (T5).
 * - Chia 2 pha:
 *   + Pha 1: Lấp đủ ngưỡng tối thiểu (min_people) của mọi ca.
 *   + Pha 2: Lấp mở rộng từ min_people tới max_people theo điểm ưu tiên và cân bằng tải.
 * - Mỗi pha thực hiện 3 bước:
 *   + Bước 1: Lọc khả thi (Feasibility Matrix) thỏa H2 & H4.
 *   + Bước 2: Gán tham lam theo độ khan hiếm (Scarcity-first Greedy) với hàm điểm phạt dư kỹ năng (-w_k * skillWaste).
 *   + Bước 3: Chuỗi đổi chỗ tăng cặp (Kuhn Augmenting Swap Chain) tối đa 4 bước để giải cứu ca bị kẹt do trùng giờ.
 */
final class XanhHubScheduler
{
    private const MAX_AUGMENT_DEPTH = 4;

    public function __construct(
        private readonly PlanComparator $comparator = new PlanComparator()
    ) {
    }

    public function schedule(ScheduleDataset $dataset, ?SchedulerWeights $weights = null): PlanOutput
    {
        $weights ??= SchedulerWeights::fromMode(SchedulerWeights::MODE_MAX_COVERAGE);

        // Lập chỉ mục đơn đăng ký ACTIVE theo [shiftId][volunteerId]
        /** @var array<int, array<int, RegistrationSpec>> $regByShiftVol */
        $regByShiftVol = [];
        foreach ($dataset->registrations as $reg) {
            if ($reg->status === 'ACTIVE') {
                $regByShiftVol[$reg->shiftId][$reg->volunteerId] = $reg;
            }
        }

        // Trạng thái phân công hiện tại: khởi tạo từ fixedAssignments (các ca đã CONFIRMED/LOCKED)
        /** @var list<AssignmentDecision> $decisions */
        $decisions = array_values($dataset->fixedAssignments);

        // Tách từng ca thành các "ô vị trí đơn vị" (unit slots) cho Pha 1 (min_people) và Pha 2 (tới max_people)
        $unitSlots = $this->buildUnitSlots($dataset, $decisions);

        // Bước 1: Lọc khả thi (Feasibility Filter) cho từng unit slot
        foreach ($unitSlots as $slotIdx => &$slot) {
            $shift = $dataset->shiftsById[$slot['shift_id']];
            $req = $slot['requirement'];
            $shiftRegs = $regByShiftVol[$shift->id] ?? [];
            $feasibleVolIds = [];

            foreach ($shiftRegs as $volId => $reg) {
                $vol = $dataset->volunteersById[$volId] ?? null;
                if ($vol === null) {
                    continue;
                }
                if (!$vol->isAvailableForShift($shift)) {
                    continue;
                }
                if ($req !== null && !$vol->satisfiesRequirement($req)) {
                    continue;
                }
                $feasibleVolIds[] = $volId;
            }
            sort($feasibleVolIds);
            $slot['feasible_volunteers'] = $feasibleVolIds;
        }
        unset($slot);

        // Đếm độ linh hoạt của mỗi tình nguyện viên (số lượng ca khả thi mà người đó có thể làm)
        // Dùng để ưu tiên giữ người đa năng cho ca khó và phá hòa điểm tất định
        $volunteerVersatility = [];
        foreach ($dataset->volunteers as $v) {
            $volunteerVersatility[$v->id] = 0;
        }
        foreach ($unitSlots as $slot) {
            foreach ($slot['feasible_volunteers'] as $vid) {
                $volunteerVersatility[$vid] = ($volunteerVersatility[$vid] ?? 0) + 1;
            }
        }

        // ====================================================================
        // PHA 1: Lấp đủ ngưỡng tối thiểu (min_people) cho mọi ca
        // ====================================================================
        $this->runGreedyByScarcity($dataset, $unitSlots, $decisions, $regByShiftVol, $volunteerVersatility, $weights, phase: 1);
        $this->runAugmentingSwapChains($dataset, $unitSlots, $decisions, $regByShiftVol, $weights, phase: 1);

        // ====================================================================
        // PHA 2: Mở rộng từ min_people tới max_people theo điểm ưu tiên
        // ====================================================================
        $this->runGreedyByScarcity($dataset, $unitSlots, $decisions, $regByShiftVol, $volunteerVersatility, $weights, phase: 2);
        $this->runAugmentingSwapChains($dataset, $unitSlots, $decisions, $regByShiftVol, $weights, phase: 2);

        // Tính lại điểm cho từng quyết định theo cấu hình hiện tại
        $finalDecisions = [];
        $loadByVol = [];
        foreach ($decisions as $d) {
            $loadByVol[$d->volunteerId] = ($loadByVol[$d->volunteerId] ?? 0) + 1;
        }
        foreach ($decisions as $d) {
            $shift = $dataset->shiftsById[$d->shiftId];
            $vol = $dataset->volunteersById[$d->volunteerId];
            $reg = $regByShiftVol[$d->shiftId][$d->volunteerId] ?? null;
            $req = null;
            foreach ($shift->requirements as $r) {
                if ($r->id === $d->requirementId) {
                    $req = $r;
                    break;
                }
            }
            $score = $this->computePairScore($vol, $shift, $req, $reg, max(0, ($loadByVol[$vol->id] ?? 1) - 1), $weights);
            $finalDecisions[] = new AssignmentDecision(
                shiftId: $d->shiftId,
                requirementId: $d->requirementId,
                volunteerId: $d->volunteerId,
                score: $score,
                isLockedFixed: $d->isLockedFixed
            );
        }

        $evaluated = $this->comparator->evaluate($dataset, $finalDecisions);
        $unassigned = $this->diagnoseUnassigned($dataset, $evaluated['annotated_decisions'], $regByShiftVol);

        return new PlanOutput(
            algorithmCode: 'X',
            priorityMode: $weights->priorityMode,
            snapshotHash: $dataset->computeSnapshotHash(),
            decisions: $evaluated['annotated_decisions'],
            unassigned: $unassigned,
            metrics: $evaluated['metrics'],
            config: $weights->toArray()
        );
    }

    /**
     * Tách yêu cầu của các ca thành danh sách các vị trí đơn vị (unit slots) gắn nhãn Pha 1 (min_people) và Pha 2 (max_people).
     *
     * @param list<AssignmentDecision> $fixedDecisions
     * @return list<array{
     *   slot_index: int,
     *   shift_id: int,
     *   requirement: ?SkillRequirementSpec,
     *   phase: int,
     *   assigned_volunteer_id: ?int,
     *   is_locked: bool,
     *   feasible_volunteers: list<int>
     * }>
     */
    private function buildUnitSlots(ScheduleDataset $dataset, array $fixedDecisions): array
    {
        $fixedByShift = [];
        foreach ($fixedDecisions as $fd) {
            $fixedByShift[$fd->shiftId][] = $fd;
        }

        $unitSlots = [];
        $slotIdx = 0;

        foreach ($dataset->shifts as $shift) {
            $shiftFixed = $fixedByShift[$shift->id] ?? [];
            $usedFixedIndices = [];

            // Tạo danh sách các vị trí yêu cầu kỹ năng của ca
            $rawShiftSlots = [];
            foreach ($shift->requirements as $req) {
                for ($i = 0; $i < $req->requiredCount; $i++) {
                    $rawShiftSlots[] = $req;
                }
            }
            // Nếu max_people lớn hơn tổng vị trí chuyên môn, phần còn lại là vị trí hỗ trợ chung (null)
            while (count($rawShiftSlots) < $shift->maxPeople) {
                $rawShiftSlots[] = null;
            }
            // Cắt nếu tổng requiredCount vượt maxPeople
            $rawShiftSlots = array_slice($rawShiftSlots, 0, $shift->maxPeople);

            foreach ($rawShiftSlots as $posInShift => $req) {
                $phase = ($posInShift < $shift->minPeople) ? 1 : 2;
                $assignedVolId = null;
                $isLocked = $shift->isLocked;

                // Khớp các phân công cố định (fixedAssignments) vào slot
                foreach ($shiftFixed as $fIdx => $fd) {
                    if (isset($usedFixedIndices[$fIdx])) {
                        continue;
                    }
                    if (($req !== null && $fd->requirementId === $req->id) || ($req === null && $fd->requirementId === null)) {
                        $assignedVolId = $fd->volunteerId;
                        $isLocked = true;
                        $usedFixedIndices[$fIdx] = true;
                        break;
                    }
                }

                $unitSlots[] = [
                    'slot_index' => $slotIdx++,
                    'shift_id' => $shift->id,
                    'requirement' => $req,
                    'phase' => $phase,
                    'assigned_volunteer_id' => $assignedVolId,
                    'is_locked' => $isLocked,
                    'feasible_volunteers' => [],
                ];
            }
        }

        return $unitSlots;
    }

    /**
     * Bước 2: Gán tham lam theo độ khan hiếm (Scarcity-first Greedy).
     *
     * @param list<array<string, mixed>> $unitSlots
     * @param list<AssignmentDecision> $decisions
     * @param array<int, array<int, RegistrationSpec>> $regByShiftVol
     * @param array<int, int> $volunteerVersatility
     */
    private function runGreedyByScarcity(
        ScheduleDataset $dataset,
        array &$unitSlots,
        array &$decisions,
        array $regByShiftVol,
        array $volunteerVersatility,
        SchedulerWeights $weights,
        int $phase
    ): void {
        // Lập chỉ mục các ca đã gán theo từng tình nguyện viên (tối đa max_shifts_per_week phần tử/người)
        // để kiểm tra H1 & H5 trong O(1) thay vì quét toàn bộ mảng $decisions
        $assignedShiftIdsByVol = [];
        foreach ($decisions as $d) {
            $assignedShiftIdsByVol[$d->volunteerId][] = $d->shiftId;
        }

        while (true) {
            $bestSlotKey = null;
            $bestScarcity = PHP_FLOAT_MAX;
            $bestShiftPriority = -1;
            $bestCandidateForSlot = null;
            $bestCandidateScore = -PHP_FLOAT_MAX;

            foreach ($unitSlots as $k => $slot) {
                if ($slot['phase'] !== $phase || $slot['assigned_volunteer_id'] !== null || $slot['is_locked']) {
                    continue;
                }

                $shift = $dataset->shiftsById[$slot['shift_id']];
                /** @var ?SkillRequirementSpec $req */
                $req = $slot['requirement'];

                // Lọc các ứng viên trong feasible_volunteers hiện đang rảnh giờ (H1) và chưa chạm trần số ca (H5)
                $availableCandidates = [];
                foreach ($slot['feasible_volunteers'] as $volId) {
                    $vol = $dataset->volunteersById[$volId] ?? null;
                    if ($vol === null) {
                        continue;
                    }
                    $volShiftIds = $assignedShiftIdsByVol[$volId] ?? [];
                    if (count($volShiftIds) >= $vol->maxShiftsPerWeek) {
                        continue;
                    }
                    $conflict = false;
                    foreach ($volShiftIds as $sid) {
                        if ($sid === $shift->id || $shift->overlapsWith($dataset->shiftsById[$sid])) {
                            $conflict = true;
                            break;
                        }
                    }
                    if (!$conflict) {
                        $availableCandidates[] = $volId;
                    }
                }

                if ($availableCandidates === []) {
                    continue;
                }

                $scarcity = (float) count($availableCandidates);

                // Chọn ứng viên tốt nhất trong slot này
                $topVolId = null;
                $topScore = -PHP_FLOAT_MAX;
                $topVersatility = PHP_INT_MAX;

                foreach ($availableCandidates as $volId) {
                    $vol = $dataset->volunteersById[$volId];
                    $reg = $regByShiftVol[$shift->id][$volId] ?? null;
                    $currentLoad = count($assignedShiftIdsByVol[$volId] ?? []);
                    $score = $this->computePairScore($vol, $shift, $req, $reg, $currentLoad, $weights);
                    $versatility = $volunteerVersatility[$volId] ?? 0;

                    if (
                        $score > $topScore + 1e-9
                        || (abs($score - $topScore) <= 1e-9 && $versatility < $topVersatility)
                        || (abs($score - $topScore) <= 1e-9 && $versatility === $topVersatility && ($topVolId === null || $volId < $topVolId))
                    ) {
                        $topScore = $score;
                        $topVolId = $volId;
                        $topVersatility = $versatility;
                    }
                }

                // So sánh độ khan hiếm giữa các slot: ưu tiên slot ít ứng viên nhất -> ca ưu tiên cao hơn -> mã slot nhỏ hơn
                if (
                    $scarcity < $bestScarcity - 1e-9
                    || (abs($scarcity - $bestScarcity) <= 1e-9 && $shift->priority > $bestShiftPriority)
                    || (abs($scarcity - $bestScarcity) <= 1e-9 && $shift->priority === $bestShiftPriority && $topScore > $bestCandidateScore + 1e-9)
                ) {
                    $bestScarcity = $scarcity;
                    $bestShiftPriority = $shift->priority;
                    $bestSlotKey = $k;
                    $bestCandidateForSlot = $topVolId;
                    $bestCandidateScore = $topScore;
                }
            }

            if ($bestSlotKey === null || $bestCandidateForSlot === null) {
                break;
            }

            // Thực hiện gán ứng viên vào slot khan hiếm nhất
            $chosenSlot = $unitSlots[$bestSlotKey];
            /** @var ?SkillRequirementSpec $req */
            $req = $chosenSlot['requirement'];

            $unitSlots[$bestSlotKey]['assigned_volunteer_id'] = $bestCandidateForSlot;
            $assignedShiftIdsByVol[$bestCandidateForSlot][] = $chosenSlot['shift_id'];
            $decisions[] = new AssignmentDecision(
                shiftId: $chosenSlot['shift_id'],
                requirementId: $req?->id,
                volunteerId: $bestCandidateForSlot,
                score: $bestCandidateScore
            );
        }
    }

    /**
     * Bước 3: Cải thiện bằng chuỗi đổi chỗ tăng cặp (Kuhn Augmenting Swap Chain, tối đa 4 bước).
     * Khi một slot đang trống vì ứng viên khả thi duy nhất đã bị gán vào một slot khác trùng giờ
     * (hoặc chạm trần số ca), thử chuyển ứng viên đó sang slot đang trống và tìm người khác thay vào slot cũ.
     *
     * @param list<array<string, mixed>> $unitSlots
     * @param list<AssignmentDecision> $decisions
     * @param array<int, array<int, RegistrationSpec>> $regByShiftVol
     */
    private function runAugmentingSwapChains(
        ScheduleDataset $dataset,
        array &$unitSlots,
        array &$decisions,
        array $regByShiftVol,
        SchedulerWeights $weights,
        int $phase
    ): void {
        $improved = true;
        $iterations = 0;

        while ($improved && $iterations < 20) {
            $improved = false;
            $iterations++;

            foreach ($unitSlots as $openKey => $openSlot) {
                if ($openSlot['phase'] > $phase || $openSlot['assigned_volunteer_id'] !== null || $openSlot['is_locked']) {
                    continue;
                }

                $visitedSlots = [$openKey => true];
                $visitedVols = [];

                if ($this->tryAugmentSlot($dataset, $unitSlots, $decisions, $openKey, $visitedSlots, $visitedVols, 1)) {
                    $this->rebuildDecisionsFromSlots($unitSlots, $decisions);
                    $improved = true;
                    break;
                }
            }
        }
    }

    /**
     * Đệ quy tìm đường tăng Kuhn độ sâu tối đa MAX_AUGMENT_DEPTH (4 bước).
     *
     * @param list<array<string, mixed>> $unitSlots
     * @param list<AssignmentDecision> $decisions
     * @param array<int, bool> $visitedSlots
     * @param array<int, bool> $visitedVols
     */
    private function tryAugmentSlot(
        ScheduleDataset $dataset,
        array &$unitSlots,
        array $decisions,
        int $targetSlotKey,
        array &$visitedSlots,
        array &$visitedVols,
        int $depth
    ): bool {
        if ($depth > self::MAX_AUGMENT_DEPTH) {
            return false;
        }

        $targetSlot = $unitSlots[$targetSlotKey];
        $targetShift = $dataset->shiftsById[$targetSlot['shift_id']];

        // 1. Thử tìm ứng viên khả thi có thể vào thẳng mà không xung đột
        foreach ($targetSlot['feasible_volunteers'] as $candVolId) {
            if ($this->canAssignVolunteerToShiftFromSlots($dataset, $unitSlots, $candVolId, $targetShift->id)) {
                $unitSlots[$targetSlotKey]['assigned_volunteer_id'] = $candVolId;
                return true;
            }
        }

        if ($depth === self::MAX_AUGMENT_DEPTH) {
            return false;
        }

        // 2. Nếu mọi ứng viên khả thi đều đang bận ở đúng 1 slot trùng giờ (hoặc chạm trần tải), thử đổi chỗ dây chuyền
        foreach ($targetSlot['feasible_volunteers'] as $candVolId) {
            if (isset($visitedVols[$candVolId])) {
                continue;
            }

            // Không được phân 2 vị trí trong cùng 1 ca cho cùng 1 người
            if ($this->isVolunteerInShiftSlots($unitSlots, $candVolId, $targetShift->id)) {
                continue;
            }

            // Tìm các slot hiện tại của $candVolId đang gây trùng giờ với $targetShift
            $blockingSlotKeys = $this->findBlockingSlotKeys($dataset, $unitSlots, $candVolId, $targetShift);
            if (count($blockingSlotKeys) !== 1) {
                continue;
            }

            $blockingKey = $blockingSlotKeys[0];
            if (isset($visitedSlots[$blockingKey]) || $unitSlots[$blockingKey]['is_locked']) {
                continue;
            }

            // Tạm thời nhấc $candVolId khỏi $blockingKey và đặt vào $targetSlotKey
            $prevVolInBlocking = $unitSlots[$blockingKey]['assigned_volunteer_id'];
            $unitSlots[$blockingKey]['assigned_volunteer_id'] = null;

            // Kiểm tra sau khi nhấc khỏi $blockingKey thì $candVolId có hợp lệ hoàn toàn với $targetShift không
            if ($this->canAssignVolunteerToShiftFromSlots($dataset, $unitSlots, $candVolId, $targetShift->id)) {
                $unitSlots[$targetSlotKey]['assigned_volunteer_id'] = $candVolId;
                $visitedSlots[$blockingKey] = true;
                $visitedVols[$candVolId] = true;

                if ($this->tryAugmentSlot($dataset, $unitSlots, $decisions, $blockingKey, $visitedSlots, $visitedVols, $depth + 1)) {
                    return true;
                }

                // Hoàn tác nếu nhánh đệ quy không tìm được người thay cho $blockingKey
                $unitSlots[$targetSlotKey]['assigned_volunteer_id'] = null;
            }

            $unitSlots[$blockingKey]['assigned_volunteer_id'] = $prevVolInBlocking;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $unitSlots
     * @param list<AssignmentDecision> $decisions
     */
    private function rebuildDecisionsFromSlots(array $unitSlots, array &$decisions): void
    {
        $rebuilt = [];
        foreach ($unitSlots as $slot) {
            if ($slot['assigned_volunteer_id'] === null) {
                continue;
            }
            /** @var ?SkillRequirementSpec $req */
            $req = $slot['requirement'];
            $rebuilt[] = new AssignmentDecision(
                shiftId: $slot['shift_id'],
                requirementId: $req?->id,
                volunteerId: $slot['assigned_volunteer_id'],
                score: 0.0,
                isLockedFixed: $slot['is_locked']
            );
        }
        $decisions = $rebuilt;
    }

    /**
     * @param list<AssignmentDecision> $decisions
     */
    private function canAssignVolunteerToShift(
        ScheduleDataset $dataset,
        array $decisions,
        int $volunteerId,
        int $targetShiftId
    ): bool {
        $vol = $dataset->volunteersById[$volunteerId] ?? null;
        $targetShift = $dataset->shiftsById[$targetShiftId] ?? null;
        if ($vol === null || $targetShift === null) {
            return false;
        }

        $load = 0;
        foreach ($decisions as $d) {
            if ($d->volunteerId !== $volunteerId) {
                continue;
            }
            $load++;
            if ($d->shiftId === $targetShiftId) {
                return false;
            }
            $otherShift = $dataset->shiftsById[$d->shiftId] ?? null;
            if ($otherShift !== null && $targetShift->overlapsWith($otherShift)) {
                return false;
            }
        }

        return $load < $vol->maxShiftsPerWeek;
    }

    /**
     * @param list<array<string, mixed>> $unitSlots
     */
    private function canAssignVolunteerToShiftFromSlots(
        ScheduleDataset $dataset,
        array $unitSlots,
        int $volunteerId,
        int $targetShiftId
    ): bool {
        $vol = $dataset->volunteersById[$volunteerId] ?? null;
        $targetShift = $dataset->shiftsById[$targetShiftId] ?? null;
        if ($vol === null || $targetShift === null) {
            return false;
        }

        $load = 0;
        foreach ($unitSlots as $slot) {
            if ($slot['assigned_volunteer_id'] !== $volunteerId) {
                continue;
            }
            $load++;
            if ($slot['shift_id'] === $targetShiftId) {
                return false;
            }
            $otherShift = $dataset->shiftsById[$slot['shift_id']] ?? null;
            if ($otherShift !== null && $targetShift->overlapsWith($otherShift)) {
                return false;
            }
        }

        return $load < $vol->maxShiftsPerWeek;
    }

    /**
     * @param list<array<string, mixed>> $unitSlots
     */
    private function isVolunteerInShiftSlots(array $unitSlots, int $volunteerId, int $shiftId): bool
    {
        foreach ($unitSlots as $slot) {
            if ($slot['shift_id'] === $shiftId && $slot['assigned_volunteer_id'] === $volunteerId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $unitSlots
     * @return list<int>
     */
    private function findBlockingSlotKeys(
        ScheduleDataset $dataset,
        array $unitSlots,
        int $volunteerId,
        ShiftSpec $targetShift
    ): array {
        $vol = $dataset->volunteersById[$volunteerId];
        $overlappingKeys = [];
        $allAssignedKeys = [];

        foreach ($unitSlots as $k => $slot) {
            if ($slot['assigned_volunteer_id'] !== $volunteerId) {
                continue;
            }
            $allAssignedKeys[] = $k;
            $otherShift = $dataset->shiftsById[$slot['shift_id']];
            if ($targetShift->overlapsWith($otherShift)) {
                $overlappingKeys[] = $k;
            }
        }

        if ($overlappingKeys !== []) {
            return $overlappingKeys;
        }

        // Nếu không trùng giờ nhưng đã chạm trần maxShiftsPerWeek, bất kỳ slot hiện tại nào cũng có thể nhường chỗ
        if (count($allAssignedKeys) >= $vol->maxShiftsPerWeek) {
            return [$allAssignedKeys[0]];
        }

        return [];
    }

    /**
     * @param list<AssignmentDecision> $decisions
     */
    private function countVolunteerShifts(array $decisions, int $volunteerId): int
    {
        $cnt = 0;
        foreach ($decisions as $d) {
            if ($d->volunteerId === $volunteerId) {
                $cnt++;
            }
        }
        return $cnt;
    }

    /**
     * Hàm điểm cặp (v, r) chuẩn v1.1:
     * score(v, r) = w_p * pri_s + w_n * prefScore(v, s) - w_k * skillWaste(v, r) - w_l * currentLoad(v) - w_a * P(absent_v)
     */
    private function computePairScore(
        VolunteerSpec $vol,
        ShiftSpec $shift,
        ?SkillRequirementSpec $req,
        ?RegistrationSpec $reg,
        int $currentLoad,
        SchedulerWeights $weights
    ): float {
        $priTerm = $weights->wPriority * (float) $shift->priority;
        $prefTerm = $weights->wPreference * ($reg !== null ? $reg->preferenceScore() : 1.0);
        $skillWaste = $req !== null ? (float) $vol->skillWasteFor($req) : 0.0;
        $wastePenalty = $weights->wSkillWaste * $skillWaste;
        $loadPenalty = $weights->wLoad * (float) $currentLoad;
        $absentPenalty = $weights->wAbsentRisk * $vol->absentRiskProbability;

        $repBonus = 0.0;
        if ($weights->wReputation > 0.0) {
            $competitionFactor = ($shift->priority >= 4) ? 1.5 : 1.0;
            $repBonus = $weights->wReputation * ($vol->reputationScore / 5.0) * $competitionFactor;
        }

        return $priTerm + $prefTerm + $repBonus - $wastePenalty - $loadPenalty - $absentPenalty;
    }

    /**
     * Phân tích nguyên nhân gốc rễ cho từng ca thiếu người (dưới min_people) và từng tình nguyện viên chưa được phân ca.
     * Tuyệt đối không tạo người giả để lấp chỗ (Phép thử bắt buộc số 4).
     *
     * @param list<AssignmentDecision> $decisions
     * @param array<int, array<int, RegistrationSpec>> $regByShiftVol
     * @return list<UnassignedEntry>
     */
    private function diagnoseUnassigned(
        ScheduleDataset $dataset,
        array $decisions,
        array $regByShiftVol
    ): array {
        $unassigned = [];
        $byShift = [];
        $byVol = [];

        foreach ($decisions as $d) {
            $byShift[$d->shiftId][] = $d;
            $byVol[$d->volunteerId][] = $d;
        }

        // 1. Chẩn đoán các ca chưa đạt min_people
        foreach ($dataset->shifts as $shift) {
            $assignedInShift = $byShift[$shift->id] ?? [];
            $assignedVolIds = array_column($assignedInShift, 'volunteerId');
            $headcount = count($assignedInShift);

            if ($headcount >= $shift->minPeople) {
                continue;
            }

            $missingTotal = $shift->minPeople - $headcount;
            $shiftRegs = $regByShiftVol[$shift->id] ?? [];

            // Tìm vị trí kỹ năng đang thiếu của ca
            $reqAssignedCounts = [];
            foreach ($assignedInShift as $d) {
                if ($d->requirementId !== null) {
                    $reqAssignedCounts[$d->requirementId] = ($reqAssignedCounts[$d->requirementId] ?? 0) + 1;
                }
            }

            $targetReq = null;
            foreach ($shift->requirements as $req) {
                $filled = $reqAssignedCounts[$req->id] ?? 0;
                if ($filled < $req->requiredCount) {
                    $targetReq = $req;
                    break;
                }
            }

            // Phân loại các ứng viên đã đăng ký ca này nhưng không được gán
            $skillMatchedCount = 0;
            $availMatchedCount = 0;
            $conflictBlockedCount = 0;
            $loadBlockedCount = 0;

            foreach ($shiftRegs as $volId => $reg) {
                if (in_array($volId, $assignedVolIds, true)) {
                    continue;
                }
                $vol = $dataset->volunteersById[$volId] ?? null;
                if ($vol === null) {
                    continue;
                }
                if ($targetReq !== null && !$vol->satisfiesRequirement($targetReq)) {
                    continue;
                }
                $skillMatchedCount++;

                if (!$vol->isAvailableForShift($shift)) {
                    continue;
                }
                $availMatchedCount++;

                $volCurrentDecisions = $byVol[$volId] ?? [];
                $hasOverlap = false;
                foreach ($volCurrentDecisions as $vd) {
                    $otherShift = $dataset->shiftsById[$vd->shiftId] ?? null;
                    if ($otherShift !== null && $shift->overlapsWith($otherShift)) {
                        $hasOverlap = true;
                        break;
                    }
                }
                if ($hasOverlap) {
                    $conflictBlockedCount++;
                } elseif (count($volCurrentDecisions) >= $vol->maxShiftsPerWeek) {
                    $loadBlockedCount++;
                }
            }

            if ($skillMatchedCount === 0 && $targetReq !== null) {
                $reason = ReasonCode::NO_SKILL;
                $explanation = sprintf(
                    'Ca %s (%s) còn thiếu %d người vì không đủ tình nguyện viên đăng ký đạt kỹ năng "%s" (cấp tối thiểu %d).',
                    $shift->code,
                    $shift->title,
                    $missingTotal,
                    $targetReq->skillName,
                    $targetReq->minLevel
                );
            } elseif ($availMatchedCount === 0) {
                $reason = ReasonCode::NO_AVAILABLE;
                $explanation = sprintf(
                    'Ca %s (%s) còn thiếu %d người vì các ứng viên có kỹ năng phù hợp đều bận ngoài khung lịch rảnh.',
                    $shift->code,
                    $shift->title,
                    $missingTotal
                );
            } elseif ($conflictBlockedCount > 0) {
                $reason = ReasonCode::TIME_CONFLICT;
                $explanation = sprintf(
                    'Ca %s (%s) còn thiếu %d người vì %d ứng viên đủ điều kiện đã được phân vào các ca trùng giờ ưu tiên hơn.',
                    $shift->code,
                    $shift->title,
                    $missingTotal,
                    $conflictBlockedCount
                );
            } elseif ($loadBlockedCount > 0) {
                $reason = ReasonCode::LOAD_LIMIT;
                $explanation = sprintf(
                    'Ca %s (%s) còn thiếu %d người vì ứng viên khả thi đã chạm trần số ca tối đa trong tuần.',
                    $shift->code,
                    $shift->title,
                    $missingTotal
                );
            } else {
                $reason = ReasonCode::NO_AVAILABLE;
                $explanation = sprintf(
                    'Ca %s (%s) còn thiếu %d người do số lượng đăng ký hợp lệ thấp hơn mức tối thiểu (%d người).',
                    $shift->code,
                    $shift->title,
                    $missingTotal,
                    $shift->minPeople
                );
            }

            $unassigned[] = new UnassignedEntry(
                itemType: UnassignedEntry::TYPE_SLOT,
                shiftId: $shift->id,
                requirementId: $targetReq?->id,
                volunteerId: null,
                missingCount: $missingTotal,
                reasonCode: $reason,
                explanationText: $explanation
            );
        }

        // 2. Chẩn đoán các tình nguyện viên có đăng ký nhưng chưa được phân vào bất kỳ ca nào
        $regsByVol = [];
        foreach ($dataset->registrations as $reg) {
            if ($reg->status === 'ACTIVE') {
                $regsByVol[$reg->volunteerId][] = $reg;
            }
        }

        foreach ($regsByVol as $volId => $volRegs) {
            if (isset($byVol[$volId]) && $byVol[$volId] !== []) {
                continue;
            }
            $vol = $dataset->volunteersById[$volId] ?? null;
            if ($vol === null) {
                continue;
            }

            $hadAvail = false;
            $hadSkill = false;
            foreach ($volRegs as $reg) {
                $s = $dataset->shiftsById[$reg->shiftId] ?? null;
                if ($s === null) {
                    continue;
                }
                if ($vol->isAvailableForShift($s)) {
                    $hadAvail = true;
                }
                foreach ($s->requirements as $req) {
                    if ($vol->satisfiesRequirement($req)) {
                        $hadSkill = true;
                    }
                }
                if ($s->requirements === []) {
                    $hadSkill = true;
                }
            }

            if (!$hadSkill) {
                $reason = ReasonCode::NO_SKILL;
                $text = sprintf('Tình nguyện viên %s (%s) chưa đạt cấp kỹ năng yêu cầu của các ca đã đăng ký.', $vol->fullName, $vol->studentCode);
            } elseif (!$hadAvail) {
                $reason = ReasonCode::NO_AVAILABLE;
                $text = sprintf('Tình nguyện viên %s (%s) đăng ký các ca nằm ngoài khung lịch rảnh cá nhân.', $vol->fullName, $vol->studentCode);
            } else {
                $reason = ReasonCode::CAPACITY_FULL;
                $text = sprintf('Tình nguyện viên %s (%s) chưa được bố trí do các ca đã đăng ký đã đủ ứng viên điểm ưu tiên cao hơn (đưa vào danh sách dự bị).', $vol->fullName, $vol->studentCode);
            }

            $unassigned[] = new UnassignedEntry(
                itemType: UnassignedEntry::TYPE_VOLUNTEER,
                shiftId: $volRegs[0]->shiftId,
                requirementId: null,
                volunteerId: $vol->id,
                missingCount: 1,
                reasonCode: $reason,
                explanationText: $text
            );
        }

        return $unassigned;
    }
}

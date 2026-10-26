<?php

declare(strict_types=1);

namespace XanhHub\Domain\Scheduling;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\VolunteerSpec;

/**
 * Bộ kiểm tra ràng buộc dùng chung cho Thuật toán (CN3) và Điều phối viên sửa tay (CN4, CN5).
 * Thuần PHP ở tầng Domain, không phụ thuộc PDO hay HTTP.
 */
final class ConstraintGuard
{
    /**
     * Kiểm tra việc gán tình nguyện viên $volunteerId vào ca $shiftId (vị trí $requirementId).
     *
     * @param list<AssignmentDecision> $currentAssignments Các phân công đang có hiệu lực
     * @return array{
     *   allowed: bool,
     *   requires_override: bool,
     *   hard_violations: list<string>,
     *   soft_warnings: list<string>,
     *   all_codes: list<string>,
     *   messages_vi: list<string>,
     *   resolved_requirement_id: ?int
     * }
     */
    public function validateAssignment(
        ScheduleDataset $dataset,
        array $currentAssignments,
        int $shiftId,
        int $volunteerId,
        ?int $requirementId = null,
        bool $isManualEdit = false,
        ?string $overrideReason = null,
        bool $unlockConfirmed = false
    ): array {
        $hardViolations = [];
        $softWarnings = [];

        $shift = $dataset->shiftsById[$shiftId] ?? null;
        $volunteer = $dataset->volunteersById[$volunteerId] ?? null;

        if ($shift === null || $volunteer === null) {
            $hardViolations[] = ReasonCode::NO_SKILL;
            return $this->buildResult($hardViolations, $softWarnings, $isManualEdit, $overrideReason, $requirementId);
        }

        // 0. Kiểm tra khóa ca (SHIFT_LOCKED) khi sửa tay
        if ($isManualEdit && $shift->isLocked && !$unlockConfirmed) {
            $hardViolations[] = ReasonCode::SHIFT_LOCKED;
        }

        // Phân loại phân công hiện tại của ca này và của tình nguyện viên này
        $shiftAssignments = [];
        $volunteerAssignments = [];
        $slotCounts = [];

        foreach ($currentAssignments as $decision) {
            if ($decision->shiftId === $shiftId) {
                $shiftAssignments[] = $decision;
                if ($decision->requirementId !== null) {
                    $slotCounts[$decision->requirementId] = ($slotCounts[$decision->requirementId] ?? 0) + 1;
                }
            }
            if ($decision->volunteerId === $volunteerId) {
                $volunteerAssignments[] = $decision;
            }
        }

        // 1. Kiểm tra H3: Vượt số người tối đa của ca (CAPACITY_FULL)
        if (count($shiftAssignments) >= $shift->maxPeople) {
            $hardViolations[] = ReasonCode::CAPACITY_FULL;
        }

        // Tự động xác định vị trí kỹ năng phù hợp nếu chưa truyền $requirementId
        $resolvedReqId = $requirementId;
        $matchedReq = null;

        if ($resolvedReqId !== null) {
            foreach ($shift->requirements as $req) {
                if ($req->id === $resolvedReqId) {
                    $matchedReq = $req;
                    break;
                }
            }
            if ($matchedReq === null) {
                $hardViolations[] = ReasonCode::NO_SKILL;
            } else {
                $currentSlotCount = $slotCounts[$matchedReq->id] ?? 0;
                if ($currentSlotCount >= $matchedReq->requiredCount) {
                    // Nếu vị trí kỹ năng cụ thể này đã đủ định biên tối thiểu, nhưng ca trực vẫn còn chỗ trống
                    // (count($shiftAssignments) < $shift->maxPeople): Chuyển sang suất nhân sự bổ sung của ca
                    // thay vì chặn nhầm CAPACITY_FULL của toàn ca.
                    if (count($shiftAssignments) < $shift->maxPeople) {
                        $resolvedReqId = null;
                        $matchedReq = null;
                    } else {
                        $hardViolations[] = ReasonCode::CAPACITY_FULL;
                    }
                }
                if ($matchedReq !== null && !$volunteer->satisfiesRequirement($matchedReq)) {
                    $hardViolations[] = ReasonCode::NO_SKILL;
                }
            }
        } else {
            // Tìm vị trí kỹ năng còn trống mà tình nguyện viên thỏa mãn
            if ($shift->requirements !== []) {
                $foundOpenMatchingReq = null;
                $anyOpenReq = false;
                $totalReqSlots = 0;

                foreach ($shift->requirements as $req) {
                    $totalReqSlots += $req->requiredCount;
                    $currentSlotCount = $slotCounts[$req->id] ?? 0;
                    if ($currentSlotCount < $req->requiredCount) {
                        $anyOpenReq = true;
                        if ($volunteer->satisfiesRequirement($req) && $foundOpenMatchingReq === null) {
                            $foundOpenMatchingReq = $req;
                        }
                    }
                }

                if ($foundOpenMatchingReq !== null) {
                    $resolvedReqId = $foundOpenMatchingReq->id;
                    $matchedReq = $foundOpenMatchingReq;
                } else {
                    // Kiểm tra xem ca có chỗ hỗ trợ chung (maxPeople > tổng requiredCount) hay không
                    $generalCapacity = max(0, $shift->maxPeople - $totalReqSlots);
                    $usedGeneral = 0;
                    foreach ($shiftAssignments as $sa) {
                        if ($sa->requirementId === null) {
                            $usedGeneral++;
                        }
                    }
                    if ($generalCapacity > $usedGeneral) {
                        $resolvedReqId = null;
                    } elseif ($anyOpenReq) {
                        $hardViolations[] = ReasonCode::NO_SKILL;
                    } else {
                        $hardViolations[] = ReasonCode::CAPACITY_FULL;
                    }
                }
            }
        }

        // 2. Kiểm tra H1: Trùng giờ (TIME_CONFLICT) với các ca đã phân công cho tình nguyện viên này
        foreach ($volunteerAssignments as $existing) {
            if ($existing->shiftId === $shiftId) {
                $hardViolations[] = ReasonCode::TIME_CONFLICT;
                break;
            }
            $otherShift = $dataset->shiftsById[$existing->shiftId] ?? null;
            if ($otherShift !== null && $shift->overlapsWith($otherShift)) {
                $hardViolations[] = ReasonCode::TIME_CONFLICT;
                break;
            }
        }

        // 3. Kiểm tra H4: Nằm trong khung lịch rảnh (NO_AVAILABLE)
        if (!$volunteer->isAvailableForShift($shift)) {
            if ($isManualEdit) {
                $softWarnings[] = ReasonCode::NO_AVAILABLE;
            } else {
                $hardViolations[] = ReasonCode::NO_AVAILABLE;
            }
        }

        // 4. Kiểm tra H5: Giới hạn số ca mỗi tuần (LOAD_LIMIT)
        if (count($volunteerAssignments) >= $volunteer->maxShiftsPerWeek) {
            if ($isManualEdit) {
                $softWarnings[] = ReasonCode::LOAD_LIMIT;
            } else {
                $hardViolations[] = ReasonCode::LOAD_LIMIT;
            }
        }

        // 5. Kiểm tra cảnh báo thời gian đệm di chuyển (TRAVEL_BUFFER) giữa hai ca liền nhau
        foreach ($volunteerAssignments as $existing) {
            $otherShift = $dataset->shiftsById[$existing->shiftId] ?? null;
            if ($otherShift === null || $shift->overlapsWith($otherShift)) {
                continue;
            }
            $gapMinutes = abs($shift->startEpoch - $otherShift->endEpoch) / 60.0;
            $gapMinutesReverse = abs($otherShift->startEpoch - $shift->endEpoch) / 60.0;
            $minGap = min($gapMinutes, $gapMinutesReverse);

            if ($minGap <= 30.0) {
                $distKm = $this->haversineKm($shift->lat, $shift->lng, $otherShift->lat, $otherShift->lng);
                $neededMinutes = ($distKm / 25.0) * 60.0; // Giả định tốc độ nội đô 25 km/h
                if ($distKm > 3.0 && $minGap < $neededMinutes) {
                    $softWarnings[] = ReasonCode::TRAVEL_BUFFER;
                    break;
                }
            }
        }

        return $this->buildResult($hardViolations, $softWarnings, $isManualEdit, $overrideReason, $resolvedReqId);
    }

    /**
     * @param list<string> $hardViolations
     * @param list<string> $softWarnings
     * @return array{
     *   allowed: bool,
     *   requires_override: bool,
     *   hard_violations: list<string>,
     *   soft_warnings: list<string>,
     *   all_codes: list<string>,
     *   messages_vi: list<string>,
     *   resolved_requirement_id: ?int
     * }
     */
    private function buildResult(
        array $hardViolations,
        array $softWarnings,
        bool $isManualEdit,
        ?string $overrideReason,
        ?int $resolvedReqId
    ): array {
        $hardViolations = array_values(array_unique($hardViolations));
        $softWarnings = array_values(array_unique($softWarnings));
        $allCodes = array_values(array_unique(array_merge($hardViolations, $softWarnings)));

        $hasHard = $hardViolations !== [];
        $hasSoft = $softWarnings !== [];
        $hasOverrideReason = $overrideReason !== null && trim($overrideReason) !== '';

        if ($hasHard) {
            $allowed = false;
            $requiresOverride = false;
        } elseif ($isManualEdit && $hasSoft && !$hasOverrideReason) {
            $allowed = false;
            $requiresOverride = true;
        } else {
            $allowed = true;
            $requiresOverride = false;
        }

        $messagesVi = array_map(static fn(string $c) => ReasonCode::messageVi($c), $allCodes);

        return [
            'allowed' => $allowed,
            'requires_override' => $requiresOverride,
            'hard_violations' => $hardViolations,
            'soft_warnings' => $softWarnings,
            'all_codes' => $allCodes,
            'messages_vi' => $messagesVi,
            'resolved_requirement_id' => $resolvedReqId,
        ];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return $earthRadius * $c;
    }
}

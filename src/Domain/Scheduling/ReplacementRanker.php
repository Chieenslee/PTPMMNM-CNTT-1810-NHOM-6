<?php

declare(strict_types=1);

namespace XanhHub\Domain\Scheduling;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\ScheduleDataset;

/**
 * Tính năng A3: Xếp hạng người thay thế có giải thích khi có tình nguyện viên báo vắng.
 * Tuyệt đối lọc qua ConstraintGuard (H1–H3 chặn cứng) trước khi chấm điểm xếp hạng.
 */
final class ReplacementRanker
{
    public function __construct(
        private readonly ConstraintGuard $guard = new ConstraintGuard()
    ) {
    }

    /**
     * @param list<AssignmentDecision> $activeAssignments Danh sách phân công hiện tại (đã trừ người vừa báo vắng)
     * @param list<int> $waitlistVolunteerIds Danh sách TNV trong hàng chờ dự bị của ca đó
     * @return list<array{
     *   volunteer_id: int,
     *   student_code: string,
     *   full_name: string,
     *   chapter_name: string,
     *   score: float,
     *   is_waitlisted: bool,
     *   is_fully_available: bool,
     *   current_load: int,
     *   max_shifts_per_week: int,
     *   reasons_vi: list<string>,
     *   soft_warnings: list<string>
     * }>
     */
    public function rankCandidates(
        ScheduleDataset $dataset,
        array $activeAssignments,
        int $shiftId,
        ?int $requirementId = null,
        array $waitlistVolunteerIds = [],
        int $limit = 5
    ): array {
        $shift = $dataset->shiftsById[$shiftId] ?? null;
        if ($shift === null) {
            return [];
        }

        $waitlistSet = array_flip($waitlistVolunteerIds);
        $regByVol = [];
        foreach ($dataset->registrations as $reg) {
            if ($reg->shiftId === $shiftId && $reg->status === 'ACTIVE') {
                $regByVol[$reg->volunteerId] = $reg;
            }
        }

        $loadByVol = [];
        foreach ($activeAssignments as $a) {
            $loadByVol[$a->volunteerId] = ($loadByVol[$a->volunteerId] ?? 0) + 1;
        }

        $targetReq = null;
        if ($requirementId !== null) {
            foreach ($shift->requirements as $r) {
                if ($r->id === $requirementId) {
                    $targetReq = $r;
                    break;
                }
            }
        }

        $candidates = [];

        foreach ($dataset->volunteers as $vol) {
            // Kiểm tra qua ConstraintGuard: H1–H3 bắt buộc phải hợp lệ (0 hard_violations)
            $check = $this->guard->validateAssignment(
                dataset: $dataset,
                currentAssignments: $activeAssignments,
                shiftId: $shiftId,
                volunteerId: $vol->id,
                requirementId: $requirementId,
                isManualEdit: true,
                overrideReason: 'REPLACEMENT_RANKING_CHECK',
                unlockConfirmed: true
            );

            if ($check['hard_violations'] !== []) {
                continue;
            }

            $isWaitlisted = isset($waitlistSet[$vol->id]);
            $hasRegistered = isset($regByVol[$vol->id]);
            $isAvail = $vol->isAvailableForShift($shift);
            $currentLoad = $loadByVol[$vol->id] ?? 0;

            if ($currentLoad >= $vol->maxShiftsPerWeek) {
                continue; // Không gợi ý người đã chạm trần số ca trong tuần
            }

            $score = 50.0;
            $reasons = [];

            if ($isWaitlisted) {
                $score += 25.0;
                $reasons[] = 'Đang nằm trong danh sách dự bị (Waitlist) của ca';
            } elseif ($hasRegistered) {
                $pref = $regByVol[$vol->id]->preferenceRank;
                $score += (4 - $pref) * 7.0;
                $reasons[] = sprintf('Đã đăng ký ca này (Nguyện vọng %d)', $pref);
            }

            if ($targetReq !== null) {
                $lvl = $vol->skillLevel($targetReq->skillId, $targetReq->requireVerified);
                $score += $lvl * 4.0;
                $reasons[] = sprintf('Đạt kỹ năng %s cấp %d/%d', $targetReq->skillName, $lvl, $targetReq->minLevel);
            } else {
                $reasons[] = 'Đủ điều kiện kỹ năng vị trí';
            }

            if ($isAvail) {
                $score += 15.0;
                $reasons[] = 'Khớp 100% khung lịch rảnh đã khai';
            } else {
                $score -= 15.0;
                $reasons[] = 'Cần xác nhận thêm lịch rảnh (ngoài khung mặc định)';
            }

            // Ưu tiên người đang ít ca trong tuần để đảm bảo công bằng
            $loadBonus = max(0, $vol->maxShiftsPerWeek - $currentLoad) * 3.5;
            $score += $loadBonus;
            $reasons[] = sprintf('Tải hiện tại thấp (%d/%d ca trong tuần)', $currentLoad, $vol->maxShiftsPerWeek);

            // Phạt nhẹ rủi ro vắng
            $score -= $vol->absentRiskProbability * 10.0;

            // Thưởng điểm uy tín thi đua (Reputation Score)
            if ($vol->reputationScore >= 4.5) {
                $score += ($vol->reputationScore - 3.0) * 4.0;
                $reasons[] = sprintf('Điểm uy tín xuất sắc (%.1f/5.0 sao thi đua)', $vol->reputationScore);
            }

            $candidates[] = [
                'volunteer_id' => $vol->id,
                'student_code' => $vol->studentCode,
                'full_name' => $vol->fullName,
                'chapter_name' => $vol->chapterName,
                'score' => round($score, 2),
                'is_waitlisted' => $isWaitlisted,
                'is_fully_available' => $isAvail,
                'current_load' => $currentLoad,
                'max_shifts_per_week' => $vol->maxShiftsPerWeek,
                'reasons_vi' => $reasons,
                'soft_warnings' => $check['soft_warnings'],
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            if (abs($a['score'] - $b['score']) > 1e-6) {
                return $b['score'] <=> $a['score'];
            }
            return $a['volunteer_id'] <=> $b['volunteer_id'];
        });

        return array_slice($candidates, 0, $limit);
    }
}

<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\AvailabilitySlot;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\VolunteerSpec;
use XanhHub\Domain\Scheduling\ConstraintGuard;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;

/**
 * Phép thử bắt buộc số 1: Một người không được phân vào hai ca trùng giờ.
 * Kiểm chứng ở cả 3 tầng:
 * - Tầng Thuật toán (XanhHubScheduler trên bộ D3 nhiều trùng lịch)
 * - Tầng Kiểm tra sửa tay (ConstraintGuard trả về mã TIME_CONFLICT)
 * - Tầng Cơ sở dữ liệu PostgreSQL (ràng buộc EXCLUDE USING gist chặn chèn trực tiếp)
 * - Biên khoảng nửa mở [start, end): ca kết thúc 10:00 và ca bắt đầu 10:00 KHÔNG tính trùng giờ.
 */
final class ConflictTimeTest extends TestCase
{
    public function testSchedulerNeverAssignsOverlappingShiftsOnD3(): void
    {
        $dataset = DatasetGenerator::createD3HighOverlapDataset(3);
        $scheduler = new XanhHubScheduler();
        $plan = $scheduler->schedule($dataset, SchedulerWeights::fromMode(SchedulerWeights::MODE_MAX_COVERAGE));

        $this->assertSame(0, $plan->metrics['violation_breakdown'][ReasonCode::TIME_CONFLICT], 'Thuật toán X không được có bất kỳ vi phạm TIME_CONFLICT nào trên D3.');

        $byVol = [];
        foreach ($plan->decisions as $d) {
            $byVol[$d->volunteerId][] = $dataset->shiftsById[$d->shiftId];
        }

        foreach ($byVol as $volId => $shifts) {
            $n = count($shifts);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $this->assertFalse(
                        $shifts[$i]->overlapsWith($shifts[$j]),
                        sprintf('TNV #%d bị phân vào 2 ca trùng giờ: %s và %s', $volId, $shifts[$i]->code, $shifts[$j]->code)
                    );
                }
            }
        }
    }

    public function testManualEditRejectsOverlappingShiftWithTimeConflictCode(): void
    {
        $dataset = DatasetGenerator::createLinhPhucMiniDataset();
        $scheduler = new XanhHubScheduler();
        $plan = $scheduler->schedule($dataset);

        // Linh (volunteerId = 1) đã được phân vào Ca A (101: 08:00-11:00).
        // Thử gán thêm Linh vào Ca B (102: 09:00-12:00) trùng giờ:
        $guard = new ConstraintGuard();
        $res = $guard->validateAssignment(
            dataset: $dataset,
            currentAssignments: $plan->decisions,
            shiftId: 102,
            volunteerId: 1,
            requirementId: null,
            isManualEdit: true
        );

        $this->assertFalse($res['allowed'], 'Gán một người vào ca trùng giờ khi sửa tay phải bị từ chối.');
        $this->assertContains(ReasonCode::TIME_CONFLICT, $res['hard_violations'], 'Phải trả về đúng mã lý do TIME_CONFLICT.');
    }

    public function testHalfOpenIntervalAdjacentShiftsDoNotConflict(): void
    {
        $base = strtotime('2026-11-07 00:00:00');
        $shiftMorning = new ShiftSpec(1, 'S1', 'Ca Sáng 08:00-10:00', $base + 8 * 3600, $base + 10 * 3600, '2026-11-07T08:00:00+07:00', '2026-11-07T10:00:00+07:00', 1, 2, 3, []);
        $shiftLateMorning = new ShiftSpec(2, 'S2', 'Ca Trưa 10:00-12:00', $base + 10 * 3600, $base + 12 * 3600, '2026-11-07T10:00:00+07:00', '2026-11-07T12:00:00+07:00', 1, 2, 3, []);

        $this->assertFalse($shiftMorning->overlapsWith($shiftLateMorning), 'Hai ca liền kề kết thúc 10:00 và bắt đầu 10:00 theo khoảng nửa mở [start, end) không tính trùng giờ.');
    }

    public function testPostgresExclusionConstraintBlocksOverlappingInsert(): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            // Tìm 1 phân công đang hiệu lực của volunteer_id = 1 trong hoạt động 3 (D3)
            $stmt = $pdo->query("
                SELECT a.volunteer_id, a.shift_id, s.activity_id
                  FROM assignments a
                  JOIN shifts s ON s.id = a.shift_id
                 WHERE a.activity_id = 3 AND a.volunteer_id = 1
                 LIMIT 1
            ");
            $row = $stmt->fetch();
            $this->assertTrue(is_array($row), 'Phải có phân công mẫu trong hoạt động 3.');

            // Tìm ca trùng giờ với ca đó (ở D3, ca 201 và 202 trùng giờ nhau)
            $conflictingShiftId = ((int) $row['shift_id'] === 201) ? 202 : 201;

            $blockedByDb = false;
            try {
                $ins = $pdo->prepare("
                    INSERT INTO assignments (activity_id, shift_id, volunteer_id, during, status)
                    SELECT activity_id, id, :vid, during, 'ASSIGNED'
                      FROM shifts WHERE id = :sid
                ");
                $ins->execute([':vid' => $row['volunteer_id'], ':sid' => $conflictingShiftId]);
            } catch (\PDOException $e) {
                $blockedByDb = true;
            }

            $this->assertTrue($blockedByDb, 'Ràng buộc EXCLUDE USING gist của PostgreSQL phải chặn chèn 2 ca trùng giờ cho cùng 1 tình nguyện viên.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}

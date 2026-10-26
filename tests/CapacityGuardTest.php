<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Scheduling\ConstraintGuard;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

/**
 * Phép thử bắt buộc số 3: Sửa phân công làm vượt số người của ca phải bị từ chối.
 * Kiểm chứng:
 * - Tầng Domain (ConstraintGuard trả về mã CAPACITY_FULL khi ca đã đạt max_people)
 * - Tầng API/Repository (manualAssignVolunteer từ chối người thứ max + 1)
 * - Tầng Cơ sở dữ liệu PostgreSQL (trigger trg_check_shift_capacity có SELECT ... FOR UPDATE chặn vượt max_people)
 */
final class CapacityGuardTest extends TestCase
{
    public function testConstraintGuardRejectsExceedingShiftMaxPeople(): void
    {
        $dataset = DatasetGenerator::createD1SufficientDataset(1);
        $shift = $dataset->shifts[0]; // CA-01: min = 2, max = 3, cần FIRST_AID cấp 3

        // Gán đủ 3 người có FIRST_AID (SV001, SV002, SV003) vào CA-01
        $current = [
            new AssignmentDecision($shift->id, $shift->requirements[0]->id, 1),
            new AssignmentDecision($shift->id, $shift->requirements[0]->id, 2),
            new AssignmentDecision($shift->id, $shift->requirements[0]->id, 3),
        ];

        // Thử gán thêm người thứ 4 (SV004 cũng có FIRST_AID cấp 4) vào CA-01:
        $guard = new ConstraintGuard();
        $res = $guard->validateAssignment(
            dataset: $dataset,
            currentAssignments: $current,
            shiftId: $shift->id,
            volunteerId: 4,
            requirementId: $shift->requirements[0]->id,
            isManualEdit: true
        );

        $this->assertFalse($res['allowed'], 'Gán người thứ max + 1 vào ca đã đầy phải bị từ chối.');
        $this->assertContains(ReasonCode::CAPACITY_FULL, $res['hard_violations'], 'Phải trả về đúng mã lý do CAPACITY_FULL.');
    }

    public function testRepositoryAndPostgresTriggerRejectCapacityOverflow(): void
    {
        $pdo = Database::getConnection();
        $repo = new XanhHubRepository($pdo);

        $pdo->beginTransaction();
        try {
            // Ca 1 của Hoạt động 1 (D1) có max_people = 3 và đã được nạp đủ 3 người khi seed
            $stmtCount = $pdo->query("
                SELECT COUNT(*) FROM assignments
                 WHERE shift_id = 1 AND status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
            ");
            $currentCount = (int) $stmtCount->fetchColumn();
            $this->assertSame(3, $currentCount, 'Ca 1 của D1 đã có đủ 3/3 người.');

            // 1. Thử gọi qua Repository (tương đương gọi API sửa phân công trực tiếp) với TNV #6:
            $apiResult = $repo->manualAssignVolunteer(
                activityId: 1,
                shiftId: 1,
                volunteerId: 6,
                requirementId: 1,
                overrideReason: 'Thử vượt số người qua API',
                unlockConfirmed: true,
                actorUserId: 31
            );
            $this->assertFalse($apiResult['allowed'], 'API/Repository phải từ chối khi ca đã đạt max_people.');
            $this->assertContains(ReasonCode::CAPACITY_FULL, $apiResult['hard_violations']);

            // 2. Thử chèn thẳng bằng câu lệnh SQL vào bảng assignments để kiểm tra trigger PostgreSQL:
            $triggerBlocked = false;
            try {
                $stmtIns = $pdo->prepare("
                    INSERT INTO assignments (activity_id, shift_id, requirement_id, volunteer_id, during, status)
                    SELECT activity_id, id, 1, 6, during, 'ASSIGNED'
                      FROM shifts WHERE id = 1
                ");
                $stmtIns->execute();
            } catch (\PDOException $e) {
                $triggerBlocked = str_contains($e->getMessage(), 'CAPACITY_FULL');
            }

            $this->assertTrue($triggerBlocked, 'Trigger trg_check_shift_capacity của PostgreSQL phải chặn chèn vượt max_people với lỗi CAPACITY_FULL.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}

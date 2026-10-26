<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\AvailabilitySlot;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\VolunteerSpec;
use XanhHub\Domain\Scheduling\ConstraintGuard;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;

/**
 * Phép thử bắt buộc số 2: Ca có yêu cầu kỹ năng không nhận người thiếu kỹ năng đó.
 * Kiểm chứng:
 * - Mọi phân công của thuật toán X trên D1, D2, D3 đều đạt đúng kỹ năng và cấp tối thiểu (min_level).
 * - Khi sửa tay (ConstraintGuard), gán người thiếu kỹ năng hoặc cấp độ thấp hơn min_level bị từ chối với mã NO_SKILL.
 * - Khi vị trí bật cờ require_verified (D6), người chỉ tự khai (is_verified = false) bị từ chối với mã NO_SKILL.
 */
final class SkillRequiredTest extends TestCase
{
    public function testAllAssignedVolunteersMeetSkillAndLevelRequirementsAcrossAllDatasets(): void
    {
        $scheduler = new XanhHubScheduler();
        $datasets = [
            DatasetGenerator::createD1SufficientDataset(1),
            DatasetGenerator::createD2UnderstaffedDataset(2),
            DatasetGenerator::createD3HighOverlapDataset(3),
        ];

        foreach ($datasets as $ds) {
            $plan = $scheduler->schedule($ds, SchedulerWeights::fromMode(SchedulerWeights::MODE_MAX_COVERAGE));
            $this->assertSame(0, $plan->metrics['violation_breakdown'][ReasonCode::NO_SKILL], "Bộ {$ds->datasetCode} không được có vi phạm NO_SKILL.");

            foreach ($plan->decisions as $d) {
                $vol = $ds->volunteersById[$d->volunteerId];
                $shift = $ds->shiftsById[$d->shiftId];
                foreach ($shift->requirements as $req) {
                    if ($d->requirementId === $req->id) {
                        $this->assertTrue(
                            $vol->satisfiesRequirement($req),
                            sprintf('TNV %s không đủ kỹ năng %s cấp %d cho ca %s', $vol->studentCode, $req->skillCode, $req->minLevel, $shift->code)
                        );
                    }
                }
            }
        }
    }

    public function testManualAssignRejectsVolunteerMissingRequiredSkillOrLevel(): void
    {
        $dataset = DatasetGenerator::createLinhPhucMiniDataset();
        $guard = new ConstraintGuard();

        // Phúc (volunteerId = 2) chỉ có LOGISTICS cấp 2, KHÔNG có FIRST_AID.
        // Thử gán Phúc vào Ca A (shiftId = 101, đòi FIRST_AID cấp 2):
        $res = $guard->validateAssignment(
            dataset: $dataset,
            currentAssignments: [],
            shiftId: 101,
            volunteerId: 2,
            requirementId: 1001,
            isManualEdit: true
        );

        $this->assertFalse($res['allowed'], 'Người thiếu kỹ năng Sơ cứu không được nhận vào Ca A.');
        $this->assertContains(ReasonCode::NO_SKILL, $res['hard_violations'], 'Phải trả về đúng mã lý do NO_SKILL.');
    }

    public function testUnverifiedSkillRejectedWhenShiftRequiresVerifiedSkill(): void
    {
        $base = strtotime('2026-11-07 00:00:00');
        $req = new SkillRequirementSpec(10, 1, DatasetGenerator::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', 3, 1, requireVerified: true);
        $shift = new ShiftSpec(1, 'CA-YTE', 'Ca Y tế Cần Xác minh', $base + 8 * 3600, $base + 11 * 3600, '2026-11-07T08:00:00+07:00', '2026-11-07T11:00:00+07:00', 1, 2, 5, [$req]);

        $unverifiedVol = new VolunteerSpec(
            id: 50,
            studentCode: 'SV050',
            fullName: 'TNV Tự Khai Chưa Xác Minh',
            chapterName: 'Chi đoàn KTPM-01',
            maxShiftsPerWeek: 3,
            skills: [
                DatasetGenerator::SKILL_FIRST_AID => ['level' => 4, 'is_verified' => false],
            ],
            availabilitySlots: [AvailabilitySlot::fromHm(6, '07:00', '18:00')]
        );

        $ds = new ScheduleDataset(1, 'TEST_D6', [$shift], [$unverifiedVol], []);
        $guard = new ConstraintGuard();
        $res = $guard->validateAssignment($ds, [], 1, 50, 10, isManualEdit: true);

        $this->assertFalse($res['allowed'], 'Kỹ năng chưa xác minh phải bị chặn ở vị trí yêu cầu xác minh (D6).');
        $this->assertContains(ReasonCode::NO_SKILL, $res['hard_violations']);
    }
}

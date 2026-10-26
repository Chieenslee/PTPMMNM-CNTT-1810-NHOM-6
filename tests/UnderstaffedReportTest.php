<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Model\UnassignedEntry;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;

/**
 * Phép thử bắt buộc số 4: Khi thiếu người, báo rõ các ca chưa đủ; không tạo thêm người giả để lấp chỗ.
 * Kiểm chứng trên bộ dữ liệu D2 (Thiếu người ở đúng 3 ca kỹ năng hiếm cấp 4):
 * - Danh sách unassigned chỉ rõ đúng 3 ca thiếu người (CA-04, CA-08, CA-12) kèm mã lý do NO_SKILL.
 * - Tuyệt đối không tạo người giả: mọi volunteer_id trong phương án đều nằm trong danh sách 30 TNV gốc.
 */
final class UnderstaffedReportTest extends TestCase
{
    public function testUnderstaffedShiftsAreExplicitlyReportedAndNoFakeVolunteersCreated(): void
    {
        $dataset = DatasetGenerator::createD2UnderstaffedDataset(2);
        $scheduler = new XanhHubScheduler();
        $plan = $scheduler->schedule($dataset, SchedulerWeights::fromMode(SchedulerWeights::MODE_MAX_COVERAGE));

        // 1. Kiểm tra không có người giả nào được tạo ra
        $validVolunteerIds = array_keys($dataset->volunteersById);
        $this->assertSame(30, count($validVolunteerIds), 'Bộ dữ liệu gốc có đúng 30 tình nguyện viên.');

        $assignedVolunteerIds = [];
        foreach ($plan->decisions as $d) {
            $this->assertTrue(
                isset($dataset->volunteersById[$d->volunteerId]),
                sprintf('Phát hiện mã tình nguyện viên giả không tồn tại trong hồ sơ gốc: #%d', $d->volunteerId)
            );
            $assignedVolunteerIds[$d->volunteerId] = true;
        }
        $this->assertTrue(count($assignedVolunteerIds) <= 30, 'Tổng số tình nguyện viên được phân công không vượt quá 30.');

        // 2. Kiểm tra danh sách ca chưa đủ người (unassigned slots)
        $understaffedSlots = array_values(array_filter(
            $plan->unassigned,
            static fn(UnassignedEntry $u) => $u->itemType === UnassignedEntry::TYPE_SLOT
        ));

        $this->assertSame(3, count($understaffedSlots), 'Bộ D2 phải báo cáo chính xác 3 ca thiếu người.');

        $understaffedShiftCodes = [];
        foreach ($understaffedSlots as $slot) {
            $shift = $dataset->shiftsById[$slot->shiftId];
            $understaffedShiftCodes[] = $shift->code;
            $this->assertSame(ReasonCode::NO_SKILL, $slot->reasonCode, 'Nguyên nhân thiếu người ở D2 phải là NO_SKILL (thiếu kỹ năng cấp 4).');
            $this->assertSame(2, $slot->missingCount, 'Mỗi ca hiếm cần 4 người nhưng chỉ có 2 chuyên gia cấp 4 nên thiếu đúng 2 người.');
        }

        $this->assertSame(['CA-04', 'CA-08', 'CA-12'], $understaffedShiftCodes, 'Danh sách 3 ca thiếu người phải khớp đáp án kỳ vọng CA-04, CA-08, CA-12.');
    }
}

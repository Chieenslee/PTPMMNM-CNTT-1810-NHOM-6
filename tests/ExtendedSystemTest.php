<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Scheduling\ConstraintGuard;
use XanhHub\Domain\Scheduling\ReplacementRanker;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Ai\HybridAiService;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;
use XanhHub\Infrastructure\Service\CsvSafeExporter;
use XanhHub\Infrastructure\Service\IcsExporter;
use XanhHub\Infrastructure\Service\QrCheckinService;

/**
 * Bộ phép thử mở rộng:
 * - Kiểm thử theo thuộc tính ngẫu nhiên (Property-based testing) với nhiều hạt ngẫu nhiên
 * - Kiểm thử hiệu năng trên bộ D4 (300 TNV, 60 ca, 12 kỹ năng < 2 giây)
 * - Kiểm thử cảnh báo mềm H4/H5 cho phép ghi đè kèm lý do khi sửa tay
 * - Kiểm thử xếp hạng người thay thế A3 khi báo vắng
 * - Kiểm thử QR HMAC xoay vòng 30 giây (chống mã hết hạn, sai ca, quét 2 lần)
 * - Kiểm thử chống chèn công thức CSV (Formula Injection) và xuất lịch .ics
 * - Kiểm thử trợ lý AI tiếng Việt A1 & A4 ở chế độ dự phòng thuần PHP
 * - Kiểm thử tính bất biến và chuỗi mã băm SHA-256 của bảng audit_log
 */
final class ExtendedSystemTest extends TestCase
{
    public function testPropertyBasedRandomSeedsNeverProduceHardViolations(): void
    {
        $scheduler = new XanhHubScheduler();
        for ($seed = 1001; $seed <= 1015; $seed++) {
            $ds = DatasetGenerator::createD3HighOverlapDataset(3);
            $mode = ($seed % 2 === 0) ? SchedulerWeights::MODE_MAX_COVERAGE : SchedulerWeights::MODE_FAIRNESS;
            $plan = $scheduler->schedule($ds, SchedulerWeights::fromMode($mode, $seed));

            $this->assertSame(0, $plan->metrics['total_violations'], "Hạt #{$seed} không được sinh bất kỳ vi phạm H1–H5 nào.");
        }
    }

    public function testLargeBenchmarkD4RunsUnderTwoSecondsWithZeroViolations(): void
    {
        $t0 = microtime(true);
        $d4 = DatasetGenerator::createD4BenchmarkDataset(4, 202606);
        $scheduler = new XanhHubScheduler();
        $plan = $scheduler->schedule($d4, SchedulerWeights::fromMode(SchedulerWeights::MODE_MAX_COVERAGE, 202606));
        $elapsed = microtime(true) - $t0;

        $this->assertSame(0, $plan->metrics['total_violations'], 'Bộ D4 (300 TNV, 60 ca) phải đạt 0 vi phạm.');
        $this->assertTrue($elapsed < 2.0, sprintf('Bộ D4 phải chạy dưới 2 giây (thực tế: %.3fs).', $elapsed));
    }

    public function testSoftWarningAllowsManualOverrideWithAuditReason(): void
    {
        $ds = DatasetGenerator::createD1SufficientDataset(1);
        // Ca 9 (Sáng Chủ Nhật): SV029 (id = 29) bận sáng Chủ Nhật (chỉ rảnh 13:00-21:00) nhưng có kỹ năng MEDIA cấp 3
        $sundayMorningShift = $ds->shifts[8]; // CA-09 (Sáng CN, cần MEDIA cấp 3)
        $guard = new ConstraintGuard();

        // 1. Chưa nhập lý do ghi đè -> bị chặn tạm thời với requires_override = true và mã NO_AVAILABLE
        $resWithoutReason = $guard->validateAssignment(
            dataset: $ds,
            currentAssignments: [],
            shiftId: $sundayMorningShift->id,
            volunteerId: 29,
            requirementId: $sundayMorningShift->requirements[0]->id,
            isManualEdit: true,
            overrideReason: null
        );
        $this->assertFalse($resWithoutReason['allowed']);
        $this->assertTrue($resWithoutReason['requires_override']);
        $this->assertContains(ReasonCode::NO_AVAILABLE, $resWithoutReason['soft_warnings']);

        // 2. Nhập lý do ghi đè -> được phép (allowed = true)
        $resWithReason = $guard->validateAssignment(
            dataset: $ds,
            currentAssignments: [],
            shiftId: $sundayMorningShift->id,
            volunteerId: 29,
            requirementId: $sundayMorningShift->requirements[0]->id,
            isManualEdit: true,
            overrideReason: 'Đã gọi điện xác nhận TNV đổi lịch cá nhân sáng Chủ Nhật'
        );
        $this->assertTrue($resWithReason['allowed'], 'Cảnh báo mềm H4 phải cho phép ghi đè khi điều phối viên nhập lý do.');
    }

    public function testReplacementRankerNeverSuggestsViolatingCandidates(): void
    {
        $ds = DatasetGenerator::createD3HighOverlapDataset(3);
        $plan = (new XanhHubScheduler())->schedule($ds);

        // Giả sử người đầu tiên trong Ca 201 báo vắng
        $firstDecision = $plan->decisions[0];
        $remaining = array_values(array_slice($plan->decisions, 1));

        $ranker = new ReplacementRanker();
        $candidates = $ranker->rankCandidates($ds, $remaining, $firstDecision->shiftId, $firstDecision->requirementId, [], 5);

        $guard = new ConstraintGuard();
        foreach ($candidates as $cand) {
            $check = $guard->validateAssignment(
                dataset: $ds,
                currentAssignments: $remaining,
                shiftId: $firstDecision->shiftId,
                volunteerId: $cand['volunteer_id'],
                requirementId: $firstDecision->requirementId,
                isManualEdit: true,
                overrideReason: 'TEST'
            );
            $this->assertSame([], $check['hard_violations'], 'Ứng viên thay thế A3 không bao giờ được vi phạm H1–H3.');
        }
    }

    public function testQrCheckinRotationAndExpiration(): void
    {
        $qr = new QrCheckinService('test-secret-key');
        $shiftStart = 1762477200;
        $shiftEnd = $shiftStart + 10800;
        $now = $shiftStart + 300;

        $token = $qr->generateToken(101, $now);

        // 1. Quét hợp lệ trong khung 30 giây
        $ok = $qr->verifyCheckin($token, 101, $shiftStart, $shiftEnd, isAssignedVolunteer: true, alreadyCheckedIn: false, nowEpoch: $now + 5);
        $this->assertTrue($ok['valid']);

        // 2. Quét lại sau 31 giây -> hết hạn
        $expired = $qr->verifyCheckin($token, 101, $shiftStart, $shiftEnd, isAssignedVolunteer: true, alreadyCheckedIn: false, nowEpoch: $now + 35);
        $this->assertFalse($expired['valid']);
        $this->assertSame('QR_TOKEN_EXPIRED', $expired['error_code']);

        // 3. Quét mã của ca 101 cho ca 102 -> sai ca
        $wrongShift = $qr->verifyCheckin($token, 102, $shiftStart, $shiftEnd, isAssignedVolunteer: true, alreadyCheckedIn: false, nowEpoch: $now);
        $this->assertFalse($wrongShift['valid']);
        $this->assertSame('WRONG_SHIFT_QR', $wrongShift['error_code']);

        // 4. Quét lần thứ hai -> từ chối
        $duplicate = $qr->verifyCheckin($token, 101, $shiftStart, $shiftEnd, isAssignedVolunteer: true, alreadyCheckedIn: true, nowEpoch: $now);
        $this->assertFalse($duplicate['valid']);
        $this->assertSame('ALREADY_CHECKED_IN', $duplicate['error_code']);
    }

    public function testCsvFormulaInjectionAndIcsExport(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", CsvSafeExporter::sanitizeCell('=HYPERLINK("http://evil.test")'));
        $this->assertSame("'+SUM(A1:A2)", CsvSafeExporter::sanitizeCell('+SUM(A1:A2)'));

        $ds = DatasetGenerator::createLinhPhucMiniDataset();
        $ics = IcsExporter::exportVolunteerCalendar('Nguyễn Thùy Linh', 'Tiếp sức mùa thi', $ds->shifts);
        $this->assertTrue(str_contains($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'TZID=Asia/Ho_Chi_Minh'));
    }

    public function testHybridAiFallbackParsesVietnameseShiftPromptAccurately(): void
    {
        $ai = new HybridAiService(aiEnabled: false);
        $draft = $ai->parseNaturalLanguageShift('Cần 6 người dựng lều sáng thứ Bảy 7h-11h, tối thiểu 4 người, 2 người biết sơ cứu cấp 3');

        $this->assertSame(6, $draft['max_people']);
        $this->assertSame(4, $draft['min_people']);
        $this->assertSame('07:00', $draft['start_hm']);
        $this->assertSame('11:00', $draft['end_hm']);
        $this->assertSame(6, $draft['day_of_week']);
    }

    public function testAuditLogHashChainIntegrityAndImmutability(): void
    {
        $pdo = Database::getConnection();
        $repo = new XanhHubRepository($pdo);

        $this->assertTrue($repo->verifyAuditLogChain(), 'Chuỗi mã băm SHA-256 của audit_log phải toàn vẹn.');

        $blockedUpdate = false;
        try {
            $pdo->exec("UPDATE audit_log SET reason = 'Sửa trái phép' WHERE id = 1");
        } catch (\PDOException $e) {
            $blockedUpdate = str_contains($e->getMessage(), 'AUDIT_LOG_IMMUTABLE');
        }
        $this->assertTrue($blockedUpdate, 'Bảng audit_log phải chặn tuyệt đối lệnh UPDATE/DELETE.');
    }
}

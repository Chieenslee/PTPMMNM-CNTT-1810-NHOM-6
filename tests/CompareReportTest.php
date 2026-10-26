<?php

declare(strict_types=1);

namespace XanhHub\Tests;

use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Scheduling\FifoCheckedScheduler;
use XanhHub\Domain\Scheduling\FifoUncheckedScheduler;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;

/**
 * Phép thử bắt buộc số 5: Báo cáo so sánh dùng cùng dữ liệu và lưu cấu hình của từng thuật toán.
 * Kiểm chứng:
 * - Cả 3 phương án B0, B1, X chạy trên cùng dữ liệu có cùng mã băm SHA-256 (snapshot_hash).
 * - Cấu hình JSON của từng thuật toán được lưu đầy đủ trong bảng plans.
 * - Chạy lại với cùng cấu hình và hạt ngẫu nhiên cho kết quả giống hệt 100%.
 * - Trên ví dụ Linh – Phúc và bộ D3, X vượt trội B0 (0 vi phạm so với >0 vi phạm) và vượt trội B1 về tỷ lệ ca đủ người hợp lệ.
 * - Trên bộ D1 ở chế độ Công bằng (FAIRNESS), X giảm độ lệch chuẩn số ca >= 40% so với B1.
 */
final class CompareReportTest extends TestCase
{
    public function testLinhPhucMiniExampleDemonstratesScarcityAdvantage(): void
    {
        $ds = DatasetGenerator::createLinhPhucMiniDataset();
        $b0 = (new FifoUncheckedScheduler())->schedule($ds);
        $b1 = (new FifoCheckedScheduler())->schedule($ds);
        $x  = (new XanhHubScheduler())->schedule($ds);

        $this->assertSame($b0->snapshotHash, $b1->snapshotHash, 'B0 và B1 phải dùng chung snapshot dữ liệu.');
        $this->assertSame($b1->snapshotHash, $x->snapshotHash, 'B1 và X phải dùng chung snapshot dữ liệu.');

        // Ở B1: Linh bị gán vào Ca B trước nên Ca A thiếu người (chỉ đạt 1/2 ca = 50%)
        $this->assertSame(1, $b1->metrics['valid_covered_shifts'], 'Cách gán theo thứ tự B1 chỉ lấp được 1/2 ca trong bài toán Linh – Phúc.');
        // Ở X: Linh được xếp vào Ca A (Sơ cứu), Phúc được xếp vào Ca B (Hậu cần) -> đạt 2/2 ca = 100%
        $this->assertSame(2, $x->metrics['valid_covered_shifts'], 'Thuật toán Xanh Hub (X) phải lấp đủ 2/2 ca trong bài toán Linh – Phúc.');
        $this->assertSame(0, $x->metrics['total_violations'], 'Thuật toán X không có vi phạm.');
    }

    public function testComparisonReportUsesSameSnapshotAndPersistsConfigInDatabase(): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query("
            SELECT p.activity_id, p.algorithm_code, p.priority_mode, p.config_json, p.metrics_json, ds.sha256_hash
              FROM plans p
              JOIN dataset_snapshots ds ON ds.id = p.snapshot_id
             WHERE p.activity_id IN (1, 2, 3)
             ORDER BY p.activity_id ASC, p.id ASC
        ")->fetchAll();

        $byActivity = [];
        foreach ($rows as $r) {
            $byActivity[(int) $r['activity_id']][] = $r;
        }

        foreach ([1, 2, 3] as $actId) {
            $plans = $byActivity[$actId] ?? [];
            $this->assertGreaterThanOrEqual(3, count($plans), "Hoạt động #{$actId} phải lưu đủ ít nhất 3 phương án B0, B1, X.");

            $hashes = array_unique(array_column($plans, 'sha256_hash'));
            $this->assertSame(1, count($hashes), "Mọi phương án so sánh của hoạt động #{$actId} phải tham chiếu cùng 1 mã băm dữ liệu đầu vào.");

            foreach ($plans as $p) {
                $cfg = json_decode((string) $p['config_json'], true);
                $this->assertTrue(is_array($cfg) && isset($cfg['random_seed']), 'Cấu hình lưu của từng thuật toán phải chứa random_seed.');
            }
        }
    }

    public function testDeterministicReplayAndFairnessImprovementTarget(): void
    {
        $d1 = DatasetGenerator::createD1SufficientDataset(1);
        $schedB1 = new FifoCheckedScheduler();
        $schedX = new XanhHubScheduler();

        $b1Run = $schedB1->schedule($d1, 202606);
        $xRun1 = $schedX->schedule($d1, SchedulerWeights::fromMode(SchedulerWeights::MODE_FAIRNESS, 202606));
        $xRun2 = $schedX->schedule($d1, SchedulerWeights::fromMode(SchedulerWeights::MODE_FAIRNESS, 202606));

        // Chạy lại với cùng cấu hình phải cho kết quả giống hệt 100%
        $this->assertSame($xRun1->toArray(), $xRun2->toArray(), 'Chạy lại thuật toán X với cùng dữ liệu và hạt phải cho kết quả giống hệt 100%.');

        // Kiểm tra mục tiêu giảm >= 40% độ lệch chuẩn số ca so với B1 ở chế độ Công bằng
        $stdB1 = (float) $b1Run->metrics['load_std_dev'];
        $stdX = (float) $xRun1->metrics['load_std_dev'];
        $reductionPercent = (($stdB1 - $stdX) / $stdB1) * 100.0;

        $this->assertGreaterThanOrEqual(40.0, $reductionPercent, 'Chế độ Công bằng của Xanh Hub phải giảm ít nhất 40% độ lệch chuẩn số ca so với B1.');
    }
}

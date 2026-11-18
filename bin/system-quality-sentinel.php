<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Service\SystemSentinelService;

echo "========================================================================\n";
echo " XANH HUB (ĐỀ TÀI 6) — TRUNG TÂM GIÁM SÁT ĐỘC LẬP & BẰNG CHỨNG KIỂM ĐỊNH\n";
echo " (INDEPENDENT QUALITY SENTINEL & EMPIRICAL EVIDENCE AUDIT PIPELINE)\n";
echo "========================================================================\n";
echo " Thư mục dự án:  " . dirname(__DIR__) . "\n";
echo " Thời điểm chạy: " . date('Y-m-d H:i:s') . "\n";
echo " Cơ chế:         Thu thập BẰNG CHỨNG KHÁCH QUAN, chống tự khen vô căn cứ\n";
echo "------------------------------------------------------------------------\n\n";

$pdo = Database::getConnection();
$sentinel = new SystemSentinelService($pdo);

$report = $sentinel->runFullDiagnostic();

$phaseIndex = 1;
$totalPhases = count($report['categories']);

foreach ($report['categories'] as $catKey => $cat) {
    $statusColor = $cat['status'] === 'PASS' ? "\033[32m" : "\033[31m";
    $statusReset = "\033[0m";
    echo "▶ [PHA {$phaseIndex}/{$totalPhases}] {$cat['name']} [{$cat['passed']}/{$cat['total']} ĐẠT]...\n";

    foreach ($cat['probes'] as $probe) {
        $tag = $probe['status'] === 'PASS' 
            ? "  \033[32m[PASS]\033[0m" 
            : "  \033[31m[FAIL]\033[0m";
        
        printf("%s %-22s %s (%0.1f ms)\n", $tag, $probe['id'], $probe['name'], $probe['duration_ms']);
        echo "         + Kỳ vọng:  {$probe['expected']}\n";
        echo "         + Thực tế:  {$probe['actual']}\n";
        echo "         + Bằng chứng: {$probe['evidence']}\n\n";
    }

    $phaseIndex++;
}

echo "------------------------------------------------------------------------\n";
echo " TỔNG KẾT BẰNG CHỨNG GIÁM SÁT ĐỘC LẬP:\n";
echo " - Điểm Chất lượng:   {$report['quality_score']}% ({$report['passed_probes']}/{$report['total_probes']} tiêu chí ĐẠT)\n";
echo " - Trạng thái:        " . ($report['overall_status'] === 'PERFECT' ? "\033[32mHOÀN HẢO (100% SẠCH LỖI)\033[0m" : "\033[31mCÓ KHIẾM KHUYẾT CẦN SỬA\033[0m") . "\n";
echo " - Thời gian đo đạc:  {$report['execution_time_ms']} ms\n";
echo "========================================================================\n";

if ($report['overall_status'] === 'PERFECT') {
    echo "✔ BẰNG CHỨNG XÁC THỰC: HỆ THỐNG ĐẠT CHUẨN HOÀN TOÀN KHÔNG PHÁT HIỆN LỖI!\n";
    exit(0);
} else {
    echo "✘ CẢNH BÁO BẰNG CHỨNG: PHÁT HIỆN {$report['failed_probes']} TIÊU CHÍ KHÔNG ĐẠT.\n";
    exit(1);
}

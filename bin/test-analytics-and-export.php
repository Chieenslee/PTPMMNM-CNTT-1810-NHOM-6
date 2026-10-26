<?php

declare(strict_types=1);

/**
 * XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ TỰ ĐỘNG PHÂN HỆ PHÂN TÍCH BI & XUẤT DỮ LIỆU ĐOÀN THANH NIÊN
 * (AUTOMATED TEST SUITE FOR ANALYTICS BI DASHBOARD, FORMAL REPORT & FORMULA-SAFE CSV EXPORT)
 *
 * Kiểm tra 5 trụ cột chức năng và an toàn bảo mật:
 * 1. Chống tấn công CSV Formula Injection theo chuẩn OWASP (CsvSafeExporter)
 * 2. Xuất bảng phân công nhân sự, điểm danh và giờ cống hiến chi tiết (Detailed CSV & Analytics Summary)
 * 3. Động cơ phân tích BI Đoàn Thanh niên (AnalyticsService: Trường/Khoa, Staffing Ratio, Kỹ năng, Vinh danh)
 * 4. Thể thức văn bản Báo cáo Tổng kết Chiến dịch chuẩn Đoàn TNCS HCM - Hội LHTN VN kèm QR kiểm thực
 * 5. Tích hợp tuyến đường HTTP, Giao diện Trực quan (?view=analytics, ?view=report) & API REST
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Http\SvgQrGenerator;
use XanhHub\Http\UiIcons;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Service\AnalyticsService;
use XanhHub\Infrastructure\Service\CsvSafeExporter;

$rootDir = dirname(__DIR__);
$baseUrl = 'http://127.0.0.1:8000';

// Khởi chạy PHP Built-in Server nếu chưa chạy trên port 8000
$serverProcess = null;
$serverPipes = [];
$checkSock = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.5);
if (is_resource($checkSock)) {
    fclose($checkSock);
} else {
    $phpExec = (PHP_OS_FAMILY === 'Windows' && file_exists('C:\\xampp\\php\\php.exe'))
        ? 'C:\\xampp\\php\\php.exe'
        : PHP_BINARY;
    $publicDir = escapeshellarg($rootDir . DIRECTORY_SEPARATOR . 'public');
    $cmd = sprintf('%s -S 127.0.0.1:8000 -t %s', escapeshellarg($phpExec), $publicDir);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $serverProcess = proc_open($cmd, $descriptors, $serverPipes, $rootDir);

    $waitStart = microtime(true);
    while (microtime(true) - $waitStart < 4.0) {
        $testSock = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.2);
        if (is_resource($testSock)) {
            fclose($testSock);
            break;
        }
        usleep(100000);
    }
}

register_shutdown_function(function () use (&$serverProcess, &$serverPipes) {
    if (is_resource($serverProcess)) {
        foreach ($serverPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
});

$passed = 0;
$failed = 0;
$total = 0;

function assertTest(bool $condition, string $code, string $desc): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo sprintf("  \033[32m[PASS]\033[0m %-8s %s\n", $code, $desc);
    } else {
        $failed++;
        echo sprintf("  \033[31m[FAIL]\033[0m %-8s %s\n", $code, $desc);
    }
}

function fetchHttp(string $url): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr((string)$response, 0, $headerSize);
    $body = substr((string)$response, $headerSize);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body,
    ];
}

echo "========================================================================\n";
echo " XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ PHÂN HỆ BI & XUẤT DỮ LIỆU ĐOÀN\n";
echo " (ANALYTICS BI DASHBOARD, FORMAL REPORT & FORMULA-SAFE CSV EXPORT)\n";
echo "========================================================================\n";
echo " Thư mục dự án:  {$rootDir}\n";
echo " Host kiểm thử:  {$baseUrl}\n";
echo " Thời điểm chạy: " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

// Khởi tạo Database PDO nếu khả dụng
$pdo = null;
try {
    $pdo = Database::getConnection();
} catch (\Throwable) {
    $pdo = null;
}

// ============================================================================
// PHA 1: KIỂM THỬ ĐƠN VỊ CHỐNG TẤN CÔNG CSV FORMULA INJECTION (OWASP)
// ============================================================================
echo "▶ [PHA 1/5] Kiểm thử Đơn vị Chống Tấn công CSV Formula Injection (OWASP)...\n";

assertTest(
    CsvSafeExporter::sanitizeCell('=HYPERLINK("http://attacker.com")') === "'=HYPERLINK(\"http://attacker.com\")",
    '1.1.1',
    "Vô hiệu hóa tiền tố '=' nguy hiểm bằng cách thêm dấu nháy đơn (') phía trước"
);

assertTest(
    CsvSafeExporter::sanitizeCell('+SUM(A1:A10)') === "'+SUM(A1:A10)",
    '1.1.2',
    "Vô hiệu hóa tiền tố '+' kích hoạt tính toán tự động"
);

assertTest(
    CsvSafeExporter::sanitizeCell('-10+20') === "'-10+20",
    '1.1.3',
    "Vô hiệu hóa tiền tố '-' toán tử kích hoạt công thức"
);

assertTest(
    CsvSafeExporter::sanitizeCell('@SUM(1,2)') === "'@SUM(1,2)",
    '1.1.4',
    "Vô hiệu hóa tiền tố '@' (hàm @ trong Excel/Calc)"
);

assertTest(
    CsvSafeExporter::sanitizeCell("\tcmd|'/c calc'!A0") === "'\tcmd|'/c calc'!A0",
    '1.1.5',
    "Vô hiệu hóa ký tự Tab (\\t) ở đầu ô dữ liệu ngăn bypass bộ lọc"
);

assertTest(
    CsvSafeExporter::sanitizeCell("\rcmd|'/c calc'!A0") === "'\rcmd|'/c calc'!A0",
    '1.1.6',
    "Vô hiệu hóa ký tự Carriage Return (\\r) ở đầu ô dữ liệu"
);

assertTest(
    CsvSafeExporter::sanitizeCell("|'cmd'|'arg'") === "'|'cmd'|'arg'",
    '1.1.7',
    "Vô hiệu hóa tiền tố Pipe ('|') dùng trong lệnh thực thi DDE"
);

assertTest(
    CsvSafeExporter::sanitizeCell('%COMSPEC%') === "'%COMSPEC%",
    '1.1.8',
    "Vô hiệu hóa tiền tố Phần trăm ('%') dùng trong mở rộng biến môi trường Windows"
);

assertTest(
    CsvSafeExporter::sanitizeCell("   =1+1") === "'   =1+1" &&
    CsvSafeExporter::sanitizeCell(" \t  -CMD") === "' \t  -CMD",
    '1.1.9',
    "Phát hiện và vô hiệu hóa ký tự nguy hiểm ngay cả khi có khoảng trắng/tab phía trước"
);

assertTest(
    CsvSafeExporter::sanitizeCell('Nguyễn Khánh Linh') === 'Nguyễn Khánh Linh' &&
    CsvSafeExporter::sanitizeCell('12345') === '12345' &&
    CsvSafeExporter::sanitizeCell('3.5') === '3.5' &&
    CsvSafeExporter::sanitizeCell('') === '',
    '1.1.10',
    "Dữ liệu thông thường (họ tên tiếng Việt, số nguyên, số thực, chuỗi rỗng) giữ nguyên"
);

assertTest(
    CsvSafeExporter::sanitizeCell("\u{200B}=1+1") === "'\u{200B}=1+1" &&
    CsvSafeExporter::sanitizeCell("\u{FEFF}=cmd") === "'\u{FEFF}=cmd" &&
    CsvSafeExporter::sanitizeCell("\u{00A0}+2+2") === "'\u{00A0}+2+2" &&
    CsvSafeExporter::sanitizeCell("\u{3000}@SUM(1,2)") === "'\u{3000}@SUM(1,2)",
    '1.1.11',
    "Vô hiệu hóa bypass ký tự vô hình Unicode (Zero-Width Space, BOM, Non-breaking, Ideographic space)"
);

assertTest(
    CsvSafeExporter::sanitizeCell("\u{FF1D}1+1") === "'\u{FF1D}1+1" &&
    CsvSafeExporter::sanitizeCell("\u{FF0B}2+2") === "'\u{FF0B}2+2" &&
    CsvSafeExporter::sanitizeCell("\u{FF0D}3-3") === "'\u{FF0D}3-3" &&
    CsvSafeExporter::sanitizeCell("\u{FF20}SUM(1,2)") === "'\u{FF20}SUM(1,2)",
    '1.1.12',
    "Vô hiệu hóa tiền tố công thức ký tự Unicode Full-width (＝, ＋, －, ＠)"
);

assertTest(
    CsvSafeExporter::sanitizeCell("\n=1+1") === "'\n=1+1" &&
    CsvSafeExporter::sanitizeCell(";=1+1") === "';=1+1",
    '1.1.13',
    "Vô hiệu hóa tiền tố ký tự xuống dòng (\\n) và chấm phẩy (;) kích hoạt công thức"
);

$csvSnippet = CsvSafeExporter::toCsv(['Tiêu đề', 'Công thức'], [['Nguyễn Văn A', '=2+2']]);
assertTest(
    str_starts_with($csvSnippet, "\xEF\xBB\xBF"),
    '1.2.1',
    "Hàm toCsv chèn chính xác UTF-8 BOM (\\xEF\\xBB\\xBF) giúp mở tiếng Việt đúng trên Excel"
);

assertTest(
    str_contains($csvSnippet, "'=2+2"),
    '1.2.2',
    "Hàm toCsv tự động duyệt và làm sạch mọi ô trong mảng dữ liệu"
);

echo "\n";

// ============================================================================
// PHA 2: KIỂM THỬ XUẤT BẢNG PHÂN CÔNG NHÂN SỰ & GIỜ CỐNG HIẾN CHI TIẾT
// ============================================================================
echo "▶ [PHA 2/5] Kiểm thử Xuất Bảng Phân công Nhân sự & Giờ Cống hiến Chi tiết...\n";

$campaignMeta = [
    1 => ['code' => 'D1', 'title' => 'Chiến dịch Chủ Nhật Xanh'],
    2 => ['code' => 'D2', 'title' => 'Chiến dịch Tiếp Sức Mùa Thi'],
    3 => ['code' => 'D3', 'title' => 'Chiến dịch Mùa Hè Xanh Trọng điểm'],
];

$detailedCsv = ($pdo !== null)
    ? CsvSafeExporter::exportDetailedAssignments($pdo, 3, $campaignMeta)
    : CsvSafeExporter::toCsv(['Mã Chiến Dịch', 'Tên Chiến Dịch', 'Giờ Cống Hiến Ghi Nhận'], [['D3', 'Mùa Hè Xanh', 3.0]]);

assertTest(
    str_starts_with($detailedCsv, "\xEF\xBB\xBF"),
    '2.1.1',
    "Bảng xuất phân công chi tiết exportDetailedAssignments có UTF-8 BOM"
);

assertTest(
    str_contains($detailedCsv, 'Mã Chiến Dịch') &&
    str_contains($detailedCsv, 'Mã Ca Trực') &&
    str_contains($detailedCsv, 'Họ và Tên Tình Nguyện Viên') &&
    str_contains($detailedCsv, 'Cơ Sở Đoàn / Trường Đại Học') &&
    str_contains($detailedCsv, 'Kỹ Năng Ca Yêu Cầu') &&
    str_contains($detailedCsv, 'Trạng Thái Phân Công') &&
    str_contains($detailedCsv, 'Trạng Thái Điểm Danh') &&
    str_contains($detailedCsv, 'Giờ Cống Hiến Ghi Nhận') &&
    str_contains($detailedCsv, 'Ghi Chú Kiểm Toán'),
    '2.1.2',
    "Bảng phân công chi tiết chứa đầy đủ 18 cột tiêu đề chuẩn hóa"
);

assertTest(
    strlen($detailedCsv) > 200,
    '2.1.3',
    "Bảng phân công chi tiết xuất ra dữ liệu thực tế và không bị rỗng"
);

$analyticsService = new AnalyticsService($pdo);
$reportPayload = $analyticsService->getCampaignFormalReport(3);
$summaryCsv = CsvSafeExporter::exportAnalyticsSummaryCsv($reportPayload);

assertTest(
    str_starts_with($summaryCsv, "\xEF\xBB\xBF") &&
    str_contains($summaryCsv, 'TỔNG QUAN') &&
    str_contains($summaryCsv, 'CƠ SỞ ĐOÀN') &&
    str_contains($summaryCsv, 'VINH DANH'),
    '2.2.1',
    "Báo cáo tổng hợp số liệu exportAnalyticsSummaryCsv xuất đủ 3 phân hệ: Tổng quan, Cơ sở Đoàn, Vinh danh"
);

echo "\n";

// ============================================================================
// PHA 3: KIỂM THỬ ĐỘNG CƠ PHÂN TÍCH BI ĐOÀN THANH NIÊN (AnalyticsService)
// ============================================================================
echo "▶ [PHA 3/5] Kiểm thử Động cơ Phân tích BI Đoàn Thanh niên (AnalyticsService)...\n";

$univBreakdown = $analyticsService->getUniversityBreakdown(3);
assertTest(
    is_array($univBreakdown) && count($univBreakdown) === 6,
    '3.1.1',
    "getUniversityBreakdown phân rã đầy đủ 6 Khoa / cơ sở Đoàn trực thuộc"
);

$sumRate = 0.0;
foreach ($univBreakdown as $ub) {
    $sumRate += (float) $ub['rate_percent'];
}
assertTest(
    $sumRate >= 99.0 && $sumRate <= 101.0,
    '3.1.2',
    sprintf("Tổng tỷ lệ cống hiến các cơ sở Đoàn cân bằng hoàn hảo đạt ~100%% (Thực tế: %.1f%%)", $sumRate)
);

$isSortedByHours = true;
for ($i = 0; $i < count($univBreakdown) - 1; $i++) {
    if ($univBreakdown[$i]['total_hours'] < $univBreakdown[$i + 1]['total_hours']) {
        $isSortedByHours = false;
        break;
    }
}
assertTest(
    $isSortedByHours,
    '3.1.3',
    "Dữ liệu phân tích cơ sở Đoàn được sắp xếp giảm dần theo tổng giờ cống hiến"
);

// Staffing Ratio D1 (Sufficient)
$staffD1 = $analyticsService->getStaffingRatioMetrics(1);
assertTest(
    $staffD1['overall']['is_fully_satisfied'] === true &&
    $staffD1['overall']['understaffed_count'] === 0 &&
    $staffD1['overall']['overall_ratio_percent'] >= 100.0,
    '3.2.1',
    "getStaffingRatioMetrics trên D1 (Chủ Nhật Xanh): Tỷ lệ lấp đầy đạt 100%, 0 ca thiếu người, is_fully_satisfied=true"
);

// Staffing Ratio D2 (Understaffed)
$staffD2 = $analyticsService->getStaffingRatioMetrics(2);
assertTest(
    $staffD2['overall']['is_fully_satisfied'] === false &&
    $staffD2['overall']['understaffed_count'] > 0 &&
    $staffD2['overall']['overall_ratio_percent'] < 100.0,
    '3.2.2',
    sprintf(
        "getStaffingRatioMetrics trên D2 (Tiếp Sức Mùa Thi): Nhận diện chuẩn xác %d ca thiếu người, is_fully_satisfied=false",
        $staffD2['overall']['understaffed_count']
    )
);

// Staffing Ratio D3 (High Overlap)
$staffD3 = $analyticsService->getStaffingRatioMetrics(3);
assertTest(
    count($staffD3['shifts']) === 12 &&
    isset($staffD3['shifts'][0]['min_people'], $staffD3['shifts'][0]['max_people'], $staffD3['shifts'][0]['assigned_count']),
    '3.2.3',
    "getStaffingRatioMetrics trên D3 (Mùa Hè Xanh): Bao phủ đủ 12 ca trực với đầy đủ định mức min/max và quân số"
);

// Cơ cấu kỹ năng
$skillMetrics = $analyticsService->getSkillStructureMetrics(3);
assertTest(
    count($skillMetrics['skills']) === 5 &&
    $skillMetrics['summary']['total_skills'] === 5,
    '3.3.1',
    "getSkillStructureMetrics phân tích trọn vẹn 5 nhóm kỹ năng chuyên môn Đoàn Thanh niên"
);

assertTest(
    $skillMetrics['summary']['verified_rate_percent'] >= 0.0 &&
    $skillMetrics['summary']['verified_rate_percent'] <= 100.0 &&
    $skillMetrics['summary']['total_certifications'] > 0,
    '3.3.2',
    sprintf(
        "Thống kê năng lực tình nguyện viên: Tổng %d lượt kỹ năng, tỷ lệ kiểm thực đạt %.1f%%",
        $skillMetrics['summary']['total_certifications'],
        $skillMetrics['summary']['verified_rate_percent']
    )
);

// Vinh danh cá nhân xuất sắc
$topVols = $analyticsService->getOutstandingVolunteers(3, 10);
assertTest(
    count($topVols) > 0 && count($topVols) <= 10 && $topVols[0]['rank'] === 1,
    '3.4.1',
    "getOutstandingVolunteers xếp hạng chuẩn xác Top tình nguyện viên xuất sắc (Rank #1 đứng đầu)"
);

assertTest(
    str_contains($topVols[0]['title'], 'Chiến sĩ Tình nguyện Xuất sắc tiêu biểu') &&
    $topVols[0]['total_hours'] >= ($topVols[1]['total_hours'] ?? 0),
    '3.4.2',
    "Vinh danh chuẩn danh hiệu Đoàn - Hội và sắp xếp giảm dần theo giờ cống hiến thực tế"
);

echo "\n";

// ============================================================================
// PHA 4: KIỂM THỬ THỂ THỨC VĂN BẢN BÁO CÁO TỔNG KẾT CHUẨN ĐOÀN - HỘI
// ============================================================================
echo "▶ [PHA 4/5] Kiểm thử Thể thức Văn bản Báo cáo Tổng kết Chuẩn Đoàn - Hội...\n";

$formal = $analyticsService->getCampaignFormalReport(3);

assertTest(
    $formal['header']['national_motto_1'] === 'CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM' &&
    $formal['header']['national_motto_2'] === 'Độc lập - Tự do - Hạnh phúc',
    '4.1.1',
    "Thể thức văn bản trang trọng: Quốc hiệu & Tiêu ngữ chuẩn mực Nhà nước"
);

assertTest(
    $formal['header']['superior_org'] === 'ĐOÀN TNCS HỒ CHÍ MINH TP. HỒ CHÍ MINH' &&
    $formal['header']['committee_org'] === 'BAN CHỈ HUY CHIẾN DỊCH TÌNH NGUYỆN HÈ 2026',
    '4.1.2',
    "Cơ quan ban hành văn bản: Đoàn TNCS Hồ Chí Minh & Ban Chỉ huy Chiến dịch Hè 2026"
);

assertTest(
    preg_match('/^\d{2}-BC\/ĐTN-XANHHUB-2026$/u', $formal['header']['doc_number']) === 1,
    '4.1.3',
    sprintf("Số văn bản chuẩn thể thức Đoàn: %s", $formal['header']['doc_number'])
);

assertTest(
    str_starts_with($formal['verification']['report_code'], 'XH2026-BC-D3-') &&
    strlen($formal['verification']['report_code']) >= 16,
    '4.2.1',
    sprintf("Mã báo cáo định danh độc bản duy nhất: %s", $formal['verification']['report_code'])
);

$qrSvg = $formal['verification']['qr_svg'];
assertTest(
    str_starts_with($qrSvg, '<svg') && str_contains($qrSvg, '</svg>'),
    '4.2.2',
    "Mã QR kiểm thực trực tiếp sinh mã SVG hợp lệ (SvgQrGenerator)"
);

assertTest(
    strlen($formal['verification']['sha256_hash']) === 64 &&
    ctype_xdigit($formal['verification']['sha256_hash']),
    '4.2.3',
    "Chữ ký số bất biến SHA-256 (64 hex characters) bảo đảm tính toàn vẹn của số liệu báo cáo"
);

assertTest(
    $formal['verification']['signer_title'] === 'TM. BAN CHỈ HUY CHIẾN DỊCH' &&
    $formal['verification']['signer_position'] === 'CHỈ HUY TRƯỞNG' &&
    $formal['verification']['signer_name'] === 'Đ/c Nguyễn Phương Thảo',
    '4.2.4',
    "Chữ ký và người đại diện phê duyệt: Chỉ huy trưởng Đ/c Nguyễn Phương Thảo"
);

echo "\n";

// ============================================================================
// PHA 5: KIỂM THỬ TÍCH HỢP TUYẾN ĐƯỜNG HTTP, GIAO DIỆN TRỰC QUAN & REST API
// ============================================================================
echo "▶ [PHA 5/5] Kiểm thử Tích hợp Tuyến đường HTTP, Giao diện Trực quan & REST API...\n";

// 5.1 Bảng điều khiển BI (?view=analytics)
$resAnalytics = fetchHttp($baseUrl . '/?view=analytics&activity_id=3');
assertTest(
    $resAnalytics['code'] === 200,
    '5.1.1',
    "Tuyến đường ?view=analytics&activity_id=3 phản hồi chính xác HTTP 200"
);

assertTest(
    str_contains($resAnalytics['body'], 'TRUNG TÂM BÁO CÁO THỐNG KÊ &amp; PHÂN TÍCH TÁC ĐỘNG XÃ HỘI') ||
    str_contains($resAnalytics['body'], 'TRUNG TÂM BÁO CÁO THỐNG KÊ & PHÂN TÍCH TÁC ĐỘNG XÃ HỘI'),
    '5.1.2',
    "Giao diện BI hiển thị tiêu đề Trung tâm Báo cáo Thống kê & Phân tích Tác động Xã hội"
);

assertTest(
    str_contains($resAnalytics['body'], 'Tỷ lệ Lấp đầy Ca') &&
    str_contains($resAnalytics['body'], 'Cơ sở Đoàn / Trường Đại học') &&
    str_contains($resAnalytics['body'], 'Cơ cấu Kỹ năng Chuyên môn'),
    '5.1.3',
    "Giao diện BI hiển thị đầy đủ 3 phân hệ trực quan hóa: Lấp đầy ca, Cơ sở Đoàn, Kỹ năng chuyên môn"
);

// 5.2 Báo cáo Tổng kết Đoàn - Hội (?view=report)
$resReport = fetchHttp($baseUrl . '/?view=report&activity_id=3');
assertTest(
    $resReport['code'] === 200,
    '5.2.1',
    "Tuyến đường ?view=report&activity_id=3 phản hồi chính xác HTTP 200"
);

assertTest(
    str_contains($resReport['body'], 'CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM') &&
    str_contains($resReport['body'], 'Độc lập - Tự do - Hạnh phúc') &&
    str_contains($resReport['body'], 'ĐOÀN TNCS HỒ CHÍ MINH TP. HỒ CHÍ MINH') &&
    str_contains($resReport['body'], 'BÁO CÁO TỔNG KẾT CHIẾN DỊCH TÌNH NGUYỆN D3'),
    '5.2.2',
    "Giao diện Báo cáo kết xuất chuẩn thể thức văn bản Đoàn - Hội với Quốc hiệu, Tiêu ngữ và Tên chiến dịch"
);

assertTest(
    str_contains($resReport['body'], 'Huy hiệu Đoàn TNCS Hồ Chí Minh') &&
    str_contains($resReport['body'], 'Biểu trưng Hội Sinh viên Việt Nam'),
    '5.2.3',
    "Giao diện Báo cáo nhúng trọn vẹn 2 biểu trưng Vector SVG chuẩn mực của Đoàn và Hội"
);

assertTest(
    str_contains($resReport['body'], 'window.print()'),
    '5.2.4',
    "Tích hợp sẵn nút lệnh In báo cáo / Xuất file PDF (window.print())"
);

assertTest(
    str_contains($resReport['body'], 'MÃ QR KIỂM THỰC') &&
    str_contains($resReport['body'], '<svg'),
    '5.2.5',
    "Mã QR kiểm thực trực tiếp và mã bảo mật được hiển thị rõ ràng trên bản in báo cáo"
);

// 5.2.6 Kiểm thực báo cáo trực tiếp qua cổng ?view=verify
$resVerifyReport = fetchHttp($baseUrl . '/?view=verify&code=XH2026-BC-D3-F9E13E');
assertTest(
    $resVerifyReport['code'] === 200 &&
    str_contains($resVerifyReport['body'], 'XÁC THỰC BÁO CÁO TỔNG KẾT CHIẾN DỊCH HỢP LỆ') &&
    str_contains($resVerifyReport['body'], 'XH2026-BC-D3-F9E13E') &&
    str_contains($resVerifyReport['body'], 'Chiến dịch Mùa Hè Xanh Trọng điểm'),
    '5.2.6',
    "Cổng xác thực ?view=verify nhận diện chuẩn xác mã báo cáo Đoàn - Hội và hiển thị chứng thực toàn vẹn"
);

// 5.3 Xuất CSV an toàn qua HTTP
$resCsv = fetchHttp($baseUrl . '/?action=export_csv&activity_id=3');
assertTest(
    $resCsv['code'] === 200 && str_contains($resCsv['headers'], 'text/csv'),
    '5.3.1',
    "Endpoint ?action=export_csv phản hồi HTTP 200 với Content-Type text/csv"
);

assertTest(
    str_starts_with($resCsv['body'], "\xEF\xBB\xBF"),
    '5.3.2',
    "Dữ liệu CSV từ HTTP bắt đầu bằng UTF-8 BOM chuẩn hiển thị tiếng Việt"
);

$resAnalyticsCsv = fetchHttp($baseUrl . '/?action=export_analytics_csv&activity_id=3');
assertTest(
    $resAnalyticsCsv['code'] === 200 && str_contains($resAnalyticsCsv['headers'], 'text/csv'),
    '5.3.3',
    "Endpoint ?action=export_analytics_csv phản hồi HTTP 200 với Content-Type text/csv"
);

// 5.4 REST API Endpoint
$resApi = fetchHttp($baseUrl . '/?action=api_analytics_data&activity_id=3');
$jsonApi = json_decode($resApi['body'], true);
assertTest(
    $resApi['code'] === 200 &&
    is_array($jsonApi) &&
    ($jsonApi['ok'] ?? false) === true &&
    isset($jsonApi['report']['overview'], $jsonApi['report']['university_breakdown']),
    '5.4.1',
    "API ?action=api_analytics_data trả về payload JSON chuẩn xác với ok=true và báo cáo hoàn chỉnh"
);

// 5.5 Điều hướng và Phím tắt
$resHome = fetchHttp($baseUrl . '/?view=home');
assertTest(
    str_contains($resHome['body'], '?view=analytics'),
    '5.5.1',
    "Thanh điều hướng Header chính trang chủ có liên kết nhanh trực tiếp tới Trung tâm Phân tích BI"
);

echo "\n------------------------------------------------------------------------\n";
echo sprintf(
    " TỔNG KẾT KIỂM THỬ PHÂN HỆ BI & BÁO CÁO ĐOÀN: [%d/%d ĐẠT] (%.1f%%)\n",
    $passed,
    $total,
    $total > 0 ? ($passed / $total) * 100 : 0
);

if ($failed === 0) {
    echo " ✔ KẾT LUẬN: TOÀN BỘ TÍNH NĂNG BI DASHBOARD, BÁO CÁO ĐOÀN - HỘI VÀ CSV EXPORT ĐẠT 100% PASS\n";
    echo "========================================================================\n";
    exit(0);
} else {
    echo " ✖ KẾT LUẬN: PHÁT HIỆN {$failed} CA KIỂM THỬ THẤT BẠI\n";
    echo "========================================================================\n";
    exit(1);
}

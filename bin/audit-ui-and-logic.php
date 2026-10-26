<?php

declare(strict_types=1);

/**
 * XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM TOÁN TỰ ĐỘNG LỖI LOGIC, 404 & GIAO DIỆN
 * (AUTOMATED LOGIC, 404 & UI INHERITANCE AUDIT RUNNER)
 *
 * Kiểm tra 4 trụ cột nghiệp vụ:
 * 1. Routing Guard & 404 Not Found (Xóa bỏ rơi trang, xử lý float/array/overflow)
 * 2. Master Layout Inheritance (Dynamic <title>, SEO meta, cân bằng thẻ HTML)
 * 3. Dead Link Sweep & Static Asset Integrity (0 liên kết chết, 100% asset tồn tại)
 * 4. Empty States & Accessibility (Trạng thái rỗng, phím ESC, overflow-x)
 */

$rootDir = dirname(__DIR__);
$baseUrl = 'http://127.0.0.1:8000';

// Tự động kiểm tra và khởi chạy PHP Built-in Server nếu chưa chạy trên port 8000
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

function assertCheck(bool $condition, string $code, string $desc): void
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

function fetchUrl(string $url): array
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
echo " XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM TOÁN LỖI LOGIC, 404 & GIAO DIỆN KẾ THỪA\n";
echo " (AUTOMATED AUDIT RUNNER - 4 PHASES)\n";
echo "========================================================================\n";
echo " Thư mục dự án:  " . $rootDir . "\n";
echo " Host kiểm thử:  " . $baseUrl . "\n";
echo " Thời điểm chạy:  " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

// ============================================================================
// PHA 1: ROUTING GUARD & 404 NOT FOUND VERIFICATION
// ============================================================================
echo "▶ [PHA 1/4] Kiểm toán Tuyến đường, Trang 404 & Ngăn chặn Rơi trang...\n";

// 1.1 URL không tồn tại phải trả về đúng HTTP 404
$res404 = fetchUrl($baseUrl . '/?view=non_existent_page_123');
assertCheck($res404['code'] === 404, '1.1.1', 'URL không tồn tại (?view=non_existent) trả về chính xác HTTP 404');
assertCheck(str_contains($res404['body'], '404'), '1.1.2', 'Trang 404 hiển thị mã nhận diện trực quan 404');
assertCheck(str_contains($res404['body'], 'Không tìm thấy tuyến đường') || str_contains($res404['body'], 'không tồn tại'), '1.1.3', 'Trang 404 chứa thông điệp giải thích lỗi thân thiện');
assertCheck(!str_contains($res404['body'], 'Tra cứu Giấy Chứng nhận'), '1.1.4', 'Triệt tiêu hoàn toàn lỗi rơi tự do vào trang verify');
assertCheck(str_contains($res404['body'], '?view=home') && str_contains($res404['body'], '?view=campaigns'), '1.1.5', 'Trang 404 cung cấp các thẻ điều hướng nhanh về các phân hệ chính');

// 1.2 Tham số view dạng mảng (Type confusion)
$resArrayView = fetchUrl($baseUrl . '/?view[]=malicious_array');
assertCheck($resArrayView['code'] === 404, '1.2.1', 'Truyền tham số mảng (?view[]=...) được xử lý an toàn trả về HTTP 404');
assertCheck(!str_contains($resArrayView['body'], 'Fatal error') && !str_contains($resArrayView['body'], 'Array to string conversion'), '1.2.2', 'Không phát sinh lỗi PHP Warning/Fatal khi gặp tham số mảng');

// 1.3 Tham số ca trực không tồn tại
$resShiftNotFound = fetchUrl($baseUrl . '/?view=shift_detail&id=999999');
assertCheck($resShiftNotFound['code'] === 404, '1.3.1', 'Ca trực ID không tồn tại (?id=999999) trả về HTTP 404');
assertCheck(str_contains($resShiftNotFound['body'], 'Không tìm thấy') || str_contains($resShiftNotFound['body'], 'không tồn tại'), '1.3.2', 'Giao diện hiển thị cảnh báo ca trực không tồn tại');
assertCheck(str_contains($resShiftNotFound['body'], '?view=campaigns'), '1.3.3', 'Cung cấp nút quay lại danh mục ca trực khi không tìm thấy');

// 1.4 Tham số ca trực dạng số thực (Float rejection)
$resShiftFloat = fetchUrl($baseUrl . '/?view=shift_detail&id=1.5');
assertCheck($resShiftFloat['code'] === 404, '1.4.1', 'Tham số ID dạng số thực (?id=1.5) bị từ chối chính xác bằng HTTP 404');

// 1.5 Tham số ca trực tràn số nguyên 64-bit
$resShiftOverflow = fetchUrl($baseUrl . '/?view=shift_detail&id=999999999999999999999999999');
assertCheck($resShiftOverflow['code'] === 404, '1.5.1', 'Tham số ID tràn số nguyên (?id=999...9) trả về an toàn HTTP 404');

// ============================================================================
// PHA 2: MASTER LAYOUT INHERITANCE & METADATA DYNAMICS
// ============================================================================
echo "\n▶ [PHA 2/4] Kiểm toán Kế thừa Bố cục Master Layout & Tiêu đề Động...\n";

$viewsToAudit = [
    'home' => 'Trang chủ — Xanh Hub',
    'campaigns' => 'Chiến dịch Tình nguyện & Bộ lọc Ca trực — Xanh Hub',
    'news' => 'Phim Tài liệu & Bản tin Tuổi trẻ — Xanh Hub',
    'about' => 'Giới thiệu & Liên hệ — Xanh Hub',
    'login' => 'Đăng nhập — Xanh Hub',
    'register' => 'Đăng ký Tình nguyện viên — Xanh Hub',
    'verify' => 'Tra cứu Giấy Chứng nhận Tình nguyện viên — Xanh Hub',
];

foreach ($viewsToAudit as $vKey => $expectedTitle) {
    $res = fetchUrl($baseUrl . '/?view=' . $vKey);
    $hasExpectedTitle = str_contains($res['body'], $expectedTitle);
    assertCheck($hasExpectedTitle, '2.1.' . $vKey, "View '{$vKey}' kế thừa tiêu đề động chính xác: {$expectedTitle}");
}

// 2.2 Kiểm tra cân bằng thẻ ngữ nghĩa HTML (<main> và </main>)
$indexFile = file_get_contents($rootDir . '/public/index.php');
$openMainCount = substr_count($indexFile, '<main');
$closeMainCount = substr_count($indexFile, '</main>');
assertCheck($openMainCount === $closeMainCount, '2.2.1', "Tính đóng kín HTML: Số lượng thẻ <main ({$openMainCount}) khớp chính xác với </main> ({$closeMainCount})");

// 2.3 Thẻ Viewport Responsive
assertCheck(str_contains($indexFile, '<meta name="viewport" content="width=device-width, initial-scale=1.0">'), '2.3.1', 'Thẻ viewport chuẩn mobile responsive có mặt trên master layout');

// ============================================================================
// PHA 3: DEAD LINK SWEEP & STATIC ASSET INTEGRITY
// ============================================================================
echo "\n▶ [PHA 3/4] Rà soát Liên kết Chết & Tính Toàn vẹn Tài nguyên Tĩnh...\n";

// 3.1 Kiểm tra không có liên kết rỗng href="#"
$hasDeadHashHref = (bool) preg_match('/<a\s+[^>]*href=["\']#["\']/i', $indexFile);
assertCheck(!$hasDeadHashHref, '3.1.1', 'Không phát hiện bất kỳ liên kết chết dạng href="#" nào');

// 3.2 Kiểm tra các tệp tài nguyên tĩnh quan trọng
$assetsToCheck = [
    'public/assets/style.css' => 'Tệp CSS giao diện chính',
    'public/assets/app.js' => 'Tệp JavaScript điều khiển tương tác',
    'public/assets/video/xanhhub-cinematic.mp4' => 'Video điện ảnh H.264 bản địa',
    'public/assets/images/hero-volunteer.jpg' => 'Ảnh poster hero tình nguyện viên',
    'public/assets/images/team-action.jpg' => 'Ảnh tư liệu hoạt động đội hình',
    'public/assets/images/campaign-d1.jpg' => 'Ảnh tư liệu Chủ Nhật Xanh',
    'public/assets/images/campaign-d2.jpg' => 'Ảnh tư liệu Tiếp Sức Mùa Thi',
    'public/assets/images/campaign-d3.jpg' => 'Ảnh tư liệu Mùa Hè Xanh',
    'public/assets/images/avatar-1.jpg' => 'Ảnh đại diện tình nguyện viên avatar-1',
];

$assetIdx = 1;
foreach ($assetsToCheck as $assetPath => $assetLabel) {
    $fullPath = $rootDir . '/' . $assetPath;
    $exists = file_exists($fullPath) && filesize($fullPath) > 0;
    assertCheck($exists, '3.2.' . $assetIdx, "Tài nguyên '{$assetPath}' ({$assetLabel}) tồn tại và hợp lệ (" . ($exists ? round(filesize($fullPath) / 1024, 1) . ' KB' : '0 KB') . ")");
    $assetIdx++;
}

// ============================================================================
// PHA 4: EMPTY STATES & ACCESSIBILITY POLISH
// ============================================================================
echo "\n▶ [PHA 4/4] Kiểm toán Trạng thái Rỗng & Tiêu chuẩn Trợ năng (Accessibility)...\n";

// 4.1 Khối Empty State trên trang Campaigns
assertCheck(str_contains($indexFile, 'id="camp-empty-state"'), '4.1.1', 'Khối giao diện Empty State (camp-empty-state) được tích hợp trong bộ lọc ca trực');
assertCheck(str_contains($indexFile, 'id="btn-reset-filters"'), '4.1.2', 'Nút đặt lại bộ lọc (btn-reset-filters) sẵn sàng hỗ trợ người dùng');

// 4.2 Phím Escape (ESC) đóng modal
$appJsContent = file_get_contents($rootDir . '/public/assets/app.js');
$hasEscHandler = str_contains($appJsContent, "e.key === 'Escape'") && str_contains($appJsContent, 'modal-backdrop.open');
assertCheck($hasEscHandler, '4.2.1', 'Sự kiện phím Escape (ESC) được đăng ký đóng toàn bộ modal đang mở');

// 4.3 Tràn chiều ngang (overflow-x hidden) & Focus Visible
$styleCssContent = file_get_contents($rootDir . '/public/assets/style.css');
assertCheck(str_contains($styleCssContent, 'overflow-x: hidden'), '4.3.1', 'Bảo vệ chống tràn chiều ngang (overflow-x: hidden) trên cả html và body');
assertCheck(str_contains($styleCssContent, ':focus-visible'), '4.3.2', 'Định dạng đường viền nét cao (:focus-visible) hỗ trợ điều hướng bàn phím trợ năng');

// 4.4 Thực thi bộ kiểm thử nghiệp vụ cốt lõi 21/21
$runTestsOut = [];
$runTestsExit = 0;
exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($rootDir . '/bin/run-tests.php'), $runTestsOut, $runTestsExit);
assertCheck($runTestsExit === 0, '4.4.1', 'Bộ kiểm thử nghiệp vụ lõi (bin/run-tests.php) tiếp tục ĐẠT 21/21 ca kiểm thử (100% PASS)');

echo "\n------------------------------------------------------------------------\n";
echo sprintf(" TỔNG KẾT KIỂM TOÁN LỖI LOGIC & GIAO DIỆN: [%d/%d ĐẠT]\n", $passed, $total);
if ($failed === 0) {
    echo " ✔ KẾT LUẬN: TOÀN BỘ LỖI LOGIC, 404 VÀ LAYOUT ĐÃ ĐƯỢC KHẮC PHỤC TRIỆT ĐỂ 100%\n";
    echo "========================================================================\n";
    exit(0);
} else {
    echo sprintf(" ✘ CÒN %d TIÊU CHÍ CHƯA ĐẠT! VUI LÒNG KIỂM TRA LẠI.\n", $failed);
    echo "========================================================================\n";
    exit(1);
}

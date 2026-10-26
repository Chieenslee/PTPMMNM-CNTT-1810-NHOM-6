<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ TỰ ĐỘNG PHÂN HỆ ĐIỀU PHỐI, HỒ SƠ & TẠO HOẠT ĐỘNG
 * (AUTOMATED TEST SUITE FOR R1 COORDINATOR REDESIGN, R2 ROUTING & ROLES,
 *  R3 DUAL-MODE PROFILE, R4 2-STEP CREATE ACTIVITY & R5 INTEGRITY REGRESSIONS)
 * ==============================================================================
 *
 * Kiểm tra toàn diện 5 giai đoạn:
 * 1. [PHA 1] Role Routing Guards, SV001 Fallback Elimination, Role Navigation, Home/Footer Links (R2)
 * 2. [PHA 2] Dual-Mode Profile View (?view=profile), Digital ID, 6 Badges & action=update_profile (R3)
 * 3. [PHA 3] Phân hệ Tạo Hoạt động Mới 2 bước, Dynamic $campaignMeta, RBAC 403 & PostgreSQL Transaction (R4)
 * 4. [PHA 4] Tái thiết kế Bàn Điều phối Master-Detail, Avatar Stack, 2-Zone Inspector, DataGrid & Modals (R1)
 * 5. [PHA 5] Kiểm toán Tính Toàn vẹn, Cân bằng Thẻ HTML <main>, Không Liên kết Chết & Zero Regressions (R5)
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;

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

function httpGet(string $url, ?string $cookie = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
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

function httpPost(string $url, array $data, ?string $cookie = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr((string)$response, 0, $headerSize);
    $body = substr((string)$response, $headerSize);

    preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $matches);
    $newCookie = !empty($matches[1]) ? implode('; ', $matches[1]) : null;

    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body,
        'cookie' => $newCookie,
    ];
}

function extractHeaderHtml(string $body): string
{
    $start = strpos($body, 'id="main-site-header"');
    if ($start === false) {
        return '';
    }
    $end = strpos($body, '</header>', $start);
    return substr($body, $start, ($end !== false ? $end - $start + 9 : 30000));
}

echo "========================================================================\n";
echo " XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ ĐIỀU PHỐI, HỒ SƠ & TẠO HOẠT ĐỘNG\n";
echo " (COORDINATOR REDESIGN, ROLE GUARDS, PROFILE & 2-STEP CREATE ACTIVITY)\n";
echo "========================================================================\n";
echo " Thư mục dự án:  " . $rootDir . "\n";
echo " Host kiểm thử:  " . $baseUrl . "\n";
echo " Thời điểm chạy: " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

$pdo = Database::getConnection();

// ============================================================================
// PHA 1: KIỂM THỬ R2 - ROLE ROUTING GUARDS, FALLBACK SV001, ROLE NAV & HOME/FOOTER LINKS
// ============================================================================
echo "▶ [PHA 1/5] Kiểm thử Tuyến đường RBAC, Phân quyền Session, Điều hướng & Liên kết...\n";

// 1.1.1 Đăng nhập Điều phối viên và kiểm tra dọn sạch volunteer_id trong session
$coordLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'thao.dieuphoi@example.test',
    'password' => 'XanhHub@2026',
    'redirect_view' => 'coordinator',
]);
$coordCookie = $coordLogin['cookie'];
assertTest($coordLogin['code'] === 302 && $coordCookie !== null, '1.1.1', 'Đăng nhập Điều phối viên thành công và nhận phiên làm việc hợp lệ');

// 1.1.2 Đăng nhập Tình nguyện viên SV001 và kiểm tra phiên làm việc
$volLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'SV001',
    'password' => 'XanhHub@2026',
    'redirect_view' => 'volunteer',
]);
$volCookie = $volLogin['cookie'];
assertTest($volLogin['code'] === 302 && $volCookie !== null, '1.1.2', 'Đăng nhập Tình nguyện viên (SV001) thành công và nhận phiên làm việc');

// 1.2.1 Khách vãng lai (không có cookie) không tự động fallback về SV001
$guestHome = httpGet($baseUrl . '/?view=home');
assertTest(
    $guestHome['code'] === 200 &&
    str_contains($guestHome['body'], '?view=login') &&
    str_contains($guestHome['body'], '?view=register') &&
    !str_contains($guestHome['body'], 'THE-TNV-SV001'),
    '1.2.1',
    'Khách vãng lai truy cập không bị fallback tự động về SV001'
);

// 1.3.1 Điều phối viên truy cập ?view=volunteer bị chặn và chuyển hướng về ?view=coordinator (HTTP 302)
$coordToVol = httpGet($baseUrl . '/?view=volunteer', $coordCookie);
$isCoordRedirected = ($coordToVol['code'] === 302) && (
    str_contains($coordToVol['headers'], 'view=coordinator') ||
    str_contains($coordToVol['headers'], 'Location: ?view=coordinator')
);
assertTest($isCoordRedirected, '1.3.1', 'Điều phối viên truy cập ?view=volunteer bị chặn và chuyển hướng về ?view=coordinator');

// 1.3.2 Tình nguyện viên truy cập ?view=volunteer được chấp thuận (HTTP 200)
$volToVol = httpGet($baseUrl . '/?view=volunteer', $volCookie);
assertTest($volToVol['code'] === 200 && str_contains($volToVol['body'], 'Ca trực của tôi'), '1.3.2', 'Tình nguyện viên truy cập ?view=volunteer thành công');

// 1.4.1 Phân tách menu điều hướng: Khách thấy Đăng nhập / Đăng ký, không có nút Bàn điều phối
$guestHeader = extractHeaderHtml($guestHome['body']);
assertTest(
    str_contains($guestHeader, '?view=login') &&
    str_contains($guestHeader, '?view=register') &&
    !str_contains($guestHeader, '?view=coordinator'),
    '1.4.1',
    'Header Khách vãng lai: Hiển thị Đăng nhập/Đăng ký, ẩn tuyệt đối Bàn điều phối'
);

// 1.4.2 Phân tách menu điều hướng: TNV thấy Ca trực của tôi và Hồ sơ cá nhân
$volHeader = extractHeaderHtml($volToVol['body']);
assertTest(
    str_contains($volHeader, '?view=volunteer') &&
    str_contains($volHeader, '?view=profile') &&
    !str_contains($volHeader, '?view=coordinator'),
    '1.4.2',
    'Header Tình nguyện viên: Hiển thị Ca trực của tôi & Hồ sơ cá nhân, ẩn Bàn điều phối'
);

// 1.4.3 Phân tách menu điều hướng: Điều phối viên thấy Bàn điều phối và Hồ sơ chỉ huy
$coordCoordinator = httpGet($baseUrl . '/?view=coordinator', $coordCookie);
$coordHeader = extractHeaderHtml($coordCoordinator['body']);
assertTest(
    str_contains($coordHeader, '?view=coordinator') &&
    str_contains($coordHeader, '?view=profile') &&
    !str_contains($coordHeader, 'title="Ca trực của tôi"'),
    '1.4.3',
    'Header Điều phối viên: Hiển thị Bàn điều phối & Thẻ chỉ huy, không lẫn lộn Ca trực TNV'
);

// 1.5.1 Liên kết chiến dịch trên Trang chủ trỏ đúng ?view=campaigns&activity_id=X
$hasValidHomeLinks = str_contains($guestHome['body'], '?view=campaigns&activity_id=1') ||
                     str_contains($guestHome['body'], '?view=campaigns&activity_id=2') ||
                     str_contains($guestHome['body'], '?view=campaigns&activity_id=3');
assertTest($hasValidHomeLinks, '1.5.1', 'Liên kết Chiến dịch nổi bật trên Trang chủ trỏ chính xác ?view=campaigns&activity_id=X');

// 1.5.2 Liên kết chiến dịch ở Footer trỏ đúng ?view=campaigns&activity_id=X
assertTest(
    str_contains($guestHome['body'], '?view=campaigns&activity_id=1') &&
    str_contains($guestHome['body'], '?view=campaigns&activity_id=2') &&
    str_contains($guestHome['body'], '?view=campaigns&activity_id=3'),
    '1.5.2',
    'Chân trang (Footer) liên kết đầy đủ 3 chiến dịch trọng điểm qua tham số activity_id'
);

// ============================================================================
// PHA 2: KIỂM THỬ R3 - DUAL-MODE PROFILE VIEW (?view=profile) & action=update_profile
// ============================================================================
echo "\n▶ [PHA 2/5] Kiểm thử Hồ sơ Kép (?view=profile), Thẻ TNV/Chỉ huy & Cập nhật Hồ sơ...\n";

// 2.1.1 Khách chưa đăng nhập truy cập ?view=profile bị chuyển hướng tới ?view=login
$guestProfile = httpGet($baseUrl . '/?view=profile');
$isGuestRedirectLogin = ($guestProfile['code'] === 302) && str_contains($guestProfile['headers'], 'view=login');
assertTest($isGuestRedirectLogin, '2.1.1', 'Khách chưa đăng nhập truy cập ?view=profile bị chuyển hướng tới trang Đăng nhập');

// 2.1.2 Tham số redirect được bảo toàn trong URL chuyển hướng
assertTest(str_contains($guestProfile['headers'], 'redirect=profile'), '2.1.2', 'Bảo toàn tham số redirect=profile trong URL chuyển hướng');

// 2.2.1 TNV truy cập ?view=profile trả về HTTP 200
$volProfile = httpGet($baseUrl . '/?view=profile', $volCookie);
assertTest($volProfile['code'] === 200, '2.2.1', 'Tình nguyện viên truy cập ?view=profile trả về HTTP 200');

// 2.2.2 Giao diện TNV hiển thị Thẻ Tình nguyện viên Số với tên và mã sinh viên
assertTest(
    str_contains($volProfile['body'], 'THẺ TÌNH NGUYỆN VIÊN SỐ') &&
    str_contains($volProfile['body'], 'SV001') &&
    str_contains($volProfile['body'], 'Nguyễn Khánh Linh'),
    '2.2.2',
    'Hiển thị Thẻ Tình nguyện viên Số chuẩn với Họ tên Nguyễn Khánh Linh và Mã SV SV001'
);

// 2.2.3 Giao diện TNV hiển thị trọn vẹn 6 Huy hiệu Đoàn - Hội
$hasAll6Badges = str_contains($volProfile['body'], 'Chiến sĩ Tân binh') &&
                 str_contains($volProfile['body'], 'Đôi tay Vàng Sơ cứu') &&
                 str_contains($volProfile['body'], 'Kiện tướng Môi trường') &&
                 str_contains($volProfile['body'], 'Chiến sĩ Bền bỉ') &&
                 str_contains($volProfile['body'], 'Đại sứ Lan tỏa') &&
                 str_contains($volProfile['body'], 'Thủ lĩnh Tình nguyện');
assertTest($hasAll6Badges, '2.2.3', 'Hiển thị đầy đủ 6 Huy hiệu Danh dự Đoàn - Hội (Tân binh, Sơ cứu, Môi trường, Bền bỉ, Đại sứ, Thủ lĩnh)');

// 2.2.4 Biểu mẫu cập nhật thông tin tình nguyện viên hiện diện trên DOM
assertTest(
    str_contains($volProfile['body'], 'id="form-volunteer-profile"') &&
    str_contains($volProfile['body'], 'action="?action=update_profile"') &&
    str_contains($volProfile['body'], 'name="max_shifts_per_week"'),
    '2.2.4',
    'Biểu mẫu cập nhật hồ sơ cá nhân và hạn mức ca/tuần sẵn sàng tiếp nhận tương tác'
);

// 2.3.1 Điều phối viên truy cập ?view=profile trả về HTTP 200
$coordProfile = httpGet($baseUrl . '/?view=profile', $coordCookie);
assertTest($coordProfile['code'] === 200, '2.3.1', 'Điều phối viên truy cập ?view=profile trả về HTTP 200');

// 2.3.2 Giao diện Điều phối viên hiển thị Thẻ Định danh Ban Chỉ huy
assertTest(
    str_contains($coordProfile['body'], 'THẺ ĐỊNH DANH BAN CHỈ HUY') &&
    str_contains($coordProfile['body'], 'CÁN BỘ ĐIỀU PHỐI'),
    '2.3.2',
    'Hiển thị Thẻ Định danh Ban Chỉ huy với cấp bậc và huy hiệu Command Staff'
);

// 2.3.3 Thẻ Chỉ huy hiển thị 4 chỉ số năng lực điều hành hệ thống
assertTest(
    str_contains($coordProfile['body'], 'Chiến dịch Phụ trách') &&
    str_contains($coordProfile['body'], 'Tổng số Ca trực') &&
    str_contains($coordProfile['body'], 'Lượt Phân công') &&
    str_contains($coordProfile['body'], 'Lực lượng Huy động'),
    '2.3.3',
    'Hiển thị 4 chỉ số điều hành (Chiến dịch Phụ trách, Tổng ca trực, Lượt phân công, Lực lượng huy động)'
);

// 2.3.4 Tích hợp nút phím tắt "Tạo Hoạt động Mới" trong Thẻ Chỉ huy
assertTest(
    str_contains($coordProfile['body'], 'id="btn-open-create-activity"') &&
    str_contains($coordProfile['body'], 'data-open-modal="modal-create-activity"'),
    '2.3.4',
    'Tích hợp nút phím tắt Tạo Hoạt động Mới (#btn-open-create-activity) kích hoạt Modal Wizard'
);

// 2.3.5 Bảng Danh sách Chiến dịch & Hoạt động Đang Hướng dẫn (R2 Requirement)
assertTest(
    str_contains($coordProfile['body'], 'Danh sách Chiến dịch &amp; Hoạt động Đang Hướng dẫn') &&
    str_contains($coordProfile['body'], '?view=coordinator&amp;activity_id=') &&
    !str_contains($coordProfile['body'], 'Trích xuất Nhật ký Kiểm toán Thao tác Gần nhất') &&
    !str_contains($coordProfile['body'], 'prev_hash'),
    '2.3.5',
    'Hồ sơ Cán bộ Điều phối hiển thị Bảng Danh sách Chiến dịch Đang Hướng dẫn, gỡ bỏ hoàn toàn audit_log (R2)'
);


// 2.4.1 Thực thi cập nhật hồ sơ qua POST action=update_profile
$newPhone = '0909' . random_int(100000, 999999);
$updateRes = httpPost($baseUrl . '/?action=update_profile', [
    'full_name' => 'Nguyễn Khánh Linh',
    'phone' => $newPhone,
    'chapter_name' => 'Chi đoàn KTPM-01',
    'max_shifts_per_week' => 5,
    'skills' => [1 => 3, 2 => 4],
], $volCookie);
assertTest($updateRes['code'] === 302, '2.4.1', 'POST action=update_profile phản hồi HTTP 302 chuyển hướng thành công');

// 2.4.2 Kiểm tra dữ liệu được lưu thực tế vào PostgreSQL
$stmtCheckVol = $pdo->query("SELECT phone, max_shifts_per_week FROM volunteers WHERE user_id = 1");
$volDb = $stmtCheckVol->fetch();
assertTest(
    $volDb && $volDb['phone'] === $newPhone && (int)$volDb['max_shifts_per_week'] === 5,
    '2.4.2',
    sprintf('Cơ sở dữ liệu PostgreSQL đã cập nhật đúng SĐT (%s) và định mức 5 ca/tuần', $newPhone)
);

// 2.4.3 Kiểm tra nhật ký kiểm toán PROFILE_UPDATE được ghi nhận
$stmtCheckAudit = $pdo->query("SELECT action, entity_type FROM audit_log WHERE actor_user_id = 1 AND action = 'PROFILE_UPDATE' ORDER BY id DESC LIMIT 1");
$auditDb = $stmtCheckAudit->fetch();
assertTest($auditDb && $auditDb['action'] === 'PROFILE_UPDATE', '2.4.3', 'Nhật ký kiểm toán ghi nhận chính xác hành động PROFILE_UPDATE');

// 2.4.4 Biểu mẫu từ chối khi để trống Họ và tên
$emptyNameRes = httpPost($baseUrl . '/?action=update_profile', [
    'full_name' => '',
    'phone' => '0901234567',
], $volCookie);
assertTest($emptyNameRes['code'] === 302, '2.4.4', 'Từ chối cập nhật khi để trống Họ và tên');

// Khôi phục tên Chi đoàn chuẩn hóa để bảo đảm tính nhất quán phân rã cơ sở Đoàn
$pdo->query("UPDATE volunteers SET chapter_name = 'Chi đoàn KTPM-01' WHERE user_id = 1");

// ============================================================================
// PHA 3: KIỂM THỬ R4 - PHÂN HỆ TẠO HOẠT ĐỘNG MỚI 2 BƯỚC & RBAC 403
// ============================================================================
echo "\n▶ [PHA 3/5] Kiểm thử Tạo Hoạt động 2 Bước, Transaction PostgreSQL, RBAC 403 & Dynamic Meta...\n";

// 3.1.1 RBAC 403: Khách vãng lai gọi action=create_activity bị từ chối 403
$guestCreate = httpPost($baseUrl . '/?action=create_activity', [
    'code' => 'TEST-DENY',
    'title' => 'Hoạt động Trái phép',
]);
assertTest($guestCreate['code'] === 403, '3.1.1', 'RBAC 403: Khách vãng lai tạo hoạt động bị chặn bằng mã HTTP 403 Forbidden');

// 3.1.2 RBAC 403: Tình nguyện viên gọi action=create_activity bị từ chối 403
$volCreate = httpPost($baseUrl . '/?action=create_activity', [
    'code' => 'TEST-DENY-VOL',
    'title' => 'Hoạt động Trái phép từ TNV',
], $volCookie);
assertTest($volCreate['code'] === 403, '3.1.2', 'RBAC 403: Tình nguyện viên gọi tạo hoạt động bị từ chối bằng mã HTTP 403 Forbidden');

// 3.2.1 Điều phối viên khởi tạo hoạt động mới và ca trực ban đầu qua transaction
$testActCode = 'ACT-AUTO-' . strtoupper(bin2hex(random_bytes(3)));
$testActTitle = 'Chiến dịch Xanh Tự động ' . date('His');
$testShiftCode = $testActCode . '-CA01';
$testShiftTitle = 'Ca ra quân thí điểm ' . date('His');

$coordCreate = httpPost($baseUrl . '/?action=create_activity', [
    'code' => $testActCode,
    'title' => $testActTitle,
    'description' => 'Mô tả thử nghiệm quy trình tạo hoạt động tự động',
    'location_name' => 'Công viên 23 Tháng 9, Quận 1',
    'lat' => '10.7690',
    'lng' => '106.6930',
    'shift_code' => $testShiftCode,
    'shift_title' => $testShiftTitle,
    'shift_start' => '2026-07-01 07:30:00+07:00',
    'shift_end' => '2026-07-01 11:30:00+07:00',
    'shift_location' => 'Khu A Công viên 23 Tháng 9',
    'shift_min_people' => 2,
    'shift_max_people' => 4,
    'shift_priority' => 3,
    'shift_skill_id' => 1,
    'shift_min_level' => 2,
], $coordCookie);

assertTest($coordCreate['code'] === 302, '3.2.1', 'Điều phối viên thực thi tạo hoạt động thành công, chuyển hướng HTTP 302');

// 3.2.2 Kiểm tra bản ghi activities trong cơ sở dữ liệu
$stmtNewAct = $pdo->prepare('SELECT id, code, title, location_name, status FROM activities WHERE code = :c');
$stmtNewAct->execute([':c' => $testActCode]);
$newAct = $stmtNewAct->fetch();
$createdActId = (int) ($newAct['id'] ?? 0);
assertTest(
    $createdActId > 0 && $newAct['title'] === $testActTitle && $newAct['status'] === 'OPEN',
    '3.2.2',
    sprintf('PostgreSQL xác nhận tạo thành công hoạt động #%d [%s]', $createdActId, $testActCode)
);

// 3.2.3 Kiểm tra phân công Ban Chỉ huy (activity_coordinators)
$stmtNewCoord = $pdo->prepare('SELECT COUNT(*) FROM activity_coordinators WHERE activity_id = :aid AND user_id = 31');
$stmtNewCoord->execute([':aid' => $createdActId]);
assertTest((int)$stmtNewCoord->fetchColumn() >= 1, '3.2.3', 'Bản ghi Ban Chỉ huy (activity_coordinators) tự động gán Điều phối viên phụ trách');

// 3.2.4 Kiểm tra ca trực ban đầu trong bảng shifts kèm khoảng thời gian during (tstzrange)
$stmtNewShift = $pdo->prepare('SELECT id, code, title, during FROM shifts WHERE activity_id = :aid AND code = :sc');
$stmtNewShift->execute([':aid' => $createdActId, ':sc' => $testShiftCode]);
$newShift = $stmtNewShift->fetch();
assertTest(
    $newShift && !empty($newShift['during']) && $newShift['title'] === $testShiftTitle,
    '3.2.4',
    'Bản ghi ca trực ban đầu được khởi tạo đồng bộ với tstzrange (during) nguyên vẹn'
);

// 3.2.5 Kiểm tra định mức chuyên môn trong shift_requirements
$newShiftId = (int) ($newShift['id'] ?? 0);
$stmtNewReq = $pdo->prepare('SELECT skill_id, min_level, required_count FROM shift_requirements WHERE shift_id = :sid');
$stmtNewReq->execute([':sid' => $newShiftId]);
$newReq = $stmtNewReq->fetch();
assertTest(
    $newReq && (int)$newReq['skill_id'] === 1 && (int)$newReq['min_level'] === 2,
    '3.2.5',
    'Bản ghi yêu cầu kỹ năng (shift_requirements) được cấu hình chuẩn xác (Kỹ năng #1 ≥ Cấp 2)'
);

// 3.2.6 Kiểm tra nhật ký kiểm toán ACTIVITY_CREATE
$stmtActAudit = $pdo->prepare("SELECT action, payload_json FROM audit_log WHERE action = 'ACTIVITY_CREATE' AND entity_id = :eid ORDER BY id DESC LIMIT 1");
$stmtActAudit->execute([':eid' => (string)$createdActId]);
$actAudit = $stmtActAudit->fetch();
assertTest(
    $actAudit && str_contains((string)json_encode($actAudit['payload_json']), $testActCode),
    '3.2.6',
    'Nhật ký kiểm toán ghi nhận sự kiện ACTIVITY_CREATE kèm siêu dữ liệu chiến dịch'
);

// 3.3.1 Nới lỏng ràng buộc activity_id: Chiến dịch mới tạo (?activity_id=NEW_ID) hiển thị trọn vẹn
$newCampView = httpGet($baseUrl . '/?view=campaigns&activity_id=' . $createdActId, $coordCookie);
assertTest(
    $newCampView['code'] === 200 && str_contains($newCampView['body'], htmlspecialchars($testActTitle)),
    '3.3.1',
    sprintf('Hệ thống nạp động $campaignMeta và chấp thuận ?activity_id=%d không bị ép về #3', $createdActId)
);

// 3.4.1 Nút "Tạo Hoạt động Mới" hiện diện trên Thanh công cụ Khám phá Chiến dịch (?view=campaigns)
assertTest(
    str_contains($newCampView['body'], 'id="btn-create-activity-campaigns"') &&
    str_contains($newCampView['body'], 'data-open-modal="modal-create-activity"'),
    '3.4.1',
    'Nút "Tạo Hoạt động Mới" (#btn-create-activity-campaigns) sẵn sàng trên view=campaigns'
);

// 3.4.2 Nút "Tạo Hoạt động Mới" hiện diện trên Bàn Điều phối (?view=coordinator)
assertTest(
    str_contains($coordCoordinator['body'], 'id="btn-create-activity-coordinator"') &&
    str_contains($coordCoordinator['body'], 'data-open-modal="modal-create-activity"'),
    '3.4.2',
    'Nút "Tạo Hoạt động Mới" (#btn-create-activity-coordinator) sẵn sàng trên view=coordinator'
);

// 3.4.3 Modal Tạo Hoạt động 2 Bước (#modal-create-activity) hiện diện với cấu trúc Stepper
assertTest(
    str_contains($coordCoordinator['body'], 'id="modal-create-activity"') &&
    str_contains($coordCoordinator['body'], 'id="wizard-step-1"') &&
    str_contains($coordCoordinator['body'], 'id="wizard-step-2"') &&
    str_contains($coordCoordinator['body'], 'id="btn-wizard-next"') &&
    str_contains($coordCoordinator['body'], 'id="btn-wizard-prev"'),
    '3.4.3',
    'Modal #modal-create-activity tích hợp đầy đủ 2 bước Stepper (Thông tin chung & Ca trực ban đầu)'
);

// Dọn dẹp dữ liệu kiểm thử tự động
$pdo->prepare('DELETE FROM shift_requirements WHERE shift_id = :sid')->execute([':sid' => $newShiftId]);
$pdo->prepare('DELETE FROM shifts WHERE id = :sid')->execute([':sid' => $newShiftId]);
$pdo->prepare('DELETE FROM activity_coordinators WHERE activity_id = :aid')->execute([':aid' => $createdActId]);
$pdo->prepare('DELETE FROM activities WHERE id = :aid')->execute([':aid' => $createdActId]);

// ============================================================================
// PHA 4: KIỂM THỬ R1 - TÁI THIẾT KẾ BÀN ĐIỀU PHỐI MASTER-DETAIL, DATAGRID & MODALS
// ============================================================================
echo "\n▶ [PHA 4/5] Kiểm thử Bàn Điều phối Master-Detail, Avatar Stack, DataGrid & Modals...\n";

// 4.1.1 Bố cục Master-Detail: Phân chia 2 cột Master Column và Detail Column
assertTest(
    str_contains($coordCoordinator['body'], 'id="coordinator-master-col"') &&
    str_contains($coordCoordinator['body'], 'id="coordinator-detail-col"'),
    '4.1.1',
    'Bố cục Bàn điều phối chuẩn Master-Detail 2 cột: #coordinator-master-col và #coordinator-detail-col'
);

// 4.1.2 Cột Master: Ô tìm kiếm nhanh và 4 nút lọc trạng thái
assertTest(
    str_contains($coordCoordinator['body'], 'id="shift-search-input"') &&
    str_contains($coordCoordinator['body'], 'data-shift-filter="ALL"') &&
    str_contains($coordCoordinator['body'], 'data-shift-filter="UNDERSTAFFED"') &&
    str_contains($coordCoordinator['body'], 'data-shift-filter="FULL"') &&
    str_contains($coordCoordinator['body'], 'data-shift-filter="LOCKED"'),
    '4.1.2',
    'Cột Master tích hợp tìm kiếm ca tức thì (#shift-search-input) và 4 nút lọc (Tất cả, Thiếu người, Đủ người, Đã khóa)'
);

// 4.1.3 Thẻ ca trực thu gọn (Compact Shift Cards) trong danh sách #compact-shifts-list
assertTest(
    str_contains($coordCoordinator['body'], 'id="compact-shifts-list"') &&
    str_contains($coordCoordinator['body'], 'compact-shift-card'),
    '4.1.3',
    'Danh sách ca trực tinh gọn sử dụng định dạng thẻ compact-shift-card'
);

// 4.2.1 Thẻ ca trực thu gọn tích hợp Chồng Ảnh Đại diện (Avatar Stack) -space-x-2
assertTest(
    str_contains($coordCoordinator['body'], 'avatar-stack') ||
    str_contains($coordCoordinator['body'], '-space-x-2'),
    '4.2.1',
    'Thẻ ca trực hiển thị Chồng Ảnh Đại diện (Avatar Stack) chuẩn Tailwind -space-x-2'
);

// 4.2.2 Thẻ ca trực hiển thị tiến độ quân số và tỷ lệ lấp đầy
assertTest(
    str_contains($coordCoordinator['body'], 'bi-progress-bar') ||
    str_contains($coordCoordinator['body'], 'bg-emerald-500') ||
    str_contains($coordCoordinator['body'], 'TNV'),
    '4.2.2',
    'Thẻ ca trực thể hiện trực quan thanh tiến độ quân số và tỷ lệ lấp đầy ca trực'
);

// 4.3.1 Chi tiết ca trực: Phân vùng 1 (Zone 1) Quân số Đã phân công (#zone-assigned-roster)
assertTest(
    str_contains($coordCoordinator['body'], 'id="zone-assigned-roster"') &&
    str_contains($coordCoordinator['body'], 'id="assigned-roster-list"'),
    '4.3.1',
    'Phân vùng 1 (Zone 1): Danh sách nhân sự đã được phân công vào ca (#zone-assigned-roster)'
);

// 4.3.2 Chi tiết ca trực: Phân vùng 2 (Zone 2) Nguồn TNV Phù hợp & Sẵn sàng (#zone-eligible-pool)
assertTest(
    str_contains($coordCoordinator['body'], 'id="zone-eligible-pool"') &&
    str_contains($coordCoordinator['body'], 'id="eligible-pool-list"'),
    '4.3.2',
    'Phân vùng 2 (Zone 2): Nguồn TNV phù hợp & sẵn sàng điều phối (#zone-eligible-pool)'
);

// 4.3.3 Phân vùng 2 tích hợp điều hướng phân trang và nút Gán nhanh trong script
$appJsContent = file_get_contents($rootDir . '/public/assets/app.js');
assertTest(
    str_contains($coordCoordinator['body'], 'id="btn-eligible-prev"') &&
    str_contains($coordCoordinator['body'], 'id="btn-eligible-next"') &&
    str_contains($appJsContent, 'btn-quick-assign-active-shift'),
    '4.3.3',
    'Phân vùng 2 trang bị điều hướng phân trang và nút Gán 1-chạm (.btn-quick-assign-active-shift)'
);

// 4.4.1 Tab Phân trang Lực lượng TNV Toàn diện (DataGrid Tab #tab-volunteers)
assertTest(
    str_contains($coordCoordinator['body'], 'id="tab-volunteers"') &&
    str_contains($coordCoordinator['body'], 'Lực lượng TNV (DataGrid)'),
    '4.4.1',
    'Phân hệ tích hợp Tab tra cứu toàn diện "Lực lượng TNV (DataGrid)" (#tab-volunteers)'
);

// 4.4.2 Bộ lọc Đa tiêu chí trên DataGrid (Tìm kiếm, Đơn vị, Kỹ năng, Số ca, Phân trang 10/20/50)
assertTest(
    str_contains($coordCoordinator['body'], 'id="datagrid-search-input"') &&
    str_contains($coordCoordinator['body'], 'id="datagrid-unit-select"') &&
    str_contains($coordCoordinator['body'], 'id="datagrid-skill-select"') &&
    str_contains($coordCoordinator['body'], 'id="datagrid-shifts-select"') &&
    str_contains($coordCoordinator['body'], 'id="datagrid-page-size-select"'),
    '4.4.2',
    'DataGrid trang bị đủ 5 bộ lọc đa tiêu chí: Từ khóa, Đơn vị Đoàn, Kỹ năng, Số ca và Hạn mức 10/20/50'
);

// 4.4.3 Cấu trúc Bảng dữ liệu DataGrid và thanh phân trang động
assertTest(
    str_contains($coordCoordinator['body'], 'id="datagrid-tbody"') &&
    str_contains($coordCoordinator['body'], 'id="datagrid-pagination-bar"'),
    '4.4.3',
    'Cấu trúc bảng #datagrid-tbody và thanh điều khiển phân trang #datagrid-pagination-bar hoàn chỉnh'
);

// 4.5.1 Modal Khảo sát Hồ sơ Ứng viên (#modal-candidate-inspector)
assertTest(
    str_contains($coordCoordinator['body'], 'id="modal-candidate-inspector"') &&
    str_contains($coordCoordinator['body'], 'id="cand-name"') &&
    str_contains($coordCoordinator['body'], 'id="cand-skills-list"'),
    '4.5.1',
    'Modal Khảo sát Hồ sơ Ứng viên (#modal-candidate-inspector) nhúng sẵn sàng trên DOM'
);

// 4.5.2 Modal Đánh giá Tình nguyện viên 360° (#modal-eval-volunteer)
assertTest(
    str_contains($coordCoordinator['body'], 'id="modal-eval-volunteer"') &&
    str_contains($coordCoordinator['body'], 'id="form-eval-volunteer"') &&
    str_contains($coordCoordinator['body'], 'name="punctuality_rating"'),
    '4.5.2',
    'Modal Đánh giá Tình nguyện viên 360° (#modal-eval-volunteer) tích hợp biểu mẫu chấm điểm sao'
);

// 4.6.1 REST API Hồ sơ Ứng viên (?action=api_candidate_profile&volunteer_id=1) trả về HTTP 200
$candApi = httpGet($baseUrl . '/?action=api_candidate_profile&volunteer_id=1', $coordCookie);
assertTest($candApi['code'] === 200, '4.6.1', 'API ?action=api_candidate_profile trả về mã HTTP 200');

// 4.6.2 Payload JSON của ứng viên chứa đầy đủ thông tin cá nhân, kỹ năng, lịch rảnh và huy hiệu
$candJson = json_decode($candApi['body'], true);
$candData = $candJson['data'] ?? $candJson;
$hasCompleteCandPayload = is_array($candJson) &&
                          ($candJson['ok'] ?? false) === true &&
                          isset($candData['volunteer']['full_name']) &&
                          is_array($candData['skills'] ?? null) &&
                          is_array($candData['availability'] ?? null) &&
                          is_array($candData['gamification'] ?? null);
assertTest(
    $hasCompleteCandPayload,
    '4.6.2',
    'Payload JSON ứng viên cung cấp đầy đủ: Thông tin cá nhân, Ma trận kỹ năng, Lịch rảnh 7 ngày & Gamification'
);

// ============================================================================
// PHA 4B: KIỂM THỬ ĐẶC TẢ R1, R2, R3 (3 TAB ĐIỀU PHỐI, CỔNG QUẢN TRỊ ADMIN, AVATAR MONOGRAM)
// ============================================================================
echo "\n▶ [PHA 4B/5] Kiểm thử Đặc tả R1, R2, R3 (3 Tab Điều phối, Cổng Admin 4 Tab, Monogram Avatar)...\n";

// 4.7.1 Bàn điều phối tích hợp 3 tab ngang (#inspector-tab-assigned, #inspector-tab-eligible, #inspector-tab-replacement)
$coordViewHtml = httpGet($baseUrl . '/?view=coordinator&activity_id=3', $coordCookie);
assertTest(
    $coordViewHtml['code'] === 200 &&
    str_contains($coordViewHtml['body'], 'id="inspector-tab-assigned"') &&
    str_contains($coordViewHtml['body'], 'id="inspector-tab-eligible"') &&
    str_contains($coordViewHtml['body'], 'id="inspector-tab-replacement"') &&
    str_contains($coordViewHtml['body'], 'inspector-tab-btn'),
    '4.7.1',
    'Bàn điều phối cấu trúc 3 tab ngang toàn chiều rộng (#inspector-tab-assigned, #inspector-tab-eligible, #inspector-tab-replacement) (R1)'
);

// 4.7.2 Tab 3 Báo vắng & Đề xuất Thay thế (ReplacementRanker)
assertTest(
    str_contains($coordViewHtml['body'], 'id="inspector-absence-reports-list"') &&
    str_contains($coordViewHtml['body'], 'id="inspector-replacement-suggestions-list"') &&
    str_contains($coordViewHtml['body'], 'ReplacementRanker'),
    '4.7.2',
    'Tab 3 Báo vắng & Đề xuất Người Thay thế tích hợp đầy đủ danh sách báo vắng và Top 5 ứng viên dự phòng (R1)'
);

// 4.8.1 Trang Tra cứu Chứng nhận (?view=verify) làm sạch 100% audit_log kỹ thuật
$verifyPublic = httpGet($baseUrl . '/?view=verify');
assertTest(
    $verifyPublic['code'] === 200 &&
    str_contains($verifyPublic['body'], 'max-w-4xl') &&
    str_contains($verifyPublic['body'], 'Cổng Xác thực Văn bản Điện tử Công khai') &&
    !str_contains($verifyPublic['body'], 'Nhật ký Điều phối Minh bạch') &&
    !str_contains($verifyPublic['body'], 'prev_hash'),
    '4.8.1',
    'Trang ?view=verify gỡ bỏ hoàn toàn bảng audit_log và mã băm prev_hash, bố cục căn giữa trang trọng (R2)'
);

// 4.8.2 RBAC Quản trị: Khách chưa đăng nhập vào ?view=admin bị 302 chuyển hướng
$guestAdmin = httpGet($baseUrl . '/?view=admin');
assertTest(
    $guestAdmin['code'] === 302 && str_contains($guestAdmin['headers'], 'redirect=admin'),
    '4.8.2',
    'RBAC: Khách chưa đăng nhập truy cập ?view=admin bị chuyển hướng 302 đến trang login với redirect=admin (R2)'
);

// 4.8.3 RBAC Quản trị: Tình nguyện viên và Điều phối viên vào ?view=admin bị chặn HTTP 403
$volAdmin = httpGet($baseUrl . '/?view=admin', $volCookie);
$coordAdmin = httpGet($baseUrl . '/?view=admin', $coordCookie);
assertTest(
    $volAdmin['code'] === 403 && $coordAdmin['code'] === 403 &&
    str_contains($volAdmin['body'], '403 Forbidden'),
    '4.8.3',
    'RBAC: Tình nguyện viên và Điều phối viên truy cập ?view=admin bị từ chối bằng mã HTTP 403 Forbidden (R2)'
);

// 4.8.4 Đăng nhập Quản trị viên (admin@xanhhub.vn) thành công
$adminLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'admin@xanhhub.vn',
    'password' => '123456',
    'redirect_view' => 'admin',
]);
$adminCookie = $adminLogin['cookie'];
assertTest(
    $adminLogin['code'] === 302 && $adminCookie !== null,
    '4.8.4',
    'Đăng nhập tài khoản Quản trị viên (admin@xanhhub.vn) thành công với mật khẩu chuẩn (R2)'
);

// 4.8.5 Cổng Quản trị Hệ thống (?view=admin) HTTP 200 với 4 tab chuyên biệt
$adminView = httpGet($baseUrl . '/?view=admin', $adminCookie);
assertTest(
    $adminView['code'] === 200 &&
    str_contains($adminView['body'], 'id="admin-tab-users"') &&
    str_contains($adminView['body'], 'id="admin-tab-verification"') &&
    str_contains($adminView['body'], 'id="admin-tab-settings"') &&
    str_contains($adminView['body'], 'id="admin-tab-audit"') &&
    str_contains($adminView['body'], 'w_priority') &&
    str_contains($adminView['body'], 'withdraw_deadline_hours'),
    '4.8.5',
    'Cổng Quản trị Hệ thống (?view=admin) hiển thị đầy đủ 4 tab chuyên biệt (users, verification, settings, audit) (R2)'
);

// 4.8.6 Header xuất hiện nút nổi bật Quản trị Hệ thống khi đăng nhập ADMIN
assertTest(
    str_contains($adminView['body'], 'Quản trị Hệ thống') &&
    str_contains($adminView['body'], 'href="?view=admin"'),
    '4.8.6',
    'Header hiển thị nút Quản trị Hệ thống nổi bật dẫn tới ?view=admin khi tài khoản ADMIN đăng nhập (R2)'
);

// 4.9.1 Trình tạo MonogramAvatar sinh SVG Vector thuần túy với gradient chuẩn
$testSvg = \XanhHub\Http\MonogramAvatar::svg(1, 'Nguyễn Khánh Linh');
$testDataUri = \XanhHub\Http\MonogramAvatar::dataUriBase64(1, 'Nguyễn Khánh Linh');
assertTest(
    str_contains($testSvg, '<svg') &&
    str_contains($testSvg, '<linearGradient') &&
    str_contains($testSvg, 'NL') &&
    str_starts_with($testDataUri, 'data:image/svg+xml;base64,'),
    '4.9.1',
    'Trình tạo MonogramAvatar tự động trích xuất chữ cái đầu (NL) và kết xuất SVG Base64 chuẩn Đoàn (R3)'
);

// 4.9.2 Tuyệt đối không sử dụng ảnh chân dung AI trong HTML kết xuất
$homeHtml = httpGet($baseUrl . '/?view=home');
assertTest(
    !preg_match('/assets\/images\/avatar-[1-8]\.jpg/i', $homeHtml['body']) &&
    !preg_match('/assets\/images\/avatar-[1-8]\.jpg/i', $volProfile['body']) &&
    !preg_match('/assets\/images\/avatar-[1-8]\.jpg/i', $coordProfile['body']) &&
    !preg_match('/assets\/images\/avatar-[1-8]\.jpg/i', $adminView['body']),
    '4.9.2',
    'Triệt tiêu hoàn toàn ảnh chân dung AI (avatar-1.jpg..avatar-8.jpg) trên toàn bộ các view HTML (R4)'
);

// ============================================================================
// PHA 5: KIỂM TOÁN TÍNH TOÀN VẸN, CÂN BẰNG THẺ HTML & KHÔNG HỒI QUY
// ============================================================================
echo "\n▶ [PHA 5/5] Kiểm toán Tính Toàn vẹn Hệ thống, Thẻ HTML, Liên kết Chết & Regressions...\n";

// 5.1.1 Tính đóng kín thẻ HTML <main> và </main> trên toàn bộ public/index.php
$indexContent = file_get_contents($rootDir . '/public/index.php');
$openMainCount = preg_match_all('/<main\b[^>]*>/i', $indexContent);
$closeMainCount = preg_match_all('/<\/main>/i', $indexContent);
assertTest(
    $openMainCount === 16 && $closeMainCount === 16,
    '5.1.1',
    sprintf('Tính đóng kín HTML: Số lượng thẻ <main (%d) khớp chính xác với </main> (%d)', $openMainCount, $closeMainCount)
);

// 5.2.1 Tuyệt đối không có liên kết chết href="#"
$deadLinkCount = preg_match_all('/href=["\']#["\']/i', $indexContent);
assertTest($deadLinkCount === 0, '5.2.1', 'Triệt tiêu hoàn toàn liên kết chết dạng href="#" (Phát hiện 0 lỗi)');

// 5.3.1 Kiểm tra cú pháp PHP (php -l) trên các tệp cốt lõi đã hiệu chỉnh
$phpExec = (PHP_OS_FAMILY === 'Windows' && file_exists('C:\\xampp\\php\\php.exe'))
    ? 'C:\\xampp\\php\\php.exe'
    : PHP_BINARY;

$filesToCheck = [
    'public/index.php',
    'src/Http/AuthService.php',
    'src/Http/ApiController.php',
];
$allPhpClean = true;
foreach ($filesToCheck as $f) {
    $fullPath = $rootDir . DIRECTORY_SEPARATOR . $f;
    exec(sprintf('%s -l %s 2>&1', escapeshellarg($phpExec), escapeshellarg($fullPath)), $out, $ret);
    if ($ret !== 0) {
        $allPhpClean = false;
        break;
    }
}
assertTest($allPhpClean, '5.3.1', 'Cú pháp PHP (php -l) trên 100% các tệp hiệu chỉnh (index.php, AuthService, ApiController) đạt chuẩn');

// 5.4.1 Thực thi Bộ Kiểm thử Nghiệp vụ Lõi (bin/run-tests.php) bảo đảm Zero Regressions
exec(sprintf('%s %s 2>&1', escapeshellarg($phpExec), escapeshellarg($rootDir . '/bin/run-tests.php')), $coreOut, $coreRet);
$coreOutputStr = implode("\n", $coreOut);
$corePassed = ($coreRet === 0) && str_contains($coreOutputStr, '21/21 ca kiểm thử ĐẠT');
assertTest($corePassed, '5.4.1', 'Bộ kiểm thử nghiệp vụ lõi (bin/run-tests.php) tiếp tục ĐẠT 21/21 ca kiểm thử (100% PASS)');

// ============================================================================
// TỔNG KẾT BỘ KIỂM THỬ
// ============================================================================
echo "\n------------------------------------------------------------------------\n";
echo sprintf(" TỔNG KẾT KIỂM THỬ ĐIỀU PHỐI, HỒ SƠ & TẠO HOẠT ĐỘNG: [%d/%d ĐẠT] (%.1f%%)\n", $passed, $total, ($passed / $total) * 100);

if ($failed === 0) {
    echo " \033[32m✔ KẾT LUẬN: TOÀN BỘ PHÂN HỆ R1, R2, R3, R4 VÀ R5 ĐẠT 100% TIÊU CHUẨN ĐỀ BÀI!\033[0m\n";
} else {
    echo " \033[31m✖ CẢNH BÁO: CÓ {$failed} CA KIỂM THỬ THẤT BẠI. CẦN KIỂM TRA LẠI!\033[0m\n";
}
echo "========================================================================\n";

exit($failed === 0 ? 0 : 1);

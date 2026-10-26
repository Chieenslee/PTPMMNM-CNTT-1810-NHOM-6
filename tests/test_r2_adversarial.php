<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * XANH HUB (ĐỀ TÀI 6) - EMPIRICAL ADVERSARIAL CHALLENGER TEST SUITE (R2)
 *
 * RIGOROUS EMPIRICAL VERIFICATION OF REQUIREMENT R2:
 * 1. Role Separation & RBAC Enforcement (Public, Volunteer, Coordinator, Admin)
 * 2. Dedicated Admin Portal (?view=admin) 4-Tab Architecture & Action Protection
 * 3. Clean View Compliance (?view=verify & Coordinator ?view=profile)
 * 4. Audit Log Cryptographic Integrity (SHA-256 Hash Chain & Immutability)
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

$rootDir = dirname(__DIR__);
$baseUrl = 'http://127.0.0.1:8000';

// Ensure PHP Built-in Server is active on port 8000
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

function assertCheck(bool $condition, string $code, string $desc, string $detail = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo sprintf("  \033[32m[PASS]\033[0m %-10s %s %s\n", $code, $desc, $detail !== '' ? "({$detail})" : '');
    } else {
        $failed++;
        echo sprintf("  \033[31m[FAIL]\033[0m %-10s %s %s\n", $code, $desc, $detail !== '' ? "[{$detail}]" : '');
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

echo "========================================================================\n";
echo " XANH HUB (ĐỀ TÀI 6) - EMPIRICAL ADVERSARIAL CHALLENGER SUITE (R2)\n";
echo " Target: RBAC, Role Separation, Admin Portal 4 Tabs & Audit Log Integrity\n";
echo " Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo " Base URL:  " . $baseUrl . "\n";
echo "========================================================================\n\n";

$pdo = Database::getConnection();
$repo = new XanhHubRepository($pdo);

$pdo->exec("SELECT setval('users_id_seq', (SELECT MAX(id) FROM users))");

// Ensure test users exist for exact credentials mentioned in prompt
$hasLinhEmail = (bool) $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(email) = 'nguyen.khanhlinh@example.test'")->fetchColumn();
if (!$hasLinhEmail) {
    $pdo->prepare("
        INSERT INTO users (email, password_hash, full_name, role)
        VALUES ('nguyen.khanhlinh@example.test', :h, 'Nguyễn Khánh Linh', 'VOLUNTEER')
        ON CONFLICT (email) DO NOTHING
    ")->execute([':h' => password_hash('123456', PASSWORD_BCRYPT)]);
}

$hasThaoEmail = (bool) $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(email) = 'le.thanhthao@example.test'")->fetchColumn();
if (!$hasThaoEmail) {
    $pdo->prepare("
        INSERT INTO users (email, password_hash, full_name, role)
        VALUES ('le.thanhthao@example.test', :h, 'Lê Thanh Thảo', 'COORDINATOR')
        ON CONFLICT (email) DO NOTHING
    ")->execute([':h' => password_hash('123456', PASSWORD_BCRYPT)]);
}

// =============================================================================
// PHẦN 1: RBAC & BẢO MẬT TUYẾN ĐƯỜNG CỔNG QUẢN TRỊ (?view=admin)
// =============================================================================
echo "▶ [PHẦN 1/4] Kiểm tra Rào cản RBAC trên Cổng Quản trị (?view=admin)...\n";

// 1.1 Khách / Vãng lai (Unauthenticated / Public) truy cập ?view=admin -> HTTP 302 redirect
$guestAdmin = httpGet($baseUrl . '/?view=admin');
assertCheck(
    $guestAdmin['code'] === 302 &&
    str_contains($guestAdmin['headers'], 'Location:') &&
    str_contains($guestAdmin['headers'], 'redirect=admin'),
    'RBAC-01',
    'Khách chưa đăng nhập truy cập ?view=admin nhận HTTP 302 chuyển hướng đến login với redirect=admin',
    'HTTP ' . $guestAdmin['code']
);

// 1.2 Thiết lập phiên Tình nguyện viên bằng SV001
$volLogin1 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'SV001',
    'password' => '123456',
    'redirect_view' => 'volunteer',
]);
$volCookie1 = $volLogin1['cookie'];
assertCheck(
    $volLogin1['code'] === 302 && $volCookie1 !== null,
    'RBAC-02',
    'Đăng nhập tài khoản Tình nguyện viên SV001 thành công',
    'Cookie received'
);

// 1.3 Phiên SV001 truy cập ?view=admin -> HTTP 403 Forbidden
$volAdmin1 = httpGet($baseUrl . '/?view=admin', $volCookie1);
assertCheck(
    $volAdmin1['code'] === 403 &&
    str_contains($volAdmin1['body'], '403 Forbidden') &&
    str_contains($volAdmin1['body'], 'Truy cập bị từ chối'),
    'RBAC-03',
    'Phiên Tình nguyện viên SV001 truy cập ?view=admin bị chặn mã HTTP 403 Forbidden',
    'HTTP ' . $volAdmin1['code']
);

// 1.4 Thiết lập phiên Tình nguyện viên bằng nguyen.khanhlinh@example.test
$volLogin2 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'nguyen.khanhlinh@example.test',
    'password' => '123456',
    'redirect_view' => 'volunteer',
]);
$volCookie2 = $volLogin2['cookie'];
assertCheck(
    $volLogin2['code'] === 302 && $volCookie2 !== null,
    'RBAC-04',
    'Đăng nhập tài khoản Tình nguyện viên nguyen.khanhlinh@example.test thành công',
    'Cookie received'
);

// 1.5 Phiên nguyen.khanhlinh@example.test truy cập ?view=admin -> HTTP 403 Forbidden
$volAdmin2 = httpGet($baseUrl . '/?view=admin', $volCookie2);
assertCheck(
    $volAdmin2['code'] === 403 &&
    str_contains($volAdmin2['body'], '403 Forbidden'),
    'RBAC-05',
    'Phiên Tình nguyện viên nguyen.khanhlinh@example.test truy cập ?view=admin bị chặn mã HTTP 403 Forbidden',
    'HTTP ' . $volAdmin2['code']
);

// 1.6 Thiết lập phiên Điều phối viên bằng le.thanhthao@example.test
$coordLogin1 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'le.thanhthao@example.test',
    'password' => '123456',
    'redirect_view' => 'coordinator',
]);
$coordCookie1 = $coordLogin1['cookie'];
assertCheck(
    $coordLogin1['code'] === 302 && $coordCookie1 !== null,
    'RBAC-06',
    'Đăng nhập Điều phối viên le.thanhthao@example.test thành công',
    'Cookie received'
);

// 1.7 Phiên le.thanhthao@example.test truy cập ?view=admin -> HTTP 403 Forbidden
$coordAdmin1 = httpGet($baseUrl . '/?view=admin', $coordCookie1);
assertCheck(
    $coordAdmin1['code'] === 403 &&
    str_contains($coordAdmin1['body'], '403 Forbidden') &&
    str_contains($coordAdmin1['body'], 'Bạn không có quyền quản trị viên tối cao (ADMIN)'),
    'RBAC-07',
    'Phiên Điều phối viên le.thanhthao@example.test truy cập ?view=admin bị chặn mã HTTP 403 Forbidden',
    'HTTP ' . $coordAdmin1['code']
);

// 1.8 Thiết lập phiên Điều phối viên thao.dieuphoi@example.test
$coordLogin2 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'thao.dieuphoi@example.test',
    'password' => '123456',
    'redirect_view' => 'coordinator',
]);
$coordCookie2 = $coordLogin2['cookie'];
$coordAdmin2 = httpGet($baseUrl . '/?view=admin', $coordCookie2);
assertCheck(
    $coordAdmin2['code'] === 403 &&
    str_contains($coordAdmin2['body'], '403 Forbidden'),
    'RBAC-08',
    'Phiên Điều phối viên thao.dieuphoi@example.test truy cập ?view=admin bị chặn mã HTTP 403 Forbidden',
    'HTTP ' . $coordAdmin2['code']
);

// 1.9 Đăng nhập Quản trị viên bằng admin@xanhhub.vn
$adminLogin1 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'admin@xanhhub.vn',
    'password' => '123456',
    'redirect_view' => 'admin',
]);
$adminCookie1 = $adminLogin1['cookie'];
assertCheck(
    $adminLogin1['code'] === 302 && $adminCookie1 !== null,
    'RBAC-09',
    'Đăng nhập Quản trị viên admin@xanhhub.vn thành công',
    'Cookie received'
);

// 1.10 Phiên admin@xanhhub.vn truy cập ?view=admin -> HTTP 200 OK
$adminView1 = httpGet($baseUrl . '/?view=admin', $adminCookie1);
assertCheck(
    $adminView1['code'] === 200 &&
    str_contains($adminView1['body'], 'Cổng Quản trị Hệ thống Xanh Hub') &&
    str_contains($adminView1['body'], 'id="admin-tab-users"'),
    'RBAC-10',
    'Phiên Quản trị viên admin@xanhhub.vn truy cập ?view=admin phản hồi HTTP 200 OK đầy đủ giao diện',
    'HTTP ' . $adminView1['code']
);

// 1.11 Đăng nhập Quản trị viên bằng huong.quantri@example.test
$adminLogin2 = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'huong.quantri@example.test',
    'password' => '123456',
    'redirect_view' => 'admin',
]);
$adminCookie2 = $adminLogin2['cookie'];
assertCheck(
    $adminLogin2['code'] === 302 && $adminCookie2 !== null,
    'RBAC-11',
    'Đăng nhập Quản trị viên huong.quantri@example.test thành công',
    'Cookie received'
);

// 1.12 Phiên huong.quantri@example.test truy cập ?view=admin -> HTTP 200 OK
$adminView2 = httpGet($baseUrl . '/?view=admin', $adminCookie2);
assertCheck(
    $adminView2['code'] === 200 &&
    str_contains($adminView2['body'], 'id="admin-tab-users"'),
    'RBAC-12',
    'Phiên Quản trị viên huong.quantri@example.test truy cập ?view=admin phản hồi HTTP 200 OK',
    'HTTP ' . $adminView2['code']
);

// =============================================================================
// PHẦN 2: CẤU TRÚC 4 TAB CỔNG ADMIN & BẢO VỆ CÁC ACTION ENDPOINTS
// =============================================================================
echo "\n▶ [PHẦN 2/4] Kiểm tra Cấu trúc 4 Tab & Bảo vệ các Action Endpoints Quản trị...\n";

// 2.1 Kiểm tra 4 Tab navigation buttons
assertCheck(
    str_contains($adminView1['body'], 'id="btn-admin-tab-users"') &&
    str_contains($adminView1['body'], 'id="btn-admin-tab-verification"') &&
    str_contains($adminView1['body'], 'id="btn-admin-tab-settings"') &&
    str_contains($adminView1['body'], 'id="btn-admin-tab-audit"'),
    'ADMIN-01',
    'Giao diện Cổng Quản trị tích hợp đủ 4 nút Tab navigation (users, verification, settings, audit)'
);

// 2.2 Kiểm tra 4 Tab panels
assertCheck(
    str_contains($adminView1['body'], 'id="admin-tab-users"') &&
    str_contains($adminView1['body'], 'id="admin-tab-verification"') &&
    str_contains($adminView1['body'], 'id="admin-tab-settings"') &&
    str_contains($adminView1['body'], 'id="admin-tab-audit"'),
    'ADMIN-02',
    'Giao diện Cổng Quản trị chứa đầy đủ 4 Tab panel nội dung riêng biệt (#admin-tab-*)'
);

// 2.3 Kiểm tra sự hiện diện của các form action endpoints
assertCheck(
    str_contains($adminView1['body'], 'action="?action=admin_update_role"') &&
    str_contains($adminView1['body'], 'action="?action=admin_assign_coordinator"') &&
    str_contains($adminView1['body'], 'action="?action=admin_verify_skill"') &&
    str_contains($adminView1['body'], 'action="?action=admin_issue_certificate"') &&
    str_contains($adminView1['body'], 'action="?action=admin_save_settings"'),
    'ADMIN-03',
    'Hiện diện đầy đủ 5 action endpoints quản trị (update_role, assign_coordinator, verify_skill, issue_cert, save_settings)'
);

// 2.4 Adversarial Test: Khách tấn công POST vào các endpoint Admin -> bị từ chối
$actionsToTest = [
    'admin_update_role' => ['target_user_id' => 1, 'new_role' => 'ADMIN'],
    'admin_assign_coordinator' => ['activity_id' => 1, 'user_id' => 1, 'assign_action' => 'add'],
    'admin_verify_skill' => ['volunteer_id' => 1, 'skill_id' => 1, 'verified' => 1],
    'admin_issue_certificate' => ['volunteer_id' => 1, 'activity_id' => 1, 'total_hours' => 20],
    'admin_save_settings' => ['withdraw_deadline_hours' => 12],
];

foreach ($actionsToTest as $act => $payload) {
    $resGuestAttack = httpPost($baseUrl . "/?action={$act}", $payload);
    assertCheck(
        $resGuestAttack['code'] === 302 && str_contains($resGuestAttack['headers'], 'view=home'),
        'SEC-GUEST-' . substr($act, 6),
        "Khách không thể gọi POST ?action={$act} (Bị chặn chuyển hướng về home kèm lỗi 403 flash)"
    );
}

// 2.5 Adversarial Test: Tình nguyện viên tấn công POST vào các endpoint Admin -> bị từ chối
foreach ($actionsToTest as $act => $payload) {
    $resVolAttack = httpPost($baseUrl . "/?action={$act}", $payload, $volCookie1);
    assertCheck(
        $resVolAttack['code'] === 302 && str_contains($resVolAttack['headers'], 'view=home'),
        'SEC-VOL-' . substr($act, 6),
        "Tình nguyện viên không thể gọi POST ?action={$act} (Bị chặn phân quyền 403)"
    );
}

// 2.6 Adversarial Test: Điều phối viên tấn công POST vào các endpoint Admin -> bị từ chối
foreach ($actionsToTest as $act => $payload) {
    $resCoordAttack = httpPost($baseUrl . "/?action={$act}", $payload, $coordCookie1);
    assertCheck(
        $resCoordAttack['code'] === 302 && str_contains($resCoordAttack['headers'], 'view=home'),
        'SEC-COORD-' . substr($act, 6),
        "Điều phối viên không thể gọi POST ?action={$act} (Bị chặn phân quyền 403)"
    );
}

// 2.7 Adversarial Test: Admin tự hạ quyền của chính mình -> Hệ thống từ chối (Anti-Lockout)
$myAdminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@xanhhub.vn'")->fetchColumn();
$selfDemoteRes = httpPost($baseUrl . '/?action=admin_update_role', [
    'target_user_id' => $myAdminId,
    'new_role' => 'VOLUNTEER',
], $adminCookie1);
$myRoleAfter = $pdo->query("SELECT role FROM users WHERE id = {$myAdminId}")->fetchColumn();
assertCheck(
    $myRoleAfter === 'ADMIN',
    'ANTI-LOCKOUT',
    'Bảo vệ quản trị: Chặn không cho Admin tự hạ vai trò của chính mình để tránh mất quyền hệ thống',
    'Role is still ' . $myRoleAfter
);

// 2.8 Adversarial Test: Gửi role không hợp lệ -> Hệ thống từ chối
$invalidRoleRes = httpPost($baseUrl . '/?action=admin_update_role', [
    'target_user_id' => 2,
    'new_role' => 'SUPER_HACKER',
], $adminCookie1);
$targetRoleAfter = $pdo->query("SELECT role FROM users WHERE id = 2")->fetchColumn();
assertCheck(
    $targetRoleAfter === 'VOLUNTEER',
    'VALID-ROLE',
    'Từ chối cập nhật vai trò không thuộc danh mục chuẩn [VOLUNTEER, COORDINATOR, ADMIN]',
    'Role is still ' . $targetRoleAfter
);

// =============================================================================
// PHẦN 3: LÀM SẠCH VIEW & CHỐNG RÒ RỈ DỮ LIỆU KIỂM TOÁN (CLEAN VIEWS)
// =============================================================================
echo "\n▶ [PHẦN 3/4] Kiểm tra Làm Sạch View (Trang Tra cứu & Hồ sơ Điều phối)...\n";

// 3.1 Trang tra cứu mặc định (?view=verify)
$verifyCleanDefault = httpGet($baseUrl . '/?view=verify');
assertCheck(
    $verifyCleanDefault['code'] === 200 &&
    !str_contains($verifyCleanDefault['body'], 'audit_log') &&
    !str_contains($verifyCleanDefault['body'], 'USER_LOGIN') &&
    !str_contains($verifyCleanDefault['body'], 'PROFILE_UPDATE') &&
    !str_contains($verifyCleanDefault['body'], 'prev_hash'),
    'CLEAN-01',
    'Trang ?view=verify mặc định không chứa audit_log, USER_LOGIN, PROFILE_UPDATE hoặc prev_hash'
);

// 3.2 Trang tra cứu báo cáo tổng kết chuẩn (?view=verify&code=XH2026-BC-D3-F9E13E)
$verifyCleanReport = httpGet($baseUrl . '/?view=verify&code=XH2026-BC-D3-F9E13E');
assertCheck(
    $verifyCleanReport['code'] === 200 &&
    !str_contains($verifyCleanReport['body'], 'audit_log') &&
    !str_contains($verifyCleanReport['body'], 'USER_LOGIN') &&
    !str_contains($verifyCleanReport['body'], 'PROFILE_UPDATE') &&
    !str_contains($verifyCleanReport['body'], 'prev_hash') &&
    str_contains($verifyCleanReport['body'], 'XH2026-BC-D3-F9E13E'),
    'CLEAN-02',
    'Trang ?view=verify khi tra cứu báo cáo Đoàn không chứa audit_log, USER_LOGIN hoặc prev_hash'
);

// 3.3 Trang tra cứu giấy chứng nhận hợp lệ (?view=verify&code=XH2026-SV001-9F8A2C)
$verifyCleanCert = httpGet($baseUrl . '/?view=verify&code=XH2026-SV001-9F8A2C');
assertCheck(
    $verifyCleanCert['code'] === 200 &&
    !str_contains($verifyCleanCert['body'], 'audit_log') &&
    !str_contains($verifyCleanCert['body'], 'USER_LOGIN') &&
    !str_contains($verifyCleanCert['body'], 'PROFILE_UPDATE') &&
    !str_contains($verifyCleanCert['body'], 'prev_hash'),
    'CLEAN-03',
    'Trang ?view=verify khi tra cứu giấy chứng nhận sinh viên không chứa bất kỳ audit_log hoặc prev_hash'
);

// 3.4 Trang tra cứu mã không tồn tại (?view=verify&code=INVALID-TEST-999)
$verifyCleanInvalid = httpGet($baseUrl . '/?view=verify&code=INVALID-TEST-999');
assertCheck(
    $verifyCleanInvalid['code'] === 200 &&
    !str_contains($verifyCleanInvalid['body'], 'audit_log') &&
    !str_contains($verifyCleanInvalid['body'], 'prev_hash') &&
    str_contains($verifyCleanInvalid['body'], 'Không tìm thấy mã chứng thực'),
    'CLEAN-04',
    'Trang ?view=verify khi mã không tồn tại hiển thị trạng thái rỗng sạch sẽ, không rò rỉ audit_log'
);

// 3.5 Hồ sơ Tình nguyện viên (?view=profile của VOLUNTEER)
$volProfileRes = httpGet($baseUrl . '/?view=profile', $volCookie1);
assertCheck(
    $volProfileRes['code'] === 200 &&
    !str_contains($volProfileRes['body'], 'audit_log') &&
    !str_contains($volProfileRes['body'], 'prev_hash') &&
    str_contains($volProfileRes['body'], 'Hồ sơ Tình nguyện viên Số'),
    'CLEAN-05',
    'Hồ sơ Tình nguyện viên (?view=profile) gỡ bỏ hoàn toàn bảng audit_log và mã băm prev_hash'
);

// 3.6 Hồ sơ Điều phối viên (?view=profile của COORDINATOR)
$coordProfileRes = httpGet($baseUrl . '/?view=profile', $coordCookie1);
assertCheck(
    $coordProfileRes['code'] === 200 &&
    !str_contains($coordProfileRes['body'], 'audit_log') &&
    !str_contains($coordProfileRes['body'], 'prev_hash'),
    'CLEAN-06',
    'Hồ sơ Điều phối viên (?view=profile) tuyệt đối không chứa bảng audit_log hoặc prev_hash'
);

// 3.7 Bảng "Danh sách Chiến dịch & Hoạt động Đang Hướng dẫn" trong hồ sơ Điều phối viên
assertCheck(
    str_contains($coordProfileRes['body'], 'Danh sách Chiến dịch &amp; Hoạt động Đang Hướng dẫn') &&
    str_contains($coordProfileRes['body'], 'Tiến độ Phân công Quân số') &&
    str_contains($coordProfileRes['body'], 'Mã &amp; Tên Chiến dịch'),
    'CLEAN-07',
    'Hồ sơ Điều phối viên tích hợp bảng "Danh sách Chiến dịch & Hoạt động Đang Hướng dẫn" với tiến độ quân số'
);

// =============================================================================
// PHẦN 4: TÍNH TOÀN VẸN CHUỖI BĂM MẬT MÃ SHA-256 (AUDIT LOG INTEGRITY)
// =============================================================================
echo "\n▶ [PHẦN 4/4] Kiểm tra Tính Toàn Vẹn Chuỗi Băm SHA-256 (#admin-tab-audit)...\n";

// 4.1 Tab 4 trong Cổng Quản trị hiển thị huy hiệu xác nhận chuỗi toàn vẹn
assertCheck(
    str_contains($adminView1['body'], 'Sổ cái Kiểm toán Bất biến Chuỗi băm SHA-256') &&
    str_contains($adminView1['body'], 'Chuỗi SHA-256 Toàn vẹn 100%'),
    'AUDIT-01',
    '#admin-tab-audit kết xuất tiêu đề sổ cái bất biến và huy hiệu "Chuỗi SHA-256 Toàn vẹn 100%"'
);

// 4.2 Tab 4 kết xuất đầy đủ các cột sổ cái và chuỗi băm
assertCheck(
    str_contains($adminView1['body'], 'Mã băm SHA-256') &&
    str_contains($adminView1['body'], 'Lý do nghiệp vụ') &&
    str_contains($adminView1['body'], 'Người thực hiện') &&
    str_contains($adminView1['body'], 'row_hash') &&
    str_contains($adminView1['body'], 'prev_hash'),
    'AUDIT-02',
    '#admin-tab-audit hiển thị cấu trúc bảng sổ cái với mã băm SHA-256 liên kết từng bản ghi'
);

// 4.3 Kiểm tra xác thực chuỗi băm trực tiếp qua hàm verifyAuditLogChain() của Repository
$isChainValid = $repo->verifyAuditLogChain();
assertCheck(
    $isChainValid === true,
    'AUDIT-03',
    'Hàm Repository verifyAuditLogChain() xác thực 100% chuỗi băm prev_hash -> row_hash khớp chuẩn',
    'Valid: true'
);

// 4.4 Kiểm tra thuật toán băm liên tiếp từng dòng trong cơ sở dữ liệu PostgreSQL
$auditRows = $pdo->query('SELECT id, actor_user_id, action, prev_hash, row_hash FROM audit_log ORDER BY id ASC')->fetchAll();
$totalRows = count($auditRows);
$hashChainBroken = false;
$expectedPrev = str_repeat('0', 64);

for ($i = 0; $i < $totalRows; $i++) {
    $row = $auditRows[$i];
    if ($row['prev_hash'] !== $expectedPrev) {
        $hashChainBroken = true;
        break;
    }
    $expectedPrev = $row['row_hash'];
}

assertCheck(
    !$hashChainBroken && $totalRows > 0,
    'AUDIT-04',
    sprintf('Toàn bộ %d bản ghi audit_log liên kết chuỗi băm bất biến không đứt gãy', $totalRows),
    "Total: {$totalRows} rows verified"
);

// 4.5 Kiểm tra tính năng lọc hành động trong Tab 4 (Action Filter)
$auditFilterHtml = httpGet($baseUrl . '/?view=admin&tab=audit&audit_action=USER_LOGIN', $adminCookie1);
assertCheck(
    $auditFilterHtml['code'] === 200 &&
    str_contains($auditFilterHtml['body'], 'id="admin-tab-audit"') &&
    str_contains($auditFilterHtml['body'], 'USER_LOGIN'),
    'AUDIT-05',
    'Tính năng lọc hành động trong Tab 4 (?audit_action=USER_LOGIN) hoạt động chuẩn xác'
);

// 4.6 Kiểm tra Trigger chống sửa/xóa bảng audit_log (Trg Protect Immutability)
$triggerBlocked = false;
try {
    $pdo->exec("UPDATE audit_log SET reason = 'TAMPERED' WHERE id = 1");
} catch (\Throwable $e) {
    if (str_contains($e->getMessage(), 'AUDIT_LOG_IMMUTABLE')) {
        $triggerBlocked = true;
    }
}
assertCheck(
    $triggerBlocked === true,
    'AUDIT-06',
    'PostgreSQL Trigger trg_protect_audit_log chặn đứng hành vi sửa đổi nhật ký kiểm toán (AUDIT_LOG_IMMUTABLE)',
    'Trigger Active'
);

// =============================================================================
// TỔNG KẾT BỘ KIỂM THỬ ĐỐI KHÁNG EMPIRICAL CHALLENGER
// =============================================================================
echo "\n------------------------------------------------------------------------\n";
echo sprintf(" TỔNG KẾT KIỂM THỬ ĐỐI KHÁNG R2 (RBAC & AUDIT LOG): [%d/%d ĐẠT] (%.1f%%)\n", $passed, $total, ($passed / $total) * 100);

if ($failed === 0) {
    echo " \033[32m✔ VERDICT: APPROVE — YÊU CẦU R2 ĐẠT CHUẨN HOÀN TOÀN, KHÔNG PHÁT HIỆN LỖI!\033[0m\n";
} else {
    echo " \033[31m✖ VERDICT: REQUEST_CHANGES — CÓ {$failed} CA KIỂM THỬ THẤT BẠI!\033[0m\n";
}
echo "========================================================================\n";

exit($failed === 0 ? 0 : 1);

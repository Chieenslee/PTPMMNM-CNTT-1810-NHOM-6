<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * XANH HUB (ĐỀ TÀI 6) - EMPIRICAL ADVERSARIAL CHALLENGER TEST SUITE
 * RBAC, SECURITY, ROUTING GUARDS, DATABASE TRANSACTIONS & AUDIT LOG INTEGRITY
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

$rootDir = dirname(__DIR__);
$baseUrl = 'http://127.0.0.1:8000';

// Đảm bảo PHP Built-in Server sẵn sàng trên cổng 8000
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

function assertEmpirical(bool $condition, string $code, string $desc, string $extra = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo sprintf("  \033[32m[PASS]\033[0m %-10s %s %s\n", $code, $desc, $extra !== '' ? "({$extra})" : '');
    } else {
        $failed++;
        echo sprintf("  \033[31m[FAIL]\033[0m %-10s %s %s\n", $code, $desc, $extra !== '' ? "[{$extra}]" : '');
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

    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
    $newCookie = !empty($matches[1]) ? implode('; ', $matches[1]) : null;

    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body,
        'cookie' => $newCookie,
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

    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
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
echo " XANH HUB (ĐỀ TÀI 6) - EMPIRICAL ADVERSARIAL CHALLENGER SUITE\n";
echo " RBAC, SECURITY, ROUTING GUARDS, TRANSACTIONS & AUDIT LOG INTEGRITY\n";
echo "========================================================================\n";
echo " Base URL:       " . $baseUrl . "\n";
echo " Timestamp:      " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

$pdo = Database::getConnection();
$repo = new XanhHubRepository($pdo);

// -----------------------------------------------------------------------------
// 0. CHUẨN BỊ PHIÊN LÀM VIỆC CÁC TÀI KHOẢN (COORDINATOR, ADMIN, VOLUNTEER)
// -----------------------------------------------------------------------------
$coordLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'thao.dieuphoi@example.test',
    'password' => 'XanhHub@2026',
    'redirect_view' => 'coordinator',
]);
$coordCookie = $coordLogin['cookie'];

$adminLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'huong.quantri@example.test',
    'password' => 'XanhHub@2026',
    'redirect_view' => 'coordinator',
]);
$adminCookie = $adminLogin['cookie'];

$volLogin = httpPost($baseUrl . '/?action=do_login', [
    'identifier' => 'SV001',
    'password' => 'XanhHub@2026',
    'redirect_view' => 'volunteer',
]);
$volCookie = $volLogin['cookie'];

assertEmpirical($coordLogin['code'] === 302 && $coordCookie !== null, 'AUTH-01', 'Coordinator session established', 'thao.dieuphoi');
assertEmpirical($adminLogin['code'] === 302 && $adminCookie !== null, 'AUTH-02', 'Admin session established', 'huong.quantri');
assertEmpirical($volLogin['code'] === 302 && $volCookie !== null, 'AUTH-03', 'Volunteer session established', 'SV001');

// =============================================================================
// PHẦN 1: RBAC & BẢO MẬT TUYẾN ĐƯỜNG (SECURITY & ROUTING GUARDS)
// =============================================================================
echo "\n▶ [PHẦN 1/3] Kiểm tra RBAC, Phân Quyền & Rào Cản Điều Hướng (Routing Guards)...\n";

// 1.1 Khách vãng lai (PUBLIC) gọi POST action=create_activity -> HTTP 403 Forbidden
$resGuestPost = httpPost($baseUrl . '/?action=create_activity', [
    'code' => 'TEST-HACK-01',
    'title' => 'Trái phép bởi khách',
]);
assertEmpirical(
    $resGuestPost['code'] === 403 && str_contains($resGuestPost['body'], '403 Forbidden'),
    'SEC-01',
    'PUBLIC POST action=create_activity trả về HTTP 403 Forbidden',
    'Code: ' . $resGuestPost['code']
);

// 1.2 Khách vãng lai (PUBLIC) gọi POST action=create_activity với payload rỗng -> HTTP 403 Forbidden
$resGuestEmptyPost = httpPost($baseUrl . '/?action=create_activity', []);
assertEmpirical(
    $resGuestEmptyPost['code'] === 403 && str_contains($resGuestEmptyPost['body'], '403 Forbidden'),
    'SEC-02',
    'PUBLIC POST action=create_activity với payload rỗng trả về HTTP 403 Forbidden',
    'Code: ' . $resGuestEmptyPost['code']
);

// 1.3 Tài khoản VOLUNTEER gọi POST action=create_activity -> HTTP 403 Forbidden
$resVolPost = httpPost($baseUrl . '/?action=create_activity', [
    'code' => 'TEST-HACK-02',
    'title' => 'Trái phép bởi Tình nguyện viên',
], $volCookie);
assertEmpirical(
    $resVolPost['code'] === 403 && str_contains($resVolPost['body'], '403 Forbidden'),
    'SEC-03',
    'VOLUNTEER POST action=create_activity trả về HTTP 403 Forbidden',
    'Code: ' . $resVolPost['code']
);

// 1.4 Tài khoản VOLUNTEER gọi POST action=create_activity với payload rỗng -> HTTP 403 Forbidden
$resVolEmptyPost = httpPost($baseUrl . '/?action=create_activity', [], $volCookie);
assertEmpirical(
    $resVolEmptyPost['code'] === 403 && str_contains($resVolEmptyPost['body'], '403 Forbidden'),
    'SEC-04',
    'VOLUNTEER POST action=create_activity với payload rỗng trả về HTTP 403 Forbidden',
    'Code: ' . $resVolEmptyPost['code']
);

// 1.5 COORDINATOR truy cập ?view=volunteer -> HTTP 302 chuyển hướng về ?view=coordinator
$resCoordToVol = httpGet($baseUrl . '/?view=volunteer', $coordCookie);
$isCoordVolRedirect = ($resCoordToVol['code'] === 302) && (
    str_contains($resCoordToVol['headers'], 'view=coordinator') ||
    str_contains($resCoordToVol['headers'], 'Location: ?view=coordinator')
);
assertEmpirical(
    $isCoordVolRedirect,
    'SEC-05',
    'COORDINATOR truy cập ?view=volunteer trả về HTTP 302 chuyển hướng ?view=coordinator',
    'Code: ' . $resCoordToVol['code']
);

// 1.6 ADMIN truy cập ?view=volunteer -> HTTP 302 chuyển hướng về ?view=coordinator
$resAdminToVol = httpGet($baseUrl . '/?view=volunteer', $adminCookie);
$isAdminVolRedirect = ($resAdminToVol['code'] === 302) && (
    str_contains($resAdminToVol['headers'], 'view=coordinator') ||
    str_contains($resAdminToVol['headers'], 'Location: ?view=coordinator')
);
assertEmpirical(
    $isAdminVolRedirect,
    'SEC-06',
    'ADMIN truy cập ?view=volunteer trả về HTTP 302 chuyển hướng ?view=coordinator',
    'Code: ' . $resAdminToVol['code']
);

// 1.7 Khách vãng lai truy cập ?view=profile -> HTTP 302 chuyển hướng về ?view=login&redirect=profile
$resGuestProfile = httpGet($baseUrl . '/?view=profile');
$isGuestProfileRedirect = ($resGuestProfile['code'] === 302) && (
    str_contains($resGuestProfile['headers'], 'view=login') &&
    str_contains($resGuestProfile['headers'], 'redirect=profile')
);
assertEmpirical(
    $isGuestProfileRedirect,
    'SEC-07',
    'PUBLIC truy cập ?view=profile trả về HTTP 302 chuyển hướng ?view=login&redirect=profile',
    'Code: ' . $resGuestProfile['code']
);

// 1.8 Khách vãng lai truy cập ?view=profile&tab=skills -> HTTP 302 chuyển hướng login&redirect=profile
$resGuestProfileTab = httpGet($baseUrl . '/?view=profile&tab=skills');
$isGuestProfileTabRedirect = ($resGuestProfileTab['code'] === 302) && (
    str_contains($resGuestProfileTab['headers'], 'view=login') &&
    str_contains($resGuestProfileTab['headers'], 'redirect=profile')
);
assertEmpirical(
    $isGuestProfileTabRedirect,
    'SEC-08',
    'PUBLIC truy cập ?view=profile&tab=skills trả về HTTP 302 bảo toàn redirect=profile',
    'Code: ' . $resGuestProfileTab['code']
);

// 1.9 Khách vãng lai truy cập ?view=coordinator -> HTTP 302 chuyển hướng ?view=login&redirect=coordinator
$resGuestCoord = httpGet($baseUrl . '/?view=coordinator');
$isGuestCoordRedirect = ($resGuestCoord['code'] === 302) && (
    str_contains($resGuestCoord['headers'], 'view=login') &&
    str_contains($resGuestCoord['headers'], 'redirect=coordinator')
);
assertEmpirical(
    $isGuestCoordRedirect,
    'SEC-09',
    'PUBLIC truy cập ?view=coordinator trả về HTTP 302 chuyển hướng ?view=login&redirect=coordinator',
    'Code: ' . $resGuestCoord['code']
);

// 1.10 VOLUNTEER truy cập ?view=coordinator -> HTTP 302 chuyển hướng về ?view=volunteer
$resVolCoord = httpGet($baseUrl . '/?view=coordinator', $volCookie);
$isVolCoordRedirect = ($resVolCoord['code'] === 302) && (
    str_contains($resVolCoord['headers'], 'view=volunteer')
);
assertEmpirical(
    $isVolCoordRedirect,
    'SEC-10',
    'VOLUNTEER truy cập ?view=coordinator trả về HTTP 302 chuyển hướng ?view=volunteer',
    'Code: ' . $resVolCoord['code']
);

// =============================================================================
// PHẦN 2: TÍNH TOÀN VẸN GIAO DỊCH POSTGRESQL & ROLLBACK KHI ĐẦU VÀO LỖI
// =============================================================================
echo "\n▶ [PHẦN 2/3] Kiểm thử Giao dịch PostgreSQL & Cơ chế Rollback Nguyên Tử...\n";

$initialActivitiesCount = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
$initialShiftsCount = (int) $pdo->query('SELECT COUNT(*) FROM shifts')->fetchColumn();
$initialCoordsCount = (int) $pdo->query('SELECT COUNT(*) FROM activity_coordinators')->fetchColumn();
$initialReqsCount = (int) $pdo->query('SELECT COUNT(*) FROM shift_requirements')->fetchColumn();

// 2.1 Rollback khi thiếu mã chiến dịch (activity_code = '')
$resMissingCode = httpPost($baseUrl . '/?action=create_activity', [
    'activity_code' => '',
    'title' => 'Chiến dịch thiếu mã',
    'target_area' => 'TP.HCM',
], $coordCookie);

$cntActAfter1 = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
$cntShiftAfter1 = (int) $pdo->query('SELECT COUNT(*) FROM shifts')->fetchColumn();
$cntCoordAfter1 = (int) $pdo->query('SELECT COUNT(*) FROM activity_coordinators')->fetchColumn();

assertEmpirical(
    $cntActAfter1 === $initialActivitiesCount &&
    $cntShiftAfter1 === $initialShiftsCount &&
    $cntCoordAfter1 === $initialCoordsCount,
    'TX-01',
    'Rollback giao dịch thành công khi thiếu activity_code (0 bản ghi rác được tạo)',
    "Act: {$cntActAfter1}/{$initialActivitiesCount}"
);

// 2.2 Rollback khi thiếu tên chiến dịch (title = '')
$resMissingTitle = httpPost($baseUrl . '/?action=create_activity', [
    'activity_code' => 'ACT-NO-TITLE',
    'title' => '',
    'target_area' => 'TP.HCM',
], $coordCookie);

$cntActAfter2 = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
$cntShiftAfter2 = (int) $pdo->query('SELECT COUNT(*) FROM shifts')->fetchColumn();

assertEmpirical(
    $cntActAfter2 === $initialActivitiesCount && $cntShiftAfter2 === $initialShiftsCount,
    'TX-02',
    'Rollback giao dịch thành công khi thiếu title (0 bản ghi rác được tạo)',
    "Act: {$cntActAfter2}/{$initialActivitiesCount}"
);

// 2.3 Rollback khi khung giờ ca trực bị đảo ngược (start_time > end_time)
// Khi đó tstzrange(:st::timestamptz, :et::timestamptz, '[)') sẽ gây lỗi PostgreSQL
$invertedTestCode = 'ACT-ERR-TIME-' . substr(bin2hex(random_bytes(3)), 0, 6);
$resInvertedTime = httpPost($baseUrl . '/?action=create_activity', [
    'activity_code' => $invertedTestCode,
    'title' => 'Chiến dịch lỗi khung giờ ca trực',
    'target_area' => 'Quận 1',
    'shifts' => [
        [
            'code' => $invertedTestCode . '-S1',
            'title' => 'Ca ngược thời gian',
            'start_time' => '2026-08-01 17:00:00+07:00',
            'end_time'   => '2026-08-01 08:00:00+07:00', // start > end!
            'min_people' => 2,
            'max_people' => 4,
            'skill_id'   => 1,
            'min_level'  => 1,
        ]
    ]
], $coordCookie);

$actInvertedExists = (bool) $pdo->query("SELECT 1 FROM activities WHERE code = '{$invertedTestCode}'")->fetchColumn();
$shiftInvertedExists = (bool) $pdo->query("SELECT 1 FROM shifts WHERE code = '{$invertedTestCode}-S1'")->fetchColumn();
$cntActAfter3 = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();

assertEmpirical(
    !$actInvertedExists && !$shiftInvertedExists && $cntActAfter3 === $initialActivitiesCount,
    'TX-03',
    'Rollback hoàn toàn khi PostgreSQL ném lỗi tstzrange (start_time > end_time)',
    "Act exists: " . ($actInvertedExists ? 'true' : 'false')
);

// 2.4 Rollback khi kỹ năng yêu cầu không tồn tại (skill_id = 999999 vi phạm Foreign Key)
$fkTestCode = 'ACT-ERR-FK-' . substr(bin2hex(random_bytes(3)), 0, 6);
$resFkErr = httpPost($baseUrl . '/?action=create_activity', [
    'activity_code' => $fkTestCode,
    'title' => 'Chiến dịch lỗi khóa ngoại skill_id',
    'shifts' => [
        [
            'code' => $fkTestCode . '-S1',
            'title' => 'Ca kỹ năng ma',
            'start_time' => '2026-08-01 08:00:00+07:00',
            'end_time'   => '2026-08-01 11:30:00+07:00',
            'min_people' => 2,
            'max_people' => 4,
            'skill_id'   => 999999, // Không tồn tại trong bảng skills
            'min_level'  => 1,
        ]
    ]
], $coordCookie);

$actFkExists = (bool) $pdo->query("SELECT 1 FROM activities WHERE code = '{$fkTestCode}'")->fetchColumn();
$cntActAfter4 = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();

assertEmpirical(
    !$actFkExists && $cntActAfter4 === $initialActivitiesCount,
    'TX-04',
    'Rollback hoàn toàn khi vi phạm ràng buộc khóa ngoại (shift_requirements.skill_id)',
    "Act exists: " . ($actFkExists ? 'true' : 'false')
);

// 2.5 Rollback nhiều ca: Ca 1 hợp lệ nhưng Ca 2 lỗi thời gian -> Toàn bộ ca 1 & chiến dịch phải bị rollback!
$multiTestCode = 'ACT-MULTI-ROLLBACK-' . substr(bin2hex(random_bytes(3)), 0, 6);
$resMultiErr = httpPost($baseUrl . '/?action=create_activity', [
    'activity_code' => $multiTestCode,
    'title' => 'Chiến dịch kiểm tra đa ca có ca lỗi',
    'shifts' => [
        [
            'code' => $multiTestCode . '-S1-OK',
            'title' => 'Ca 1 hợp lệ tuyệt đối',
            'start_time' => '2026-08-01 07:00:00+07:00',
            'end_time'   => '2026-08-01 11:00:00+07:00',
            'min_people' => 2,
            'max_people' => 4,
            'skill_id'   => 1,
            'min_level'  => 1,
        ],
        [
            'code' => $multiTestCode . '-S2-ERR',
            'title' => 'Ca 2 lỗi tstzrange',
            'start_time' => '2026-08-01 17:00:00+07:00',
            'end_time'   => '2026-08-01 06:00:00+07:00', // Đảo ngược!
            'min_people' => 2,
            'max_people' => 4,
            'skill_id'   => 1,
            'min_level'  => 1,
        ]
    ]
], $coordCookie);

$actMultiExists = (bool) $pdo->query("SELECT 1 FROM activities WHERE code = '{$multiTestCode}'")->fetchColumn();
$shiftMultiS1Exists = (bool) $pdo->query("SELECT 1 FROM shifts WHERE code = '{$multiTestCode}-S1-OK'")->fetchColumn();
$cntActAfter5 = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();

assertEmpirical(
    !$actMultiExists && !$shiftMultiS1Exists && $cntActAfter5 === $initialActivitiesCount,
    'TX-05',
    'Multi-shift rollback: Ca 1 hợp lệ nhưng Ca 2 lỗi -> Toàn bộ chiến dịch & Ca 1 bị xóa sạch',
    "S1 exists: " . ($shiftMultiS1Exists ? 'true' : 'false')
);

// =============================================================================
// PHẦN 3: GHI NHẬT KÝ KIỂM TOÁN (AUDIT_LOG) & BẢO TOÀN CHUỖI MÃ BĂM SHA-256
// =============================================================================
echo "\n▶ [PHẦN 3/3] Kiểm tra Nhật Ký Kiểm Toán (audit_log), Hash Chain & Tính Bất Biến...\n";

// 3.1 Kiểm tra tính toàn vẹn chuỗi hash hiện tại của audit_log
$initialChainValid = $repo->verifyAuditLogChain();
assertEmpirical($initialChainValid, 'AUDIT-01', 'Chuỗi mã băm SHA-256 hiện tại của audit_log hợp lệ 100%');

// 3.2 Tạo một hoạt động hợp lệ qua Coordinator -> Xác nhận ghi ACTIVITY_CREATE và hash chain hợp lệ
$validTestCode = 'ACT-CHALLENGE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$resValidCreate = httpPost($baseUrl . '/?action=create_activity', [
    'code' => $validTestCode,
    'title' => 'Chiến dịch Khảo thí Thực nghiệm Challenger 1',
    'tag' => 'Khảo sát Thực nghiệm',
    'location_name' => 'Quận 1, TP.HCM',
    'description' => 'Hoạt động phục vụ kiểm thử tính toàn vẹn chuỗi băm audit_log',
    'shift_code' => $validTestCode . '-S1',
    'shift_title' => 'Ca thực nghiệm 1',
    'shift_start' => '2026-08-10 07:30:00+07:00',
    'shift_end' => '2026-08-10 11:30:00+07:00',
    'shift_location' => 'Quận 1, TP.HCM',
    'shift_min_people' => 2,
    'shift_max_people' => 5,
    'shift_priority' => 3,
    'shift_skill_id' => 1,
    'shift_min_level' => 2,
], $coordCookie);

if ($resValidCreate['code'] !== 302 || !str_contains($resValidCreate['headers'], 'view=campaigns')) {
    echo "DEBUG resValidCreate code: " . $resValidCreate['code'] . "\n";
    echo "DEBUG resValidCreate headers:\n" . $resValidCreate['headers'] . "\n";
    echo "DEBUG resValidCreate body (snippet):\n" . substr($resValidCreate['body'], 0, 500) . "\n";
}


$createdActRow = $pdo->query("SELECT id, code, title FROM activities WHERE code = '{$validTestCode}'")->fetch();
assertEmpirical(
    is_array($createdActRow) && ($resValidCreate['code'] === 302),
    'AUDIT-02',
    'Tạo hoạt động hợp lệ thành công qua Coordinator (HTTP 302)',
    "ID: " . ($createdActRow['id'] ?? 'none')
);

$newActId = (int) ($createdActRow['id'] ?? 0);

// Kiểm tra bản ghi audit_log tương ứng
$lastAudit = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
assertEmpirical(
    is_array($lastAudit) &&
    $lastAudit['action'] === 'ACTIVITY_CREATE' &&
    $lastAudit['entity_type'] === 'activities' &&
    $lastAudit['entity_id'] === (string) $newActId,
    'AUDIT-03',
    'audit_log ghi nhận chính xác sự kiện ACTIVITY_CREATE cho hoạt động mới tạo',
    "Log ID: " . ($lastAudit['id'] ?? 'none')
);

// Xác nhận chuỗi hash sau khi tạo hoạt động
$chainAfterCreateValid = $repo->verifyAuditLogChain();
assertEmpirical($chainAfterCreateValid, 'AUDIT-04', 'Chuỗi mã băm SHA-256 tiếp tục toàn vẹn sau khi ghi ACTIVITY_CREATE');

// 3.3 Cập nhật hồ sơ Tình nguyện viên (SV001) -> Xác nhận ghi PROFILE_UPDATE và hash chain hợp lệ
$resVolProfile = httpPost($baseUrl . '/?action=update_profile', [
    'full_name' => 'Nguyễn Khánh Linh',
    'phone' => '0901234567',
    'chapter_name' => 'Chi đoàn KTPM-01',
    'max_shifts_per_week' => 4,
    'lat' => 10.7769,
    'lng' => 106.7009,
    'skills' => [1 => 3, 2 => 2],
], $volCookie);

$lastAuditVol = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
assertEmpirical(
    is_array($lastAuditVol) &&
    $lastAuditVol['action'] === 'PROFILE_UPDATE' &&
    $lastAuditVol['entity_type'] === 'volunteers',
    'AUDIT-05',
    'audit_log ghi nhận chính xác sự kiện PROFILE_UPDATE cho Tình nguyện viên',
    "Log ID: " . ($lastAuditVol['id'] ?? 'none')
);

$chainAfterVolValid = $repo->verifyAuditLogChain();
assertEmpirical($chainAfterVolValid, 'AUDIT-06', 'Chuỗi mã băm SHA-256 toàn vẹn sau PROFILE_UPDATE của Tình nguyện viên');

// 3.4 Cập nhật hồ sơ Ban Chỉ huy (Coordinator) -> Xác nhận ghi PROFILE_UPDATE cho users
$resCoordProfile = httpPost($baseUrl . '/?action=update_profile', [
    'full_name' => 'Nguyễn Phương Thảo (Chỉ huy trưởng)',
], $coordCookie);

$lastAuditCoord = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
assertEmpirical(
    is_array($lastAuditCoord) &&
    $lastAuditCoord['action'] === 'PROFILE_UPDATE' &&
    $lastAuditCoord['entity_type'] === 'users',
    'AUDIT-07',
    'audit_log ghi nhận chính xác sự kiện PROFILE_UPDATE cho Cán bộ Ban Chỉ huy',
    "Log ID: " . ($lastAuditCoord['id'] ?? 'none')
);

$chainAfterCoordValid = $repo->verifyAuditLogChain();
assertEmpirical($chainAfterCoordValid, 'AUDIT-08', 'Chuỗi mã băm SHA-256 toàn vẹn sau PROFILE_UPDATE của Ban Chỉ huy');

// 3.5 Kiểm tra tính bất biến (Immutability): Trigger PostgreSQL trg_protect_audit_log phải chặn UPDATE và DELETE
$updateBlocked = false;
try {
    $pdo->exec("UPDATE audit_log SET reason = 'Tampered Reason' WHERE id = " . (int)$lastAuditCoord['id']);
} catch (\PDOException $e) {
    $updateBlocked = str_contains($e->getMessage(), 'AUDIT_LOG_IMMUTABLE') || str_contains($e->getMessage(), 'trg_protect_audit_log');
}
assertEmpirical($updateBlocked, 'AUDIT-09', 'Trigger trg_protect_audit_log chặn đứng mọi thao tác UPDATE trên audit_log');

$deleteBlocked = false;
try {
    $pdo->exec("DELETE FROM audit_log WHERE id = " . (int)$lastAuditCoord['id']);
} catch (\PDOException $e) {
    $deleteBlocked = str_contains($e->getMessage(), 'AUDIT_LOG_IMMUTABLE') || str_contains($e->getMessage(), 'trg_protect_audit_log');
}
assertEmpirical($deleteBlocked, 'AUDIT-10', 'Trigger trg_protect_audit_log chặn đứng mọi thao tác DELETE trên audit_log');

// =============================================================================
// PHẦN 4: ADVERSARIAL STRESS TEST: CANDIDATE INSPECTOR API & DOM VALIDATION
// =============================================================================
echo "\n▶ [PHẦN 4/4] Khảo Thí API Hồ Sơ Ứng Viên, Liên Kết Điều Hướng & Cấu Trúc HTML...\n";

// 4.1 Tra cứu hồ sơ ứng viên hợp lệ qua Coordinator
$resCandValid = httpGet($baseUrl . '/?action=api_candidate_profile&volunteer_id=1', $coordCookie);
$candValidJson = json_decode($resCandValid['body'], true);
assertEmpirical(
    $resCandValid['code'] === 200 &&
    is_array($candValidJson) &&
    ($candValidJson['ok'] ?? false) === true &&
    isset($candValidJson['data']['volunteer']['student_code']) &&
    $candValidJson['data']['volunteer']['student_code'] === 'SV001',
    'API-01',
    'api_candidate_profile phản hồi HTTP 200 kèm đầy đủ payload JSON cho SV001'
);

// 4.2 Tra cứu hồ sơ ứng viên không tồn tại (volunteer_id = 999999)
$resCandNotFound = httpGet($baseUrl . '/?action=api_candidate_profile&volunteer_id=999999', $coordCookie);
$candNotFoundJson = json_decode($resCandNotFound['body'], true);
assertEmpirical(
    $resCandNotFound['code'] === 404 &&
    is_array($candNotFoundJson) &&
    ($candNotFoundJson['ok'] ?? true) === false,
    'API-02',
    'api_candidate_profile phản hồi chuẩn xác HTTP 404 khi ID không tồn tại'
);

// 4.3 Tra cứu hồ sơ ứng viên với ID phi số (volunteer_id = invalid_text)
$resCandInvalid = httpGet($baseUrl . '/?action=api_candidate_profile&volunteer_id=invalid_text', $coordCookie);
$candInvalidJson = json_decode($resCandInvalid['body'], true);
assertEmpirical(
    ($resCandInvalid['code'] === 400 || $resCandInvalid['code'] === 404) &&
    is_array($candInvalidJson) &&
    ($candInvalidJson['ok'] ?? true) === false,
    'API-03',
    'api_candidate_profile xử lý an toàn tham số phi số và trả về mã lỗi chuẩn (HTTP 400/404)',
    'Code: ' . $resCandInvalid['code']
);

// 4.4 Kiểm tra liên kết Chiến dịch trên Trang chủ & Footer: Trỏ chuẩn xác về ?view=campaigns&activity_id=X
$resHome = httpGet($baseUrl . '/?view=home');
// Trích xuất các thẻ Xem chi tiết chiến dịch
preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>\s*Xem chi tiết chiến dịch\s*<\/a>/iu', $resHome['body'], $detailMatches);
$allHomeCardsPointToCampaigns = !empty($detailMatches[1]);
foreach ($detailMatches[1] as $href) {
    if (!str_contains($href, 'view=campaigns') || !str_contains($href, 'activity_id=')) {
        $allHomeCardsPointToCampaigns = false;
        break;
    }
}

// Kiểm tra 3 link chiến dịch ở Footer
$footerCampaignLinksValid = str_contains($resHome['body'], 'href="?view=campaigns&activity_id=1"') &&
                            str_contains($resHome['body'], 'href="?view=campaigns&activity_id=2"') &&
                            str_contains($resHome['body'], 'href="?view=campaigns&activity_id=3"');

assertEmpirical(
    $allHomeCardsPointToCampaigns && $footerCampaignLinksValid,
    'NAV-01',
    'Các nút Xem chi tiết chiến dịch và Footer dẫn chuẩn xác ?view=campaigns&activity_id=X, không rơi nhầm coordinator/volunteer'
);

// 4.5 Kiểm tra tính đóng kín ngữ nghĩa của thẻ HTML <main> trên các view chính
$resCoordinatorView = httpGet($baseUrl . '/?view=coordinator', $coordCookie);
$mainOpenCount = substr_count($resCoordinatorView['body'], '<main');
$mainCloseCount = substr_count($resCoordinatorView['body'], '</main>');
assertEmpirical(
    $mainOpenCount === $mainCloseCount && $mainOpenCount > 0,
    'HTML-01',
    sprintf('Cân bằng tuyệt đối thẻ HTML <main> (%d mở / %d đóng) trên view=coordinator', $mainOpenCount, $mainCloseCount)
);

// =============================================================================
// PHẦN 5: DỌN DẸP AN TOÀN DỮ LIỆU THỬ NGHIỆM
// =============================================================================
if ($newActId > 0) {
    $pdo->exec("DELETE FROM activities WHERE id = {$newActId}");
}

// Khôi phục tên hiển thị chuẩn cho Coordinator và Volunteer
$pdo->exec("UPDATE users SET full_name = 'Nguyễn Phương Thảo (Điều phối viên)' WHERE id = 31");
$pdo->exec("UPDATE users SET full_name = 'Nguyễn Khánh Linh' WHERE id = 1");
$pdo->exec("UPDATE volunteers SET chapter_name = 'Chi đoàn KTPM-01', phone = '0900000001' WHERE user_id = 1");

// Tổng kết
echo "\n------------------------------------------------------------------------\n";
echo sprintf(" TỔNG KẾT BÀI KIỂM THỬ THỰC NGHIỆM: [%d/%d ĐẠT] (%.1f%%)\n", $passed, $total, ($passed / max(1, $total)) * 100);
if ($failed === 0) {
    echo " ✔ KẾT LUẬN: TOÀN BỘ RÀO CẢN RBAC, BẢO MẬT TUYẾN ĐƯỜNG, TRANSACTION ROLLBACK VÀ\n";
    echo "   CHUỖI MÃ BĂM AUDIT_LOG SHA-256 ĐẠT TIÊU CHUẨN AN TOÀN VÀ TOÀN VẸN 100%!\n";
} else {
    echo " ✘ CẢNH BÁO: PHÁT HIỆN {$failed} THẤT BẠI TRONG QUÁ TRÌNH KIỂM TOÁN THỰC NGHIỆM!\n";
}
echo "========================================================================\n";

exit($failed === 0 ? 0 : 1);

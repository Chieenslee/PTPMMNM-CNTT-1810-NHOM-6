<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ TỰ ĐỘNG PHÂN HỆ BẢN ĐỒ SỐ GIS & ĐỊNH VỊ GPS
 * (AUTOMATED TEST SUITE FOR GIS MAP, HAVERSINE RADIUS FILTER & GEOLOCATION)
 * ==============================================================================
 *
 * Kiểm tra 5 trụ cột chức năng theo yêu cầu đề tài:
 * 1. Thuật toán cự ly hình cầu Haversine & Bộ lọc bán kính GPS (<3km, <5km, <10km, <15km)
 * 2. Tính toàn vẹn tọa độ GPS thực tế 36 ca trực tại TP.HCM (Trường ĐH, Bệnh viện, Tuyến đường, Kênh mương)
 * 3. Phân loại màu Marker (Y tế đỏ, Môi trường xanh, Tiếp sức vàng cam, Mùa hè xanh xanh dương) & Popup quân số
 * 4. Tích hợp REST API GIS (?action=api_shifts_gis) kèm payload JSON chuẩn và trạng thái tuyển quân
 * 5. Tuyến đường giao diện ?view=map (Leaflet + OpenStreetMap) & Nút chuyển chế độ trên ?view=campaigns
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Service\GisService;

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
echo " XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ PHÂN HỆ BẢN ĐỒ SỐ GIS & ĐỊNH VỊ GPS\n";
echo " (GIS MAP INTERACTION, HAVERSINE RADIUS FILTER & REAL GPS DATA)\n";
echo "========================================================================\n";
echo " Thư mục dự án:  " . $rootDir . "\n";
echo " Host kiểm thử:  " . $baseUrl . "\n";
echo " Thời điểm chạy: " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

$pdo = Database::getConnection();
$gisService = new GisService($pdo);

// ============================================================================
// PHA 1: KIỂM THỬ CÔNG THỨC HAVERSINE CỰ LY HÌNH CẦU & BỘ LỌC BÁN KÍNH GPS
// ============================================================================
echo "▶ [PHA 1/5] Kiểm thử Công thức Cự ly Hình cầu Haversine & Bộ lọc Bán kính...\n";

// 1.1.1 Trùng tọa độ = 0.00 km
$dZero = GisService::haversineDistanceKm(10.7769, 106.7009, 10.7769, 106.7009);
assertTest($dZero === 0.0, '1.1.1', 'Cự ly giữa hai điểm trùng tọa độ bằng chính xác 0.00 km');

// 1.1.2 Bến Thành đến ĐH Bách Khoa TP.HCM (268 Lý Thường Kiệt, Q.10) ~ 4.39 km
$dBachKhoa = GisService::haversineDistanceKm(10.7725, 106.6980, 10.7725, 106.6578);
assertTest(abs($dBachKhoa - 4.39) <= 0.1, '1.1.2', sprintf('Cự ly Bến Thành tới ĐH Bách Khoa Q.10 đạt chuẩn ~4.39 km (Thực tế: %.2f km)', $dBachKhoa));

// 1.1.3 Bến Thành đến Cần Giờ (Rừng Sác) ~ 35.5 km
$dCanGio = GisService::haversineDistanceKm(10.7769, 106.7009, 10.5120, 106.8850);
assertTest(abs($dCanGio - 35.5) <= 1.5, '1.1.3', sprintf('Cự ly Bến Thành tới Chiến khu Rừng Sác Cần Giờ đạt ~35.5 km (Thực tế: %.2f km)', $dCanGio));

// 1.1.4 Bến Thành đến ĐHQG Thủ Đức ~ 16.1 km
$dThuDuc = GisService::haversineDistanceKm(10.7769, 106.7009, 10.8752, 106.8007);
assertTest(abs($dThuDuc - 15.5) <= 1.5, '1.1.4', sprintf('Cự ly Bến Thành tới Làng ĐHQG TP. Thủ Đức đạt ~15.5 km (Thực tế: %.2f km)', $dThuDuc));

// 1.1.5 Tính đối xứng: distance(A, B) === distance(B, A)
$dSym1 = GisService::haversineDistanceKm(10.7932, 106.6978, 10.7612, 106.7025);
$dSym2 = GisService::haversineDistanceKm(10.7612, 106.7025, 10.7932, 106.6978);
assertTest($dSym1 === $dSym2, '1.1.5', 'Tính đối xứng hình cầu hoàn hảo: distance(A, B) === distance(B, A)');

// 1.2.1 Bộ lọc bán kính < 3km từ Bến Thành lọc được 17 ca trực trung tâm (Quận 1, 3, 4, 5)
$res3km = $gisService->getShiftsGisPayload(null, 10.7769, 106.7009, 3.0);
assertTest($res3km['total'] === 17, '1.2.1', sprintf('Bộ lọc bán kính < 3km từ Bến Thành trả về chính xác 17 ca trực trung tâm (Thực tế: %d ca)', $res3km['total']));

// 1.2.2 Bộ lọc bán kính < 5km mở rộng bao phủ 22 ca trực (thêm Q.10, Phú Nhuận, Bình Thạnh)
$res5km = $gisService->getShiftsGisPayload(null, 10.7769, 106.7009, 5.0);
assertTest($res5km['total'] === 22, '1.2.2', sprintf('Bộ lọc bán kính < 5km mở rộng bao phủ đúng 22 ca trực (Thực tế: %d ca)', $res5km['total']));

// 1.2.3 Bộ lọc bán kính < 10km bao phủ 26 ca trực (thêm Q.7, Tân Bình, Gò Vấp)
$res10km = $gisService->getShiftsGisPayload(null, 10.7769, 106.7009, 10.0);
assertTest($res10km['total'] === 26, '1.2.3', sprintf('Bộ lọc bán kính < 10km bao phủ đúng 26 ca trực (Thực tế: %d ca)', $res10km['total']));

// 1.2.4 Bộ lọc bán kính < 15km bao phủ 29 ca trực
$res15km = $gisService->getShiftsGisPayload(null, 10.7769, 106.7009, 15.0);
assertTest($res15km['total'] === 29, '1.2.4', sprintf('Bộ lọc bán kính < 15km bao phủ đúng 29 ca trực (Thực tế: %d ca)', $res15km['total']));

// 1.2.5 Mọi ca trực trả về khi lọc bán kính đều có distance_km <= radius
$allWithinRadius = true;
foreach ($res5km['shifts'] as $s) {
    if (!isset($s['distance_km']) || $s['distance_km'] > 5.0) {
        $allWithinRadius = false;
        break;
    }
}
assertTest($allWithinRadius, '1.2.5', '100% ca trực trả về khi lọc bán kính thỏa mãn điều kiện cự ly (distance_km <= radius)');

// 1.2.6 Bán kính không giới hạn (ALL / null) trả về trọn vẹn 36 ca trực
$resAll = $gisService->getShiftsGisPayload();
assertTest($resAll['total'] === 36, '1.2.6', 'Bán kính không giới hạn (ALL) trả về đầy đủ toàn bộ 36 ca trực');

// ============================================================================
// PHA 2: KIỂM THỬ TOÀN VẸN TỌA ĐỘ GPS THỰC TẾ 36 CA TRỰC TP.HCM
// ============================================================================
echo "\n▶ [PHA 2/5] Kiểm thử Toàn vẹn Tọa độ GPS Thực tế 36 Ca trực TP.HCM...\n";

// 2.1.1 Đủ 36 ca trực
assertTest(count($resAll['shifts']) === 36, '2.1.1', 'Tổng số ca trực trong phân hệ GIS đạt chuẩn 36 ca trực (12 ca/chiến dịch × 3 chiến dịch)');

// 2.1.2 Vĩ độ trong TP.HCM: 10.3° <= lat <= 11.2°
$allLatValid = true;
foreach ($resAll['shifts'] as $s) {
    if ($s['lat'] < 10.3 || $s['lat'] > 11.2) {
        $allLatValid = false;
        break;
    }
}
assertTest($allLatValid, '2.1.2', '100% (36/36) ca trực có vĩ độ (latitude) thực tế hợp lệ thuộc địa phận TP.HCM (10.3° – 11.2°)');

// 2.1.3 Kinh độ trong TP.HCM: 106.3° <= lng <= 107.1°
$allLngValid = true;
foreach ($resAll['shifts'] as $s) {
    if ($s['lng'] < 106.3 || $s['lng'] > 107.1) {
        $allLngValid = false;
        break;
    }
}
assertTest($allLngValid, '2.1.3', '100% (36/36) ca trực có kinh độ (longitude) thực tế hợp lệ thuộc địa phận TP.HCM (106.3° – 107.1°)');

// 2.1.4 Địa danh không rỗng và rõ ràng
$allLocNonEmpty = true;
foreach ($resAll['shifts'] as $s) {
    if (empty($s['location_name']) || strlen(trim($s['location_name'])) < 5) {
        $allLocNonEmpty = false;
        break;
    }
}
assertTest($allLocNonEmpty, '2.1.4', '100% (36/36) ca trực có địa danh cụ thể (location_name) chi tiết và không rỗng');

// 2.1.5 Bao phủ các loại hình thực tế: Bệnh viện, Đại học, Tuyến đường, Kênh mương
$locNamesAll = implode(' ', array_column($resAll['shifts'], 'location_name'));
$hasHospital = str_contains($locNamesAll, 'Bệnh viện');
$hasUniv     = str_contains($locNamesAll, 'ĐH') || str_contains($locNamesAll, 'Đại học');
$hasCanal    = str_contains($locNamesAll, 'Kênh') || str_contains($locNamesAll, 'Rạch');
$hasPark     = str_contains($locNamesAll, 'Công viên') || str_contains($locNamesAll, 'Tuyến');
assertTest($hasHospital && $hasUniv && $hasCanal && $hasPark, '2.1.5', 'Bao phủ trọn vẹn 4 nhóm địa danh thực tế: Bệnh viện, Trường ĐH/THPT, Kênh rạch, Công viên/Tuyến đường');

// 2.2.1 D1 (Chủ Nhật Xanh) có đủ 12 ca trực với địa danh kênh rạch, công viên
$d1Shifts = array_filter($resAll['shifts'], fn($s) => (int)$s['activity_id'] === 1);
assertTest(count($d1Shifts) === 12, '2.2.1', 'Chiến dịch D1 (Chủ Nhật Xanh) chứa đầy đủ 12 ca trực tại Nhiêu Lộc, Tàu Hủ, Kênh Tẻ, Gia Định...');

// 2.2.2 D2 (Tiếp Sức Mùa Thi) có đủ 12 ca trực tại điểm thi, trường ĐH, bệnh viện
$d2Shifts = array_filter($resAll['shifts'], fn($s) => (int)$s['activity_id'] === 2);
assertTest(count($d2Shifts) === 12, '2.2.2', 'Chiến dịch D2 (Tiếp Sức Mùa Thi) chứa đầy đủ 12 ca trực tại ĐH Bách Khoa, ĐH KHTN, BV Chợ Rẫy, Gia Định...');

// 2.2.3 D3 (Mùa Hè Xanh) có đủ 12 ca trực tại Thủ Đức, Cần Giờ, Hóc Môn
$d3Shifts = array_filter($resAll['shifts'], fn($s) => (int)$s['activity_id'] === 3);
assertTest(count($d3Shifts) === 12, '2.2.3', 'Chiến dịch D3 (Mùa Hè Xanh) chứa đầy đủ 12 ca trực tại ĐHQG Thủ Đức, NVH Thanh niên, Cần Giờ, Hóc Môn...');

// ============================================================================
// PHA 3: KIỂM THỬ PHÂN LOẠI MÀU MARKER & POPUP THÔNG TIN QUÂN SỐ
// ============================================================================
echo "\n▶ [PHA 3/5] Kiểm thử Phân loại Màu Marker & Popup Thông tin Quân số...\n";

// 3.1.1 Y tế đỏ (#EF4444)
$redShifts = array_filter($resAll['shifts'], fn($s) => $s['color_category'] === 'red');
assertTest(count($redShifts) >= 6 && $resAll['shifts'][0]['color_hex'] === '#EF4444', '3.1.1', sprintf('Marker phân loại Y TẾ ĐỎ (#EF4444) cho các ca sơ cứu/cấp cứu/bệnh viện (Số ca: %d)', count($redShifts)));

// 3.1.2 Môi trường xanh (#10B981)
$greenShifts = array_filter($resAll['shifts'], fn($s) => $s['color_category'] === 'green');
assertTest(count($greenShifts) >= 10, '3.1.2', sprintf('Marker phân loại MÔI TRƯỜNG XANH (#10B981) cho ca làm sạch kênh, trồng cây, thu gom rác (Số ca: %d)', count($greenShifts)));

// 3.1.3 Tiếp sức mùa thi vàng cam (#F59E0B)
$orangeShifts = array_filter($resAll['shifts'], fn($s) => $s['color_category'] === 'orange');
assertTest(count($orangeShifts) >= 8, '3.1.3', sprintf('Marker phân loại TIẾP SỨC MÙA THI VÀNG CAM (#F59E0B) cho điểm thi, đưa đón thí sinh, điều tiết (Số ca: %d)', count($orangeShifts)));

// 3.1.4 Mùa hè xanh xanh dương (#0057B8)
$blueShifts = array_filter($resAll['shifts'], fn($s) => $s['color_category'] === 'blue');
assertTest(count($blueShifts) >= 6, '3.1.4', sprintf('Marker phân loại MÙA HÈ XANH XANH DƯƠNG (#0057B8) cho hoạt động thanh niên, kỹ thuật số, dạy học (Số ca: %d)', count($blueShifts)));

// 3.1.5 Đầy đủ cả 4 nhóm màu chuẩn
$allColorsPresent = count($redShifts) > 0 && count($greenShifts) > 0 && count($orangeShifts) > 0 && count($blueShifts) > 0;
assertTest($allColorsPresent, '3.1.5', 'Bao phủ chuẩn xác đầy đủ 4 nhóm màu marker: Y tế đỏ, Môi trường xanh, Tiếp sức vàng cam, Mùa hè xanh xanh dương');

// 3.2.1 Popup chứa đầy đủ định mức quân số min/max/assigned
$firstShift = $resAll['shifts'][0];
$hasQuotaFields = isset($firstShift['min_people'], $firstShift['max_people'], $firstShift['assigned_count']);
assertTest($hasQuotaFields && $firstShift['max_people'] >= $firstShift['min_people'], '3.2.1', 'Popup thông tin cung cấp đầy đủ định mức quân số (min_people, max_people, assigned_count)');

// 3.2.2 Trạng thái tuyển quân recruiting_status hợp lệ
$validStatuses = ['OPEN', 'FULL', 'URGENT', 'LOCKED'];
$allStatusesValid = true;
foreach ($resAll['shifts'] as $s) {
    if (!in_array($s['recruiting_status'], $validStatuses, true)) {
        $allStatusesValid = false;
        break;
    }
}
assertTest($allStatusesValid, '3.2.2', 'Trạng thái tuyển quân recruiting_status phản ánh chính xác: OPEN, FULL, URGENT hoặc LOCKED');

// 3.2.3 ID ca trực hợp lệ phục vụ điều hướng ứng tuyển 1-chạm (?view=shift_detail&id={id})
$allIdsValid = true;
foreach ($resAll['shifts'] as $s) {
    if ($s['id'] <= 0 || empty($s['code'])) {
        $allIdsValid = false;
        break;
    }
}
assertTest($allIdsValid, '3.2.3', '100% ca trực có mã ID và CODE hợp lệ phục vụ nút điều hướng ứng tuyển 1-chạm (?view=shift_detail)');

// ============================================================================
// PHA 4: KIỂM THỬ TÍCH HỢP REST API GIS (?action=api_shifts_gis)
// ============================================================================
echo "\n▶ [PHA 4/5] Kiểm thử Tích hợp Tuyến đường REST API (?action=api_shifts_gis)...\n";

// 4.1.1 HTTP 200
$resApi = fetchHttp($baseUrl . '/?action=api_shifts_gis');
assertTest($resApi['code'] === 200, '4.1.1', 'Endpoint ?action=api_shifts_gis phản hồi chính xác mã HTTP 200');

// 4.1.2 Content-Type JSON
assertTest(str_contains($resApi['headers'], 'application/json'), '4.1.2', 'Header phản hồi chứa Content-Type: application/json chuẩn UTF-8');

// 4.1.3 JSON hợp lệ & ok=true
$dataApi = json_decode($resApi['body'], true);
assertTest(is_array($dataApi) && ($dataApi['ok'] ?? false) === true, '4.1.3', 'Payload JSON trả về hợp lệ với trường nhận diện ok = true');

// 4.1.4 Mặc định trả về 36 ca
assertTest(($dataApi['total'] ?? 0) === 36 && count($dataApi['shifts'] ?? []) === 36, '4.1.4', 'API mặc định không truyền tham số trả về đầy đủ toàn bộ 36 ca trực');

// 4.2.1 activity_id=1 trả về 12 ca
$resApiD1 = fetchHttp($baseUrl . '/?action=api_shifts_gis&activity_id=1');
$dataD1 = json_decode($resApiD1['body'], true);
assertTest(($dataD1['total'] ?? 0) === 12, '4.2.1', 'Tham số activity_id=1 trả về đúng 12 ca trực của Chiến dịch Chủ Nhật Xanh');

// 4.2.2 activity_id=2 trả về 12 ca
$resApiD2 = fetchHttp($baseUrl . '/?action=api_shifts_gis&activity_id=2');
$dataD2 = json_decode($resApiD2['body'], true);
assertTest(($dataD2['total'] ?? 0) === 12, '4.2.2', 'Tham số activity_id=2 trả về đúng 12 ca trực của Chiến dịch Tiếp Sức Mùa Thi');

// 4.2.3 activity_id=3 trả về 12 ca
$resApiD3 = fetchHttp($baseUrl . '/?action=api_shifts_gis&activity_id=3');
$dataD3 = json_decode($resApiD3['body'], true);
assertTest(($dataD3['total'] ?? 0) === 12, '4.2.3', 'Tham số activity_id=3 trả về đúng 12 ca trực của Chiến dịch Mùa Hè Xanh');

// 4.3.1 Lọc bán kính GPS qua API: lat=10.7769&lng=106.7009&radius=5
$resApiRad5 = fetchHttp($baseUrl . '/?action=api_shifts_gis&lat=10.7769&lng=106.7009&radius=5');
$dataRad5 = json_decode($resApiRad5['body'], true);
assertTest(($dataRad5['total'] ?? 0) === 22, '4.3.1', 'Truyền tham số GPS lat, lng và radius=5 lọc chính xác 22 ca trực trong bán kính 5km');

// 4.3.2 Sắp xếp tăng dần theo khoảng cách gần nhất
$shiftsRad5 = $dataRad5['shifts'] ?? [];
$isSortedByDist = true;
for ($i = 0; $i < count($shiftsRad5) - 1; $i++) {
    if (($shiftsRad5[$i]['distance_km'] ?? 0) > ($shiftsRad5[$i + 1]['distance_km'] ?? 0)) {
        $isSortedByDist = false;
        break;
    }
}
assertTest($isSortedByDist, '4.3.2', 'Danh sách ca trực trả về khi lọc bán kính được sắp xếp tăng dần theo cự ly gần nhất');

// 4.3.3 Bán kính hẹp radius=3 trả về đúng 17 ca
$resApiRad3 = fetchHttp($baseUrl . '/?action=api_shifts_gis&lat=10.7769&lng=106.7009&radius=3');
$dataRad3 = json_decode($resApiRad3['body'], true);
assertTest(($dataRad3['total'] ?? 0) === 17, '4.3.3', 'Bán kính hẹp radius=3 trả về chính xác 17 ca trực trung tâm (distance_km <= 3.0)');

// 4.4.1 Lọc theo Kỹ năng: skill=FIRST_AID
$resApiSkill = fetchHttp($baseUrl . '/?action=api_shifts_gis&skill=FIRST_AID');
$dataSkill = json_decode($resApiSkill['body'], true);
$allFirstAid = true;
foreach ($dataSkill['shifts'] ?? [] as $s) {
    if ($s['skill_code'] !== 'FIRST_AID') {
        $allFirstAid = false;
        break;
    }
}
assertTest($allFirstAid && count($dataSkill['shifts'] ?? []) > 0, '4.4.1', sprintf('Tham số skill=FIRST_AID lọc chuẩn xác %d ca trực chuyên môn Sơ cứu Y tế', count($dataSkill['shifts'] ?? [])));

// 4.4.2 Tọa độ không hợp lệ trả về an toàn
$resApiInvalid = fetchHttp($baseUrl . '/?action=api_shifts_gis&lat=invalid_lat&radius=abc');
$dataInvalid = json_decode($resApiInvalid['body'], true);
assertTest(($dataInvalid['ok'] ?? false) === true && ($dataInvalid['total'] ?? 0) === 36, '4.4.2', 'API xử lý an toàn khi gặp tham số tọa độ không hợp lệ (fallback về danh sách toàn bộ)');

// ============================================================================
// PHA 5: KIỂM THỬ TUYẾN ĐƯỜNG GIAO DIỆN ?view=map & NÚT CHUYỂN CHẾ ĐỘ
// ============================================================================
echo "\n▶ [PHA 5/5] Kiểm thử Tuyến đường Giao diện ?view=map & Nút Chuyển Chế độ...\n";

// 5.1.1 Tuyến đường ?view=map HTTP 200
$resMap = fetchHttp($baseUrl . '/?view=map');
assertTest($resMap['code'] === 200, '5.1.1', 'Tuyến đường ?view=map phản hồi chính xác mã HTTP 200');

// 5.1.2 Nhúng thư viện Leaflet (leaflet.js hoặc leaflet.css)
assertTest(str_contains($resMap['body'], 'leaflet'), '5.1.2', 'Giao diện nhúng thư viện bản đồ tương tác Leaflet (leaflet.css / leaflet.js)');

// 5.1.3 Lớp nền OpenStreetMap
assertTest(str_contains($resMap['body'], 'openstreetmap.org'), '5.1.3', 'Giao diện tích hợp lớp nền bản đồ số mở OpenStreetMap');

// 5.1.4 Khung chứa bản đồ id="gis-map"
assertTest(str_contains($resMap['body'], 'id="gis-map"'), '5.1.4', 'Khung chứa bản đồ id="gis-map" hiện diện đầy đủ trên DOM');

// 5.1.5 Tiêu đề động chính xác
assertTest(str_contains($resMap['body'], 'Bản đồ') && str_contains($resMap['body'], 'Xanh Hub'), '5.1.5', 'Tiêu đề trang <title> kế thừa đúng chuẩn: Bản đồ Số GIS & Định vị Điểm Tình nguyện — Xanh Hub');

// 5.2.1 Nút chọn bán kính GPS: <3km, <5km, <10km, <15km
$hasRadiusBtns = str_contains($resMap['body'], 'data-radius="3"')
    && str_contains($resMap['body'], 'data-radius="5"')
    && str_contains($resMap['body'], 'data-radius="10"')
    && str_contains($resMap['body'], 'data-radius="15"');
assertTest($hasRadiusBtns, '5.2.1', 'Giao diện tích hợp đầy đủ 4 nút lọc bán kính GPS: <3km, <5km, <10km, <15km');

// 5.2.2 Nút "Vị trí của tôi"
assertTest(str_contains($resMap['body'], 'id="btn-my-location"') || str_contains($resMap['body'], 'Vị trí của tôi'), '5.2.2', 'Giao diện cung cấp nút định vị vị trí người dùng "Vị trí của tôi" (GPS Geolocation)');

// 5.2.3 Bộ chọn Chiến dịch
assertTest(str_contains($resMap['body'], '?view=map&amp;activity_id=1') || str_contains($resMap['body'], '?view=map&activity_id=1'), '5.2.3', 'Giao diện hỗ trợ bộ chọn chuyển đổi nhanh giữa các Chiến dịch (D1, D2, D3)');

// 5.2.4 Danh mục ca trực & Nút ứng tuyển 1-chạm
assertTest(str_contains($resMap['body'], 'gis-shifts-list') && str_contains($resMap['body'], '?view=shift_detail'), '5.2.4', 'Tích hợp danh sách ca trực bên cạnh bản đồ kèm nút điều hướng ứng tuyển 1-chạm');

// 5.3.1 Nút chuyển chế độ "Bản đồ" trên ?view=campaigns
$resCamp = fetchHttp($baseUrl . '/?view=campaigns');
assertTest(str_contains($resCamp['body'], '?view=map'), '5.3.1', 'Tuyến đường ?view=campaigns tích hợp nút chuyển chế độ "Bản đồ" (?view=map)');

// 5.3.2 Nút có ID btn-camp-map
assertTest(str_contains($resCamp['body'], 'id="btn-camp-map"'), '5.3.2', 'Nút chuyển chế độ trên trang khám phá chiến dịch mang ID định danh #btn-camp-map');

// 5.3.3 Thanh Header có liên kết tới ?view=map
assertTest(str_contains($resCamp['body'], 'Bản đồ GIS'), '5.3.3', 'Thanh điều hướng Header chính có liên kết trực tiếp tới phân hệ Bản đồ GIS (?view=map)');

// 5.4.1 Không có liên kết chết href="#"
$hasDeadHashHref = (bool) preg_match('/<a\s+[^>]*href=["\']#["\']/i', $resMap['body']);
assertTest(!$hasDeadHashHref, '5.4.1', 'Không phát hiện bất kỳ liên kết chết dạng href="#" nào trên giao diện bản đồ');

// 5.4.2 Tính đóng kín HTML: Số lượng <main khớp </main>
$indexContent = file_get_contents($rootDir . '/public/index.php');
$openMain = substr_count($indexContent, '<main');
$closeMain = substr_count($indexContent, '</main>');
assertTest($openMain === $closeMain, '5.4.2', "Tính đóng kín HTML chuẩn mực: Số lượng thẻ <main ({$openMain}) khớp chính xác với </main> ({$closeMain})");

echo "\n------------------------------------------------------------------------\n";
echo sprintf(" TỔNG KẾT KIỂM THỬ BẢN ĐỒ SỐ GIS: [%d/%d ĐẠT] (%.1f%%)\n", $passed, $total, ($passed / max(1, $total)) * 100);
if ($failed === 0) {
    echo " \033[32m✔ KẾT LUẬN: TOÀN BỘ PHÂN HỆ BẢN ĐỒ SỐ GIS, HAVERSINE & GEOLOCATION ĐẠT 100% PASS\033[0m\n";
} else {
    echo " \033[31m✖ KẾT LUẬN: CÒN {$failed} CA KIỂM THỬ CHƯA ĐẠT!\033[0m\n";
}
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;

/**
 * Phân hệ Dịch vụ Bản đồ Số GIS & Định vị Tình nguyện viên (GisService).
 * Cung cấp:
 * 1. Thuật toán cự ly hình cầu Haversine chuẩn xác (Earth radius R = 6371.0 km).
 * 2. Bộ lọc bán kính GPS (<3km, <5km, <10km, <15km).
 * 3. Phân loại màu Marker chuẩn (Y tế đỏ, Môi trường xanh, Tiếp sức vàng cam, Mùa hè xanh xanh dương).
 * 4. Truy xuất payload GIS đầy đủ 36 ca trực tại TP.HCM kèm trạng thái tuyển quân.
 */
final class GisService
{
    public const EARTH_RADIUS_KM = 6371.0;

    // Tọa độ trung tâm mặc định TP.HCM (Quảng trường Bến Thành / Trụ sở Thành Đoàn)
    public const DEFAULT_CENTER_LAT = 10.7769;
    public const DEFAULT_CENTER_LNG = 106.7009;

    /**
     * Bảng ánh xạ tọa độ GPS thực tế của 36 ca trực tại TP.HCM (12 ca/chiến dịch × 3 chiến dịch).
     * Đảm bảo tính nhất quán tuyệt đối giữa dữ liệu bộ nhớ và cơ sở dữ liệu.
     *
     * @var array<int, array{lat: float, lng: float, location: string}>
     */
    public const CANONICAL_LOCATIONS = [
        // --- CHIẾN DỊCH 1: CHỦ NHẬT XANH (ID 1..12) ---
        1 => ['lat' => 10.7932, 'lng' => 106.6978, 'location' => 'Cầu Điện Biên Phủ, Tuyến Kênh Nhiêu Lộc, Q. Bình Thạnh'],
        2 => ['lat' => 10.7887, 'lng' => 106.6946, 'location' => 'Công viên Lê Văn Tám, Hai Bà Trưng, Q. 1'],
        3 => ['lat' => 10.7875, 'lng' => 106.6850, 'location' => 'Tuyến đường Hoàng Sa – Trường Sa, Q. 3'],
        4 => ['lat' => 10.7612, 'lng' => 106.7025, 'location' => 'Bến Vân Đồn, Tuyến Kênh Tàu Hủ, Q. 4'],
        5 => ['lat' => 10.7745, 'lng' => 106.6922, 'location' => 'Công viên Tao Đàn, Trương Định, Q. 1'],
        6 => ['lat' => 10.8164, 'lng' => 106.6744, 'location' => 'Công viên Gia Định, Hoàng Minh Giám, Q. Phú Nhuận'],
        7 => ['lat' => 10.8055, 'lng' => 106.6980, 'location' => 'Tuyến Kênh Rạch Xuyên Tâm, P. 15, Q. Bình Thạnh'],
        8 => ['lat' => 10.7997, 'lng' => 106.6622, 'location' => 'Công viên Hoàng Văn Thụ, Q. Tân Bình'],
        9 => ['lat' => 10.7695, 'lng' => 106.6930, 'location' => 'Công viên 23 Tháng 9, Phạm Ngũ Lão, Q. 1'],
        10 => ['lat' => 10.8235, 'lng' => 106.7210, 'location' => 'Tuyến Bờ kè Bán đảo Thanh Đa, Q. Bình Thạnh'],
        11 => ['lat' => 10.7512, 'lng' => 106.7085, 'location' => 'Bờ kè Kênh Tẻ, Trần Xuân Soạn, Q. 7'],
        12 => ['lat' => 10.8290, 'lng' => 106.6325, 'location' => 'Tuyến Kênh Tham Lương – Bến Cát, Q. 12'],

        // --- CHIẾN DỊCH 2: TIẾP SỨC MÙA THI (ID 101..112) ---
        101 => ['lat' => 10.7725, 'lng' => 106.6578, 'location' => 'ĐH Bách Khoa TP.HCM, 268 Lý Thường Kiệt, Q. 10'],
        102 => ['lat' => 10.7629, 'lng' => 106.6823, 'location' => 'ĐH Khoa học Tự nhiên, 227 Nguyễn Văn Cừ, Q. 5'],
        103 => ['lat' => 10.7818, 'lng' => 106.6782, 'location' => 'Ga Sài Gòn, 1 Nguyễn Thông, Q. 3'],
        104 => ['lat' => 10.7578, 'lng' => 106.6595, 'location' => 'Bệnh viện Chợ Rẫy, 201B Nguyễn Chí Thanh, Q. 5'],
        105 => ['lat' => 10.7640, 'lng' => 106.6820, 'location' => 'THPT Chuyên Lê Hồng Phong, 235 Nguyễn Văn Cừ, Q. 5'],
        106 => ['lat' => 10.7601, 'lng' => 106.6835, 'location' => 'ĐH Sư Phạm TP.HCM, 280 An Dương Vương, Q. 5'],
        107 => ['lat' => 10.7812, 'lng' => 106.6905, 'location' => 'THPT Marie Curie, 159 Nam Kỳ Khởi Nghĩa, Q. 3'],
        108 => ['lat' => 10.8785, 'lng' => 106.8180, 'location' => 'Bến xe Miền Đông mới, TP. Thủ Đức'],
        109 => ['lat' => 10.7766, 'lng' => 106.6872, 'location' => 'THPT Nguyễn Thị Minh Khai, 275 Điện Biên Phủ, Q. 3'],
        110 => ['lat' => 10.7782, 'lng' => 106.6795, 'location' => 'Nút giao Vòng xoay Dân Chủ, Q. 3'],
        111 => ['lat' => 10.7828, 'lng' => 106.6958, 'location' => 'ĐH Kinh Tế TP.HCM, 59C Nguyễn Đình Chiểu, Q. 3'],
        112 => ['lat' => 10.8038, 'lng' => 106.6952, 'location' => 'Bệnh viện Nhân dân Gia Định, 1 Nơ Trang Long, Q. Bình Thạnh'],

        // --- CHIẾN DỊCH 3: MÙA HÈ XANH (ID 201..212) ---
        201 => ['lat' => 10.8805, 'lng' => 106.8062, 'location' => 'ĐH Bách Khoa TP.HCM (CS2), ĐHQG TP.HCM, TP. Thủ Đức'],
        202 => ['lat' => 10.8752, 'lng' => 106.8007, 'location' => 'Nhà Văn hóa Sinh viên ĐHQG, TP. Thủ Đức'],
        203 => ['lat' => 10.7435, 'lng' => 106.6178, 'location' => 'Bến xe Miền Tây, Kinh Dương Vương, Q. Bình Tân'],
        204 => ['lat' => 10.7801, 'lng' => 106.6975, 'location' => 'Nhà Văn hóa Thanh niên TP.HCM, 4 Phạm Ngọc Thạch, Q. 1'],
        205 => ['lat' => 10.5510, 'lng' => 106.8812, 'location' => 'Điểm trường Xã Tam Thôn Hiệp, Huyện Cần Giờ'],
        206 => ['lat' => 10.8700, 'lng' => 106.7917, 'location' => 'ĐH Nông Lâm TP.HCM, QL1A, TP. Thủ Đức'],
        207 => ['lat' => 10.7820, 'lng' => 106.6940, 'location' => 'Thành Đoàn TP.HCM, 145 Pasteur, Q. 3'],
        208 => ['lat' => 10.8385, 'lng' => 106.6025, 'location' => 'Điểm xây cầu giao thông Xã Bà Điểm, Hóc Môn'],
        209 => ['lat' => 10.4550, 'lng' => 106.8520, 'location' => 'Trạm Y tế Xã Lý Nhơn, Huyện Cần Giờ'],
        210 => ['lat' => 10.5120, 'lng' => 106.8850, 'location' => 'Khu Di tích Rừng Sác – Chiến khu Rừng Sác, Cần Giờ'],
        211 => ['lat' => 10.8512, 'lng' => 106.7720, 'location' => 'ĐH Sư phạm Kỹ thuật TP.HCM, Võ Văn Ngân, TP. Thủ Đức'],
        212 => ['lat' => 10.8805, 'lng' => 106.8375, 'location' => 'Công viên Lịch sử Văn hóa Dân tộc, TP. Thủ Đức'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tính cự ly hình cầu giữa hai tọa độ GPS (kinh/vĩ độ tính bằng độ thập phân) theo công thức Haversine.
     * Kết quả trả về tính theo kilômét (km), làm tròn 2 chữ số thập phân.
     */
    public static function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // Hai điểm trùng nhau trả về 0.00
        if (abs($lat1 - $lat2) < 0.000001 && abs($lng1 - $lng2) < 0.000001) {
            return 0.0;
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2.0) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2.0) ** 2);

        $c = 2.0 * atan2(sqrt(max(0.0, min(1.0, $a))), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Phân loại màu Marker theo 4 nhóm chuẩn:
     * - Y tế đỏ: #EF4444 (FIRST_AID hoặc ca trực bệnh viện/sơ cứu/cấp cứu)
     * - Môi trường xanh: #10B981 (D1 Chủ Nhật Xanh / làm sạch kênh / trồng cây / rác)
     * - Tiếp sức mùa thi vàng cam: #F59E0B (D2 Tiếp Sức Mùa Thi / thí sinh / điều tiết giao thông / trường thi)
     * - Mùa hè xanh xanh dương: #0057B8 (D3 Mùa Hè Xanh / kỹ thuật / dạy học / truyền thông)
     *
     * @return array{category: string, hex: string, label: string}
     */
    public static function determineMarkerColor(string $skillCode, int $activityId, string $title = ''): array
    {
        $skillUpper = strtoupper(trim($skillCode));
        $titleLower = mb_strtolower($title, 'UTF-8');

        // 1. Nhóm Y tế đỏ (Ưu tiên cao nhất)
        if (
            $skillUpper === 'FIRST_AID' ||
            str_contains($titleLower, 'sơ cứu') ||
            str_contains($titleLower, 'y tế') ||
            str_contains($titleLower, 'cấp cứu') ||
            str_contains($titleLower, 'bệnh viện')
        ) {
            return [
                'category' => 'red',
                'hex' => '#EF4444',
                'label' => 'Y tế',
            ];
        }

        // 2. Nhóm Môi trường xanh (Chiến dịch Chủ Nhật Xanh hoặc công việc môi trường)
        if (
            $activityId === 1 ||
            str_contains($titleLower, 'xanh') ||
            str_contains($titleLower, 'môi trường') ||
            str_contains($titleLower, 'trồng cây') ||
            str_contains($titleLower, 'rác') ||
            str_contains($titleLower, 'kênh') ||
            str_contains($titleLower, 'bờ kè')
        ) {
            return [
                'category' => 'green',
                'hex' => '#10B981',
                'label' => 'Môi trường',
            ];
        }

        // 3. Nhóm Tiếp sức mùa thi vàng cam (Chiến dịch Tiếp Sức Mùa Thi hoặc điều tiết giao thông)
        if (
            $activityId === 2 ||
            $skillUpper === 'DRIVING' ||
            $skillUpper === 'TRAFFIC' ||
            str_contains($titleLower, 'tiếp sức') ||
            str_contains($titleLower, 'thí sinh') ||
            str_contains($titleLower, 'điểm thi') ||
            str_contains($titleLower, 'giao thông') ||
            str_contains($titleLower, 'đưa đón')
        ) {
            return [
                'category' => 'orange',
                'hex' => '#F59E0B',
                'label' => 'Tiếp sức mùa thi',
            ];
        }

        // 4. Nhóm Mùa hè xanh xanh dương
        return [
            'category' => 'blue',
            'hex' => '#0057B8',
            'label' => 'Mùa hè xanh',
        ];
    }

    /**
     * Truy xuất toàn bộ thông tin GIS của các ca trực từ cơ sở dữ liệu kèm trạng thái tuyển quân.
     * Hỗ trợ bộ lọc theo Chiến dịch, Kỹ năng, và Bán kính Haversine quanh vị trí người dùng.
     *
     * @return array{
     *     ok: bool,
     *     total: int,
     *     center: array{lat: float, lng: float},
     *     query: array<string, mixed>,
     *     shifts: list<array<string, mixed>>,
     *     stats: array<string, mixed>
     * }
     */
    public function getShiftsGisPayload(
        ?int $activityId = null,
        ?float $userLat = null,
        ?float $userLng = null,
        ?float $radiusKm = null,
        ?string $skillFilter = null
    ): array {
        $params = [];
        $whereClauses = [];

        if ($activityId !== null && $activityId > 0) {
            $whereClauses[] = 's.activity_id = :aid';
            $params[':aid'] = $activityId;
        }

        $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Tải danh sách ca trực cùng yêu cầu kỹ năng đầu tiên & số lượng quân số đã phân công
        $sql = "
            SELECT s.id,
                   s.activity_id,
                   a.code AS campaign_code,
                   a.title AS campaign_title,
                   s.code,
                   s.title,
                   s.location_name,
                   s.lat,
                   s.lng,
                   s.start_time::text AS start_iso,
                   s.end_time::text AS end_iso,
                   EXTRACT(EPOCH FROM s.start_time)::bigint AS start_epoch,
                   EXTRACT(EPOCH FROM s.end_time)::bigint AS end_epoch,
                   s.min_people,
                   s.max_people,
                   s.priority,
                   s.is_locked,
                   sr.skill_id,
                   COALESCE(sk.code, 'LOGISTICS') AS skill_code,
                   COALESCE(sk.name, 'Hậu cần Tiếp sức') AS skill_name,
                   COALESCE(sr.min_level, 1) AS min_level,
                   COALESCE(sr.require_verified, FALSE) AS require_verified,
                   COUNT(DISTINCT asm.id) FILTER (WHERE asm.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')) AS assigned_count
              FROM shifts s
              JOIN activities a ON a.id = s.activity_id
              LEFT JOIN shift_requirements sr ON sr.id = (
                  SELECT MIN(sr2.id) FROM shift_requirements sr2 WHERE sr2.shift_id = s.id
              )
              LEFT JOIN skills sk ON sk.id = sr.skill_id
              LEFT JOIN assignments asm ON asm.shift_id = s.id
             {$whereSql}
             GROUP BY s.id, a.code, a.title, sr.skill_id, sk.code, sk.name, sr.min_level, sr.require_verified
             ORDER BY s.activity_id ASC, s.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasUserCoords = ($userLat !== null && $userLng !== null);
        $filterSkillUpper = ($skillFilter !== null && $skillFilter !== '' && $skillFilter !== 'ALL')
            ? strtoupper(trim($skillFilter))
            : null;

        $processedShifts = [];
        $colorStats = ['red' => 0, 'green' => 0, 'orange' => 0, 'blue' => 0];

        foreach ($rows as $r) {
            $shiftId = (int) $r['id'];
            $actId = (int) $r['activity_id'];
            $skillCode = (string) $r['skill_code'];
            $title = (string) $r['title'];

            // Lấy tọa độ chuẩn nếu trong DB chưa có hoặc là mặc định
            $lat = (float) $r['lat'];
            $lng = (float) $r['lng'];
            $locName = trim((string) $r['location_name']);

            if (isset(self::CANONICAL_LOCATIONS[$shiftId])) {
                $canon = self::CANONICAL_LOCATIONS[$shiftId];
                if (abs($lat - self::DEFAULT_CENTER_LAT) < 0.0001 && abs($lng - self::DEFAULT_CENTER_LNG) < 0.0001) {
                    $lat = $canon['lat'];
                    $lng = $canon['lng'];
                }
                if ($locName === '' || str_starts_with($locName, 'Điểm hoạt động')) {
                    $locName = $canon['location'];
                }
            }

            // Lọc theo Kỹ năng nếu có
            if ($filterSkillUpper !== null && strtoupper($skillCode) !== $filterSkillUpper) {
                continue;
            }

            // Tính cự ly Haversine nếu có vị trí người dùng
            $distanceKm = null;
            if ($hasUserCoords) {
                $distanceKm = self::haversineDistanceKm($userLat, $userLng, $lat, $lng);

                // Lọc theo bán kính GPS nếu có
                if ($radiusKm !== null && $radiusKm > 0.0 && $distanceKm > $radiusKm) {
                    continue;
                }
            }

            // Phân loại màu marker
            $colorInfo = self::determineMarkerColor($skillCode, $actId, $title);
            $colorCat = $colorInfo['category'];
            $colorStats[$colorCat] = ($colorStats[$colorCat] ?? 0) + 1;

            // Đánh giá trạng thái tuyển quân
            $minP = (int) $r['min_people'];
            $maxP = (int) $r['max_people'];
            $assigned = (int) $r['assigned_count'];
            $isLocked = (bool) $r['is_locked'];
            $isFull = ($assigned >= $maxP);

            $recruitingStatus = 'OPEN';
            $statusLabel = 'Đang tuyển';
            if ($isLocked) {
                $recruitingStatus = 'LOCKED';
                $statusLabel = 'Đã chốt sổ';
            } elseif ($isFull) {
                $recruitingStatus = 'FULL';
                $statusLabel = 'Đã đủ người';
            } elseif ($assigned < $minP) {
                $recruitingStatus = 'URGENT';
                $statusLabel = 'Cần khẩn cấp';
            }

            $processedShifts[] = [
                'id' => $shiftId,
                'activity_id' => $actId,
                'campaign_code' => (string) $r['campaign_code'],
                'campaign_title' => (string) $r['campaign_title'],
                'code' => (string) $r['code'],
                'title' => $title,
                'location_name' => $locName,
                'lat' => $lat,
                'lng' => $lng,
                'start_time' => date('d/m/Y H:i', (int) $r['start_epoch']),
                'end_time' => date('d/m/Y H:i', (int) $r['end_epoch']),
                'start_epoch' => (int) $r['start_epoch'],
                'end_epoch' => (int) $r['end_epoch'],
                'min_people' => $minP,
                'max_people' => $maxP,
                'assigned_count' => $assigned,
                'is_full' => $isFull,
                'is_locked' => $isLocked,
                'recruiting_status' => $recruitingStatus,
                'status_label' => $statusLabel,
                'skill_id' => (int) ($r['skill_id'] ?? 0),
                'skill_code' => $skillCode,
                'skill_name' => (string) $r['skill_name'],
                'min_level' => (int) $r['min_level'],
                'require_verified' => (bool) $r['require_verified'],
                'color_category' => $colorCat,
                'color_hex' => $colorInfo['hex'],
                'color_label' => $colorInfo['label'],
                'distance_km' => $distanceKm,
            ];
        }

        // Nếu có khoảng cách, sắp xếp theo thứ tự cự ly tăng dần (gần nhất lên đầu)
        if ($hasUserCoords) {
            usort($processedShifts, static function (array $a, array $b): int {
                return ($a['distance_km'] ?? 999999.0) <=> ($b['distance_km'] ?? 999999.0);
            });
        }

        return [
            'ok' => true,
            'total' => count($processedShifts),
            'center' => [
                'lat' => $userLat ?? self::DEFAULT_CENTER_LAT,
                'lng' => $userLng ?? self::DEFAULT_CENTER_LNG,
            ],
            'query' => [
                'activity_id' => $activityId,
                'user_lat' => $userLat,
                'user_lng' => $userLng,
                'radius_km' => $radiusKm,
                'skill' => $filterSkillUpper,
            ],
            'shifts' => $processedShifts,
            'stats' => [
                'total_shifts' => count($processedShifts),
                'by_color' => $colorStats,
            ],
        ];
    }
}

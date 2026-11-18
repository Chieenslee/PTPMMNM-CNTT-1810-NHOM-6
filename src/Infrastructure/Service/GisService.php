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
 * 4. Truy xuất payload GIS đầy đủ 36 ca trực tại TP. Hà Nội kèm trạng thái tuyển quân.
 */
final class GisService
{
    public const EARTH_RADIUS_KM = 6371.0;

    // Tọa độ trung tâm mặc định TP. Hà Nội (Hồ Hoàn Kiếm / Thành Đoàn Hà Nội)
    public const DEFAULT_CENTER_LAT = 21.0285;
    public const DEFAULT_CENTER_LNG = 105.8542;

    /**
     * Bảng ánh xạ tọa độ GPS thực tế của 36 ca trực tại TP. Hà Nội (12 ca/chiến dịch × 3 chiến dịch).
     * Đảm bảo tính nhất quán tuyệt đối giữa dữ liệu bộ nhớ và cơ sở dữ liệu.
     *
     * @var array<int, array{lat: float, lng: float, location: string}>
     */
    public const CANONICAL_LOCATIONS = [
        // --- CHIẾN DỊCH 1: CHỦ NHẬT XANH (ID 1..12) ---
        1  => ['lat' => 21.0112, 'lng' => 105.8095, 'location' => 'Cầu Cót – Tuyến Sông Tô Lịch, Đường Láng, Q. Đống Đa'],
        2  => ['lat' => 21.0170, 'lng' => 105.8430, 'location' => 'Công viên Thống Nhất, Đường Lê Duẩn, Q. Hai Bà Trưng'],
        3  => ['lat' => 21.0285, 'lng' => 105.8542, 'location' => 'Không gian Đi bộ Hồ Hoàn Kiếm, Đinh Tiên Hoàng, Q. Hoàn Kiếm'],
        4  => ['lat' => 20.9950, 'lng' => 105.8622, 'location' => 'Tuyến Bờ sông Kim Ngưu, Minh Khai, Q. Hai Bà Trưng'],
        5  => ['lat' => 21.0465, 'lng' => 105.8368, 'location' => 'Đường Thanh Niên – Ven Hồ Tây & Hồ Trúc Bạch, Q. Tây Hồ'],
        6  => ['lat' => 21.0288, 'lng' => 105.7908, 'location' => 'Công viên Cầu Giấy, Đường Thành Thái, Q. Cầu Giấy'],
        7  => ['lat' => 21.0308, 'lng' => 105.8065, 'location' => 'Công viên Thủ Lệ – Ven Hồ Thủ Lệ, Đường Bưởi, Q. Ba Đình'],
        8  => ['lat' => 21.0410, 'lng' => 105.7965, 'location' => 'Công viên Nghĩa Đô, Đường Nguyễn Văn Huyên, Q. Cầu Giấy'],
        9  => ['lat' => 21.0318, 'lng' => 105.8405, 'location' => 'Vườn hoa Lê Nin – Điện Biên Phủ, Q. Ba Đình'],
        10 => ['lat' => 21.0645, 'lng' => 105.7868, 'location' => 'Công viên Hòa Bình, Đường Phạm Văn Đồng, Q. Bắc Từ Liêm'],
        11 => ['lat' => 20.9635, 'lng' => 105.8545, 'location' => 'Công viên Yên Sở, Nút giao Pháp Vân, Q. Hoàng Mai'],
        12 => ['lat' => 20.9815, 'lng' => 105.7875, 'location' => 'Hồ Văn Quán, Khu đô thị Văn Quán, Q. Hà Đông'],

        // --- CHIẾN DỊCH 2: TIẾP SỨC MÙA THI (ID 101..112) ---
        101 => ['lat' => 21.0050, 'lng' => 105.8435, 'location' => 'ĐH Bách Khoa Hà Nội, Số 1 Đại Cồ Việt, Q. Hai Bà Trưng'],
        102 => ['lat' => 21.0378, 'lng' => 105.7815, 'location' => 'ĐH Quốc gia Hà Nội, 144 Xuân Thủy, Q. Cầu Giấy'],
        103 => ['lat' => 21.0242, 'lng' => 105.8412, 'location' => 'Ga Hà Nội, 120 Lê Duẩn, Q. Đống Đa'],
        104 => ['lat' => 21.0028, 'lng' => 105.8405, 'location' => 'Bệnh viện Bạch Mai, 78 Giải Phóng, Q. Đống Đa'],
        105 => ['lat' => 21.0432, 'lng' => 105.8325, 'location' => 'THPT Chu Văn An, Số 10 Thụy Khuê, Q. Tây Hồ'],
        106 => ['lat' => 21.0368, 'lng' => 105.7832, 'location' => 'ĐH Sư phạm Hà Nội, 136 Xuân Thủy, Q. Cầu Giấy'],
        107 => ['lat' => 21.0068, 'lng' => 105.7982, 'location' => 'THPT Chuyên Hà Nội – Amsterdam, Hoàng Minh Giám, Q. Cầu Giấy'],
        108 => ['lat' => 21.0285, 'lng' => 105.7782, 'location' => 'Bến xe Mỹ Đình, 20 Phạm Hùng, Q. Nam Từ Liêm'],
        109 => ['lat' => 20.9998, 'lng' => 105.8425, 'location' => 'ĐH Kinh tế Quốc dân, 207 Giải Phóng, Q. Hai Bà Trưng'],
        110 => ['lat' => 21.0028, 'lng' => 105.8208, 'location' => 'Nút giao Ngã Tư Sở – Tây Sơn, Q. Đống Đa'],
        111 => ['lat' => 21.0072, 'lng' => 105.8248, 'location' => 'ĐH Thủy Lợi, 175 Tây Sơn, Q. Đống Đa'],
        112 => ['lat' => 21.0272, 'lng' => 105.8482, 'location' => 'Bệnh viện Hữu nghị Việt Đức, 40 Tràng Thi, Q. Hoàn Kiếm'],

        // --- CHIẾN DỊCH 3: MÙA HÈ XANH (ID 201..212) ---
        201 => ['lat' => 21.0058, 'lng' => 105.8442, 'location' => 'Quảng trường Tạ Quang Bửu, ĐH Bách Khoa Hà Nội, Q. Hai Bà Trưng'],
        202 => ['lat' => 21.0168, 'lng' => 105.8588, 'location' => 'Cung Văn hóa Thể thao Thanh niên Hà Nội, Số 1 Tăng Bạt Hổ, Q. Hai Bà Trưng'],
        203 => ['lat' => 20.9802, 'lng' => 105.8415, 'location' => 'Bến xe Giáp Bát, Đường Giải Phóng, Q. Hoàng Mai'],
        204 => ['lat' => 21.0228, 'lng' => 105.8556, 'location' => 'Thành Đoàn Hà Nội, 14A Phan Chu Trinh, Q. Hoàn Kiếm'],
        205 => ['lat' => 21.0725, 'lng' => 105.4250, 'location' => 'Điểm trường Xã Ba Vì, Huyện Ba Vì, TP. Hà Nội'],
        206 => ['lat' => 21.0042, 'lng' => 105.9318, 'location' => 'Học viện Nông nghiệp Việt Nam, Thị trấn Trâu Quỳ, Huyện Gia Lâm'],
        207 => ['lat' => 21.0182, 'lng' => 105.8445, 'location' => 'Nhà Văn hóa Học sinh – Sinh viên Hà Nội, 37 Trần Bình Trọng, Q. Hai Bà Trưng'],
        208 => ['lat' => 21.2580, 'lng' => 105.8490, 'location' => 'Điểm xây đường giao thông nông thôn Xã Minh Phú, Huyện Sóc Sơn'],
        209 => ['lat' => 21.0450, 'lng' => 105.4580, 'location' => 'Trạm Y tế Xã Yên Bài, Huyện Ba Vì, TP. Hà Nội'],
        210 => ['lat' => 21.1125, 'lng' => 105.8735, 'location' => 'Khu Di tích Cổ Loa, Xã Cổ Loa, Huyện Đông Anh, TP. Hà Nội'],
        211 => ['lat' => 21.0035, 'lng' => 105.8428, 'location' => 'ĐH Xây dựng Hà Nội, 55 Giải Phóng, Q. Hai Bà Trưng'],
        212 => ['lat' => 21.0135, 'lng' => 105.5265, 'location' => 'Khu Đô thị ĐHQG Hà Nội tại Hòa Lạc, Huyện Thạch Thất, TP. Hà Nội'],
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

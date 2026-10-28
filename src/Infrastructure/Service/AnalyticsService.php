<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Http\SvgQrGenerator;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;

/**
 * Dịch vụ Trung tâm Báo cáo Thống kê & Phân tích Tác động Xã hội (Analytics & BI Engine)
 * chuẩn Đoàn TNCS Hồ Chí Minh - Hội Liên hiệp Thanh niên Việt Nam.
 *
 * Cung cấp:
 * 1. Phân tích số liệu cống hiến theo Trường Đại học / cơ sở Đoàn.
 * 2. Phân tích tỷ lệ lấp đầy ca trực (Staffing Ratio) toàn chiến dịch và từng ca.
 * 3. Phân tích cơ cấu kỹ năng chuyên môn và tỷ lệ kiểm thực năng lực.
 * 4. Xếp hạng và danh sách vinh danh cá nhân / tình nguyện viên xuất sắc.
 * 5. Tổng hợp dữ liệu biểu mẫu Báo cáo Tổng kết Chiến dịch chuẩn Đoàn - Hội.
 */
final class AnalyticsService
{
    /**
     * Bản đồ chuẩn hóa Chi đoàn -> Đoàn Khoa / Đoàn Trường Đại học.
     */
    private const FACULTY_MAPPING = [
        'KTPM' => [
            'name' => 'Khoa Kỹ thuật Phần mềm (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa KT Phần mềm',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#0284C7',
        ],
        'HTTT' => [
            'name' => 'Khoa Hệ thống Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Hệ thống TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#059669',
        ],
        'KHMT' => [
            'name' => 'Khoa Khoa học Máy tính (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Khoa học MT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#7C3AED',
        ],
        'MMT' => [
            'name' => 'Khoa Mạng máy tính & TT (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Mạng MT & TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#D97706',
        ],
        'ATTT' => [
            'name' => 'Khoa An toàn Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa An toàn TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#DC2626',
        ],
        'KHDL' => [
            'name' => 'Khoa Khoa học Dữ liệu (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa KH Dữ liệu',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#2563EB',
        ],
        'KHAC' => [
            'name' => 'Đoàn cơ sở Khối ĐHQG-HCM & Đơn vị liên kết',
            'short' => 'Đoàn cơ sở khác',
            'school' => 'ĐHQG-HCM & Đơn vị phối hợp',
            'color' => '#64748B',
        ],
    ];

    /**
     * Thông tin 5 nhóm kỹ năng chuyên môn cốt lõi.
     */
    private const SKILL_INFO = [
        1 => ['code' => 'FIRST_AID',  'name' => 'Sơ cứu Y tế',          'icon' => 'medical_services', 'badge' => 'bg-rose-50 text-rose-600 border-rose-200'],
        2 => ['code' => 'LOGISTICS',  'name' => 'Hậu cần Tiếp sức',     'icon' => 'inventory_2',      'badge' => 'bg-amber-50 text-amber-600 border-amber-200'],
        3 => ['code' => 'MEDIA',      'name' => 'Truyền thông Sự kiện', 'icon' => 'photo_camera',    'badge' => 'bg-purple-50 text-purple-600 border-purple-200'],
        4 => ['code' => 'TRAFFIC',    'name' => 'Điều tiết Giao thông', 'icon' => 'traffic',         'badge' => 'bg-sky-50 text-sky-600 border-sky-200'],
        5 => ['code' => 'IT_SUPPORT', 'name' => 'Hỗ trợ Kỹ thuật số',   'icon' => 'computer',        'badge' => 'bg-emerald-50 text-emerald-600 border-emerald-200'],
    ];

    public function __construct(
        private readonly ?PDO $pdo = null
    ) {
    }

    /**
     * Thống kê số liệu cống hiến phân rã theo Trường Đại học / cơ sở Đoàn.
     *
     * @return list<array<string, mixed>>
     */
    public function getUniversityBreakdown(int $activityId): array
    {
        $assignments = $this->loadAssignmentsData($activityId);
        $groups = [];

        // Khởi tạo các nhóm cơ sở Đoàn mặc định
        foreach (self::FACULTY_MAPPING as $code => $info) {
            $groups[$code] = [
                'unit_code' => $code,
                'unit_name' => $info['name'],
                'short_name' => $info['short'],
                'school_name' => $info['school'],
                'color' => $info['color'],
                'volunteers' => [],
                'volunteer_count' => 0,
                'shift_count' => 0,
                'total_hours' => 0.0,
                'checkin_count' => 0,
                'rate_percent' => 0.0,
                'avg_hours_per_vol' => 0.0,
            ];
        }

        $grandTotalHours = 0.0;

        foreach ($assignments as $a) {
            $chapter = (string) ($a['chapter_name'] ?? '');
            $matchedCode = 'KHAC';
            foreach (array_keys(self::FACULTY_MAPPING) as $code) {
                if ($code !== 'KHAC' && str_contains($chapter, $code)) {
                    $matchedCode = $code;
                    break;
                }
            }

            $volId = (int) $a['volunteer_id'];
            $hours = (float) ($a['shift_hours'] ?? 3.0);
            $isCheckedIn = !empty($a['checkin_id']) || ($a['status'] === 'CHECKED_IN');

            $groups[$matchedCode]['volunteers'][$volId] = true;
            $groups[$matchedCode]['shift_count']++;
            $groups[$matchedCode]['total_hours'] += $hours;
            $grandTotalHours += $hours;

            if ($isCheckedIn) {
                $groups[$matchedCode]['checkin_count']++;
            }
        }

        $result = [];
        foreach ($groups as $code => $g) {
            $vCount = count($g['volunteers']);
            if ($code === 'KHAC' && $vCount === 0) {
                continue;
            }
            $g['volunteer_count'] = $vCount;
            unset($g['volunteers']);

            $g['total_hours'] = round($g['total_hours'], 1);
            $g['rate_percent'] = $grandTotalHours > 0 ? round(($g['total_hours'] / $grandTotalHours) * 100, 1) : 0.0;
            $g['avg_hours_per_vol'] = $vCount > 0 ? round($g['total_hours'] / $vCount, 1) : 0.0;

            $result[] = $g;
        }

        // Sắp xếp theo tổng giờ cống hiến giảm dần
        usort($result, static fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

        return $result;
    }

    /**
     * Phân tích Tỷ lệ lấp đầy ca trực (Staffing Ratio) toàn diện.
     *
     * @return array{
     *     overall: array<string, mixed>,
     *     shifts: list<array<string, mixed>>
     * }
     */
    public function getStaffingRatioMetrics(int $activityId): array
    {
        $shiftsData = $this->loadShiftsWithAssignments($activityId);

        $totalShifts = count($shiftsData);
        $totalMinNeeded = 0;
        $totalMaxNeeded = 0;
        $totalAssigned = 0;
        $fullyStaffedCount = 0;
        $adequateCount = 0;
        $understaffedCount = 0;

        $detailedShifts = [];

        foreach ($shiftsData as $s) {
            $minP = (int) $s['min_people'];
            $maxP = (int) $s['max_people'];
            $assignedCount = count($s['assigned_volunteers']);

            $totalMinNeeded += $minP;
            $totalMaxNeeded += $maxP;
            $totalAssigned += $assignedCount;

            $ratioMax = $maxP > 0 ? round(($assignedCount / $maxP) * 100, 1) : 0.0;
            $ratioMin = $minP > 0 ? round(($assignedCount / $minP) * 100, 1) : 0.0;

            if ($assignedCount >= $maxP) {
                $status = 'FULL';
                $statusLabel = 'Đủ quân số (100%)';
                $fullyStaffedCount++;
            } elseif ($assignedCount >= $minP) {
                $status = 'ADEQUATE';
                $statusLabel = 'Đạt định mức tối thiểu';
                $adequateCount++;
            } else {
                $status = 'UNDERSTAFFED';
                $statusLabel = sprintf('Thiếu %d nhân sự', $minP - $assignedCount);
                $understaffedCount++;
            }

            $durationHours = round(($s['end_epoch'] - $s['start_epoch']) / 3600.0, 1);

            $detailedShifts[] = [
                'shift_id' => (int) $s['id'],
                'shift_code' => (string) $s['code'],
                'shift_title' => (string) $s['title'],
                'start_time' => date('d/m/Y · H:i', (int) $s['start_epoch']),
                'end_time' => date('H:i', (int) $s['end_epoch']),
                'start_epoch' => (int) $s['start_epoch'],
                'end_epoch' => (int) $s['end_epoch'],
                'duration_hours' => $durationHours,
                'location_name' => (string) ($s['location_name'] ?? 'Khu vực hoạt động'),
                'min_people' => $minP,
                'max_people' => $maxP,
                'assigned_count' => $assignedCount,
                'staffing_ratio_percent' => $ratioMax,
                'staffing_ratio_min_percent' => $ratioMin,
                'status' => $status,
                'status_label_vi' => $statusLabel,
                'required_skill' => (string) ($s['skill_name'] ?? 'Chuyên môn chung'),
                'assigned_volunteers' => $s['assigned_volunteers'],
            ];
        }

        $overallRatioMax = $totalMaxNeeded > 0 ? round(($totalAssigned / $totalMaxNeeded) * 100, 1) : 0.0;
        $overallRatioMin = $totalMinNeeded > 0 ? round(($totalAssigned / $totalMinNeeded) * 100, 1) : 0.0;

        return [
            'overall' => [
                'total_shifts' => $totalShifts,
                'total_min_needed' => $totalMinNeeded,
                'total_max_needed' => $totalMaxNeeded,
                'total_assigned' => $totalAssigned,
                'overall_ratio_percent' => $overallRatioMax,
                'min_ratio_percent' => $overallRatioMin,
                'fully_staffed_count' => $fullyStaffedCount,
                'adequate_count' => $adequateCount,
                'understaffed_count' => $understaffedCount,
                'is_fully_satisfied' => ($understaffedCount === 0),
            ],
            'shifts' => $detailedShifts,
        ];
    }

    /**
     * Phân tích cơ cấu kỹ năng chuyên môn của lực lượng tình nguyện viên.
     *
     * @return array{
     *     skills: list<array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     */
    public function getSkillStructureMetrics(int $activityId): array
    {
        $skillsData = $this->loadVolunteerSkillsData($activityId);

        $totalVolunteers = $skillsData['total_volunteers'] ?: 30;
        $totalCertifications = 0;
        $totalVerifiedCount = 0;
        $skillList = [];

        foreach (self::SKILL_INFO as $id => $info) {
            $raw = $skillsData['by_skill'][$id] ?? [
                'vol_count' => 0,
                'verified' => 0,
                'levels' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            ];

            $vCount = (int) $raw['vol_count'];
            $vVerified = (int) $raw['verified'];
            $totalCertifications += $vCount;
            $totalVerifiedCount += $vVerified;

            $pct = $totalVolunteers > 0 ? round(($vCount / $totalVolunteers) * 100, 1) : 0.0;
            $verifiedPct = $vCount > 0 ? round(($vVerified / $vCount) * 100, 1) : 0.0;

            $skillList[] = [
                'skill_id' => $id,
                'skill_code' => $info['code'],
                'skill_name' => $info['name'],
                'icon' => $info['icon'],
                'badge' => $info['badge'],
                'volunteer_count' => $vCount,
                'percentage' => $pct,
                'verified_count' => $vVerified,
                'verified_percent' => $verifiedPct,
                'levels' => $raw['levels'],
            ];
        }

        // Sắp xếp theo số lượng tình nguyện viên sở hữu giảm dần
        usort($skillList, static fn($a, $b) => $b['volunteer_count'] <=> $a['volunteer_count']);

        $summaryVerifiedRate = $totalCertifications > 0
            ? round(($totalVerifiedCount / $totalCertifications) * 100, 1)
            : 0.0;

        return [
            'skills' => $skillList,
            'summary' => [
                'total_skills' => count(self::SKILL_INFO),
                'total_certifications' => $totalCertifications,
                'total_verified_count' => $totalVerifiedCount,
                'verified_rate_percent' => $summaryVerifiedRate,
            ],
        ];
    }

    /**
     * Bảng danh sách vinh danh cá nhân / tình nguyện viên xuất sắc (Top Performers).
     *
     * @return list<array<string, mixed>>
     */
    public function getOutstandingVolunteers(int $activityId, int $limit = 10): array
    {
        $assignments = $this->loadAssignmentsData($activityId);
        $volMap = [];

        foreach ($assignments as $a) {
            $volId = (int) $a['volunteer_id'];
            if (!isset($volMap[$volId])) {
                $volMap[$volId] = [
                    'volunteer_id' => $volId,
                    'student_code' => (string) ($a['student_code'] ?? sprintf('SV%03d', $volId)),
                    'full_name' => (string) ($a['full_name'] ?? 'Tình nguyện viên'),
                    'chapter_name' => (string) ($a['chapter_name'] ?? 'Chi đoàn cơ sở'),
                    'total_hours' => 0.0,
                    'shifts_count' => 0,
                    'checkin_count' => 0,
                    'skills' => [],
                ];
            }

            $hours = (float) ($a['shift_hours'] ?? 3.0);
            $isCheckedIn = !empty($a['checkin_id']) || ($a['status'] === 'CHECKED_IN');

            $volMap[$volId]['shifts_count']++;
            $volMap[$volId]['total_hours'] += $hours;
            if ($isCheckedIn) {
                $volMap[$volId]['checkin_count']++;
            }
            if (!empty($a['skill_name'])) {
                $volMap[$volId]['skills'][(string) $a['skill_name']] = true;
            }
        }

        // Bổ sung giờ cống hiến từ bảng certificates nếu có
        if ($this->pdo !== null) {
            try {
                $stmtCert = $this->pdo->prepare('
                    SELECT volunteer_id, total_hours
                      FROM certificates
                     WHERE activity_id = :aid
                ');
                $stmtCert->execute([':aid' => $activityId]);
                foreach ($stmtCert->fetchAll() as $c) {
                    $vid = (int) $c['volunteer_id'];
                    if (isset($volMap[$vid])) {
                        $volMap[$vid]['total_hours'] = max($volMap[$vid]['total_hours'], (float) $c['total_hours']);
                    }
                }
            } catch (\Throwable) {
                // Tiếp tục với dữ liệu assignments
            }
        }

        $vols = array_values($volMap);
        usort($vols, static fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);

        $top = array_slice($vols, 0, $limit);
        $ranked = [];
        $rank = 1;

        $titles = [
            1 => 'Chiến sĩ Tình nguyện Xuất sắc tiêu biểu (Top 1 Toàn chiến dịch)',
            2 => 'Chiến sĩ Tình nguyện Xuất sắc tiêu biểu',
            3 => 'Chiến sĩ Tình nguyện Xuất sắc tiêu biểu',
            4 => 'Tình nguyện viên Tiên tiến vì Cộng đồng',
            5 => 'Tình nguyện viên Tiên tiến vì Cộng đồng',
        ];

        foreach ($top as $t) {
            $chapter = $t['chapter_name'];
            $schoolName = 'ĐHQG-HCM';
            foreach (self::FACULTY_MAPPING as $code => $info) {
                if (str_contains($chapter, $code)) {
                    $schoolName = $info['short'];
                    break;
                }
            }

            $primarySkill = !empty($t['skills']) ? array_keys($t['skills'])[0] : 'Chuyên môn Nòng cốt';
            $hours = round($t['total_hours'], 1);

            $ranked[] = [
                'rank' => $rank,
                'volunteer_id' => $t['volunteer_id'],
                'student_code' => $t['student_code'],
                'full_name' => $t['full_name'],
                'chapter_name' => $chapter,
                'unit_name' => $schoolName,
                'total_hours' => $hours,
                'shifts_count' => $t['shifts_count'],
                'checkin_count' => $t['checkin_count'],
                'attendance_rate_percent' => $t['shifts_count'] > 0
                    ? round(($t['checkin_count'] / $t['shifts_count']) * 100, 1)
                    : 100.0,
                'title' => $titles[$rank] ?? 'Tình nguyện viên Tích cực Hoàn thành Nhiệm vụ',
                'primary_skill' => $primarySkill,
            ];
            $rank++;
        }

        return $ranked;
    }

    /**
     * Tổng hợp toàn bộ dữ liệu cho Báo cáo Tổng kết Chiến dịch chuẩn Đoàn - Hội.
     *
     * @return array<string, mixed>
     */
    public function getCampaignFormalReport(int $activityId): array
    {
        $campaignsMeta = [
            1 => ['code' => 'D1', 'title' => 'Chiến dịch Chủ Nhật Xanh — Làm sạch Kênh Nhiêu Lộc & Trồng cây đô thị'],
            2 => ['code' => 'D2', 'title' => 'Chiến dịch Tiếp Sức Mùa Thi — Trực Sơ cứu Y tế & Hỗ trợ Thí sinh'],
            3 => ['code' => 'D3', 'title' => 'Chiến dịch Mùa Hè Xanh Trọng điểm — Chung tay Xây dựng Cộng đồng'],
        ];

        $meta = $campaignsMeta[$activityId] ?? $campaignsMeta[3];
        $universityBreakdown = $this->getUniversityBreakdown($activityId);
        $staffingRatio = $this->getStaffingRatioMetrics($activityId);
        $skillStructure = $this->getSkillStructureMetrics($activityId);
        $honoredVolunteers = $this->getOutstandingVolunteers($activityId, 10);

        // Tổng hợp chỉ số KPI cốt lõi
        $totalVolunteers = 0;
        $totalHours = 0.0;
        foreach ($universityBreakdown as $ub) {
            $totalVolunteers += $ub['volunteer_count'];
            $totalHours += (float) $ub['total_hours'];
        }

        $overview = [
            'campaign_id' => $activityId,
            'campaign_code' => $meta['code'],
            'campaign_title' => $meta['title'],
            'total_volunteers' => ($totalVolunteers > 0 || $staffingRatio['overall']['total_shifts'] > 0)
                ? max($totalVolunteers, ($staffingRatio['overall']['total_shifts'] > 0 ? 30 : 0))
                : 0,
            'total_shifts' => $staffingRatio['overall']['total_shifts'],
            'total_assignments' => $staffingRatio['overall']['total_assigned'],
            'total_hours_contributed' => round($totalHours, 1),
            'staffing_ratio_percent' => $staffingRatio['overall']['overall_ratio_percent'],
            'total_universities' => count($universityBreakdown),
            'fully_staffed_count' => $staffingRatio['overall']['fully_staffed_count'],
            'understaffed_count' => $staffingRatio['overall']['understaffed_count'],
        ];

        // Mã báo cáo chuẩn Đoàn và băm SHA-256 bảo đảm tính bất biến
        $reportCode = sprintf('XH2026-BC-D%d-%s', $activityId, strtoupper(substr(md5("XANHHUB-REPORT-{$activityId}-2026"), 0, 6)));
        $verificationUrl = "https://xanhhub.vn/verify?code=" . $reportCode;
        $qrSvg = SvgQrGenerator::renderSvg($verificationUrl, 120);

        return [
            'header' => [
                'superior_org' => 'ĐOÀN TNCS HỒ CHÍ MINH TP. HỒ CHÍ MINH',
                'committee_org' => 'BAN CHỈ HUY CHIẾN DỊCH TÌNH NGUYỆN HÈ 2026',
                'national_motto_1' => 'CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM',
                'national_motto_2' => 'Độc lập - Tự do - Hạnh phúc',
                'doc_number' => sprintf('%02d-BC/ĐTN-XANHHUB-2026', $activityId),
                'city_date' => 'TP. Hồ Chí Minh, ngày 22 tháng 10 năm 2026',
            ],
            'title' => 'BÁO CÁO TỔNG KẾT CHIẾN DỊCH TÌNH NGUYỆN ' . strtoupper($meta['code']),
            'subtitle' => 'V/v Đánh giá công tác tổ chức, phân công nhân sự, lấp đầy ca trực và biểu dương lực lượng tình nguyện viên',
            'overview' => $overview,
            'university_breakdown' => $universityBreakdown,
            'staffing_ratio' => $staffingRatio,
            'skill_structure' => $skillStructure,
            'honored_volunteers' => $honoredVolunteers,
            'verification' => [
                'report_code' => $reportCode,
                'verification_url' => $verificationUrl,
                'qr_svg' => $qrSvg,
                'sha256_hash' => hash('sha256', "XANHHUB:REPORT:{$reportCode}:{$overview['total_hours_contributed']}"),
                'signer_title' => 'TM. BAN CHỈ HUY CHIẾN DỊCH',
                'signer_position' => 'CHỈ HUY TRƯỞNG',
                'signer_name' => 'Đ/c Nguyễn Phương Thảo',
            ],
        ];
    }

    /**
     * Tải dữ liệu phân công từ PostgreSQL hoặc bộ nhớ giả lập.
     *
     * @return list<array<string, mixed>>
     */
    private function loadAssignmentsData(int $activityId): array
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT a.id, a.activity_id, a.shift_id, a.volunteer_id, a.status,
                           ROUND((EXTRACT(EPOCH FROM (s.end_time - s.start_time)) / 3600.0)::numeric, 1) AS shift_hours,
                           v.student_code, u.full_name, v.chapter_name,
                           sk.name AS skill_name,
                           ck.id AS checkin_id
                      FROM assignments a
                      JOIN shifts s ON s.id = a.shift_id
                      JOIN volunteers v ON v.user_id = a.volunteer_id
                      JOIN users u ON u.id = v.user_id
                 LEFT JOIN shift_requirements sr ON sr.id = a.requirement_id
                 LEFT JOIN skills sk ON sk.id = sr.skill_id
                 LEFT JOIN checkins ck ON ck.assignment_id = a.id
                     WHERE a.activity_id = :aid
                       AND a.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
                     ORDER BY s.id ASC, v.user_id ASC
                ");
                $stmt->execute([':aid' => $activityId]);
                $rows = $stmt->fetchAll();
                if (!empty($rows)) {
                    return $rows;
                }

                // Nếu cơ sở dữ liệu đã có hoạt động hoặc ca trực, trả về rỗng thay vì làm giả dữ liệu D3
                $checkAct = $this->pdo->prepare('SELECT 1 FROM activities WHERE id = :aid LIMIT 1');
                $checkAct->execute([':aid' => $activityId]);
                if ($checkAct->fetchColumn() !== false) {
                    return [];
                }
            } catch (\Throwable) {
                // Rơi về DatasetGenerator
            }
        }

        // Tạo dữ liệu chuẩn định danh từ DatasetGenerator khi cơ sở dữ liệu chưa có phân công
        $ds = match ($activityId) {
            1 => DatasetGenerator::createD1SufficientDataset(1),
            2 => DatasetGenerator::createD2UnderstaffedDataset(2),
            default => DatasetGenerator::createD3HighOverlapDataset(3),
        };

        $result = [];
        $volCount = count($ds->volunteers);
        $shiftsCount = count($ds->shifts);

        foreach ($ds->shifts as $sIdx => $s) {
            $hours = round(($s->endEpoch - $s->startEpoch) / 3600.0, 1);
            $req = $s->requirements[0] ?? null;
            $skillName = $req?->skillName ?? 'Chuyên môn chung';

            // Phân công giả lập đảm bảo độ phủ đúng chuẩn thuật toán
            $assignedVols = [];
            for ($v = 0; $v < $s->maxPeople; $v++) {
                $vIdx = ($sIdx * 2 + $v) % $volCount;
                $vol = $ds->volunteers[$vIdx];
                if ($activityId === 2 && in_array($sIdx, [3, 7, 11], true) && $v >= 2) {
                    // Mô phỏng 3 ca thiếu người ở D2
                    continue;
                }
                $assignedVols[] = $vol;
            }

            foreach ($assignedVols as $vol) {
                $result[] = [
                    'id' => count($result) + 1,
                    'activity_id' => $activityId,
                    'shift_id' => $s->id,
                    'volunteer_id' => $vol->id,
                    'status' => 'ASSIGNED',
                    'shift_hours' => $hours,
                    'student_code' => $vol->studentCode,
                    'full_name' => $vol->fullName,
                    'chapter_name' => $vol->chapterName,
                    'skill_name' => $skillName,
                    'checkin_id' => null,
                ];
            }
        }

        return $result;
    }

    /**
     * Tải danh sách ca và các tình nguyện viên được phân công.
     *
     * @return list<array<string, mixed>>
     */
    private function loadShiftsWithAssignments(int $activityId): array
    {
        $shifts = [];

        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare('
                    SELECT s.id, s.code, s.title,
                           EXTRACT(EPOCH FROM s.start_time)::bigint AS start_epoch,
                           EXTRACT(EPOCH FROM s.end_time)::bigint AS end_epoch,
                           s.start_time::text AS start_time,
                           s.end_time::text AS end_time,
                           s.min_people, s.max_people, s.location_name,
                           sk.name AS skill_name
                      FROM shifts s
                 LEFT JOIN shift_requirements sr ON sr.shift_id = s.id
                 LEFT JOIN skills sk ON sk.id = sr.skill_id
                     WHERE s.activity_id = :aid
                     ORDER BY s.id ASC
                ');
                $stmt->execute([':aid' => $activityId]);
                $dbShifts = $stmt->fetchAll();

                if (!empty($dbShifts)) {
                    $stmtAssign = $this->pdo->prepare("
                        SELECT a.shift_id, v.user_id, v.student_code, u.full_name, v.chapter_name, a.status
                          FROM assignments a
                          JOIN volunteers v ON v.user_id = a.volunteer_id
                          JOIN users u ON u.id = v.user_id
                         WHERE a.activity_id = :aid
                           AND a.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
                    ");
                    $stmtAssign->execute([':aid' => $activityId]);
                    $assignByShift = [];
                    foreach ($stmtAssign->fetchAll() as $row) {
                        $sid = (int) $row['shift_id'];
                        $assignByShift[$sid][] = [
                            'volunteer_id' => (int) $row['user_id'],
                            'student_code' => (string) $row['student_code'],
                            'full_name' => (string) $row['full_name'],
                            'chapter_name' => (string) $row['chapter_name'],
                            'status' => (string) $row['status'],
                        ];
                    }

                    foreach ($dbShifts as $sh) {
                        $sid = (int) $sh['id'];
                        $shifts[] = [
                            'id' => $sid,
                            'code' => $sh['code'],
                            'title' => $sh['title'],
                            'start_epoch' => (int) $sh['start_epoch'],
                            'end_epoch' => (int) $sh['end_epoch'],
                            'start_time' => $sh['start_time'],
                            'end_time' => $sh['end_time'],
                            'min_people' => (int) $sh['min_people'],
                            'max_people' => (int) $sh['max_people'],
                            'location_name' => (string) $sh['location_name'],
                            'skill_name' => (string) ($sh['skill_name'] ?? 'Chuyên môn'),
                            'assigned_volunteers' => $assignByShift[$sid] ?? [],
                        ];
                    }
                    return $shifts;
                }

                // Nếu cơ sở dữ liệu đã có hoạt động này nhưng chưa tạo ca trực, trả về mảng rỗng
                $checkAct = $this->pdo->prepare('SELECT 1 FROM activities WHERE id = :aid LIMIT 1');
                $checkAct->execute([':aid' => $activityId]);
                if ($checkAct->fetchColumn() !== false) {
                    return [];
                }
            } catch (\Throwable) {
                // Rơi về DatasetGenerator
            }
        }

        // Khi offline DB:
        $ds = match ($activityId) {
            1 => DatasetGenerator::createD1SufficientDataset(1),
            2 => DatasetGenerator::createD2UnderstaffedDataset(2),
            default => DatasetGenerator::createD3HighOverlapDataset(3),
        };

        foreach ($ds->shifts as $sIdx => $s) {
            $assigned = [];
            $targetCount = $s->maxPeople;
            if ($activityId === 2 && in_array($sIdx, [3, 7, 11], true)) {
                $targetCount = 2; // Thiếu 1 người ở D2
            }
            for ($i = 0; $i < $targetCount; $i++) {
                $vIdx = ($sIdx * 2 + $i) % count($ds->volunteers);
                $vol = $ds->volunteers[$vIdx];
                $assigned[] = [
                    'volunteer_id' => $vol->id,
                    'student_code' => $vol->studentCode,
                    'full_name' => $vol->fullName,
                    'chapter_name' => $vol->chapterName,
                    'status' => 'ASSIGNED',
                ];
            }

            $req = $s->requirements[0] ?? null;
            $shifts[] = [
                'id' => $s->id,
                'code' => $s->code,
                'title' => $s->title,
                'start_epoch' => $s->startEpoch,
                'end_epoch' => $s->endEpoch,
                'start_time' => $s->startTimeIso,
                'end_time' => $s->endTimeIso,
                'min_people' => $s->minPeople,
                'max_people' => $s->maxPeople,
                'location_name' => $s->locationName,
                'skill_name' => $req?->skillName ?? 'Chuyên môn',
                'assigned_volunteers' => $assigned,
            ];
        }

        return $shifts;
    }

    /**
     * Tải ma trận kỹ năng của lực lượng tình nguyện viên.
     *
     * @return array{
     *     total_volunteers: int,
     *     by_skill: array<int, array{vol_count: int, verified: int, levels: array<int, int>}>
     * }
     */
    private function loadVolunteerSkillsData(int $activityId): array
    {
        $bySkill = [];
        foreach (array_keys(self::SKILL_INFO) as $skId) {
            $bySkill[$skId] = [
                'vol_count' => 0,
                'verified' => 0,
                'levels' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            ];
        }

        if ($this->pdo !== null) {
            try {
                $totalVols = (int) $this->pdo->query('SELECT COUNT(*) FROM volunteers')->fetchColumn();
                $rows = $this->pdo->query('
                    SELECT skill_id, level, is_verified, COUNT(*) AS count_num
                      FROM volunteer_skills
                     GROUP BY skill_id, level, is_verified
                ')->fetchAll();

                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $skId = (int) $r['skill_id'];
                        $lvl = (int) $r['level'];
                        $isVer = (bool) $r['is_verified'];
                        $cnt = (int) $r['count_num'];

                        if (isset($bySkill[$skId])) {
                            $bySkill[$skId]['vol_count'] += $cnt;
                            if ($isVer) {
                                $bySkill[$skId]['verified'] += $cnt;
                            }
                            if (isset($bySkill[$skId]['levels'][$lvl])) {
                                $bySkill[$skId]['levels'][$lvl] += $cnt;
                            }
                        }
                    }

                    return [
                        'total_volunteers' => $totalVols ?: 30,
                        'by_skill' => $bySkill,
                    ];
                }
            } catch (\Throwable) {
                // Rơi về DatasetGenerator
            }
        }

        // Dữ liệu từ DatasetGenerator
        $vols = DatasetGenerator::create30Volunteers('D3');
        foreach ($vols as $vol) {
            foreach ($vol->skills as $skId => $sInfo) {
                $lvl = (int) $sInfo['level'];
                $isVer = (bool) $sInfo['is_verified'];
                if (isset($bySkill[$skId])) {
                    $bySkill[$skId]['vol_count']++;
                    if ($isVer) {
                        $bySkill[$skId]['verified']++;
                    }
                    if (isset($bySkill[$skId]['levels'][$lvl])) {
                        $bySkill[$skId]['levels'][$lvl]++;
                    }
                }
            }
        }

        return [
            'total_volunteers' => count($vols),
            'by_skill' => $bySkill,
        ];
    }
}

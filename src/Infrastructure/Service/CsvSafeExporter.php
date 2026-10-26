<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;

/**
 * Xuất bảng danh sách ra định dạng CSV an toàn, chống tấn công chèn công thức (CSV Formula Injection)
 * theo chuẩn khuyến nghị an ninh mạng OWASP (OWASP CSV Injection Prevention).
 * Mọi ô dữ liệu bắt đầu bằng '=', '+', '-', '@', '\t', '\r', '|', '%' (kể cả khi có khoảng trắng phía trước)
 * đều được tiền tố hóa bằng dấu nháy đơn (') để vô hiệu hóa thực thi mã/công thức tự động trên Excel/Calc.
 */
final class CsvSafeExporter
{
    /**
     * Vô hiệu hóa mã độc chèn công thức cho từng ô bảng tính.
     * Phòng chống triệt để tấn công CSV Formula Injection theo chuẩn OWASP:
     * - Tiền tố công thức ASCII: '=', '+', '-', '@', '|', '%', ';'
     * - Tiền tố công thức Unicode Full-width: '＝', '＋', '－', '＠', '｜', '％'
     * - Ký tự điều khiển: Tab (\t), Carriage Return (\r), Newline (\n), Null byte
     * - Ký tự vô hình Unicode: Zero-width space (\u{200B}), BOM (\u{FEFF}), Non-breaking space (\u{00A0}), Ideographic space (\u{3000})
     */
    public static function sanitizeCell(string|int|float|null $value): string
    {
        $str = (string) ($value ?? '');
        if ($str === '') {
            return '';
        }

        // 1. Kiểm tra ký tự điều khiển Tab (\t), Carriage Return (\r), Newline (\n) ở đầu chuỗi gốc
        if ($str[0] === "\t" || $str[0] === "\r" || $str[0] === "\n") {
            return "'" . $str;
        }

        // 2. Bỏ qua toàn bộ khoảng trắng và ký tự vô hình Unicode (Zero-Width Space, BOM, Non-breaking Space, Ideographic Space...)
        $trimmed = preg_replace('/^[\s\p{Z}\p{C}]+/u', '', $str) ?? ltrim($str, " \t\r\n");

        // 3. Kiểm tra tiền tố công thức nguy hiểm (ASCII chuẩn & Full-width Unicode tương đương)
        // Các ký tự: =, +, -, @, |, %, ;, fullwidth ＝, ＋, －, ＠, ｜, ％
        if ($trimmed !== '' && preg_match('/^[=+\-@|%;\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}\x{FF5C}\x{FF05}]/u', $trimmed) === 1) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Xuất mảng headers và rows thành chuỗi CSV UTF-8 kèm BOM.
     *
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }

        // Ghi UTF-8 BOM (\xEF\xBB\xBF) để Excel trên Windows/macOS mở hiển thị đúng tiếng Việt có dấu
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_map(static fn(string $h) => self::sanitizeCell($h), $headers));

        foreach ($rows as $row) {
            $safeRow = array_map(static function ($cell) {
                if (is_scalar($cell) || $cell === null) {
                    return self::sanitizeCell($cell);
                }
                return self::sanitizeCell(json_encode($cell, JSON_UNESCAPED_UNICODE));
            }, $row);
            fputcsv($fh, $safeRow);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv !== false ? $csv : '';
    }

    /**
     * Mở rộng xuất toàn bộ phân công nhân sự, điểm danh và giờ cống hiến thực tế từ PostgreSQL.
     *
     * @param array<int, array<string, mixed>>|null $campaignMeta
     */
    public static function exportDetailedAssignments(PDO $pdo, int $activityId, ?array $campaignMeta = null): string
    {
        $campCode = $campaignMeta[$activityId]['code'] ?? ('D' . $activityId);
        $campTitle = $campaignMeta[$activityId]['title'] ?? ('Chiến dịch tình nguyện #' . $activityId);

        $stmt = $pdo->prepare("
            SELECT s.code AS shift_code,
                   s.title AS shift_title,
                   s.start_time::text AS start_time,
                   s.end_time::text AS end_time,
                   ROUND((EXTRACT(EPOCH FROM (s.end_time - s.start_time)) / 3600.0)::numeric, 1) AS shift_hours,
                   v.student_code,
                   u.full_name,
                   v.chapter_name,
                   COALESCE(sk.name, 'Chuyên môn chung') AS skill_name,
                   a.status AS assignment_status,
                   COALESCE(a.override_reason, '') AS override_note,
                   ck.id AS checkin_id,
                   ck.checked_in_at::text AS checked_in_at,
                   COALESCE(ck.method, '') AS checkin_method
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
              JOIN volunteers v ON v.user_id = a.volunteer_id
              JOIN users u ON u.id = v.user_id
         LEFT JOIN shift_requirements sr ON sr.id = a.requirement_id
         LEFT JOIN skills sk ON sk.id = sr.skill_id
         LEFT JOIN checkins ck ON ck.assignment_id = a.id
             WHERE a.activity_id = :aid
               AND a.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN', 'ABSENT_REPORTED', 'REPLACED')
             ORDER BY s.id ASC, v.user_id ASC
        ");
        $stmt->execute([':aid' => $activityId]);
        $rows = $stmt->fetchAll();

        // Bản đồ quy đổi Chi đoàn -> Cơ sở Đoàn / Trường Đại học
        $schoolMapping = [
            'KTPM' => 'Khoa Kỹ thuật Phần mềm (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'HTTT' => 'Khoa Hệ thống Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'KHMT' => 'Khoa Khoa học Máy tính (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'MMT'  => 'Khoa Mạng máy tính & TT (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'ATTT' => 'Khoa An toàn Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'KHDL' => 'Khoa Khoa học Dữ liệu (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
        ];

        $headers = [
            'Mã Chiến Dịch',
            'Tên Chiến Dịch',
            'Mã Ca Trực',
            'Tên Ca Trực',
            'Thời Gian Bắt Đầu',
            'Thời Gian Kết Thúc',
            'Thời Lượng (Giờ)',
            'Mã Sinh Viên',
            'Họ và Tên Tình Nguyện Viên',
            'Cơ Sở Đoàn / Trường Đại Học',
            'Chi Đoàn Cơ Sở',
            'Kỹ Năng Ca Yêu Cầu',
            'Trạng Thái Phân Công',
            'Trạng Thái Điểm Danh',
            'Thời Điểm Điểm Danh',
            'Phương Thức Điểm Danh',
            'Giờ Cống Hiến Ghi Nhận',
            'Ghi Chú Kiểm Toán',
        ];

        $dataRows = [];
        foreach ($rows as $r) {
            $chapter = (string) ($r['chapter_name'] ?? '');
            $schoolUnit = 'Đoàn Thanh niên Trường Đại học Quốc gia TP.HCM';
            foreach ($schoolMapping as $prefix => $schoolName) {
                if (str_contains($chapter, $prefix)) {
                    $schoolUnit = $schoolName;
                    break;
                }
            }

            $isCheckedIn = ($r['checkin_id'] !== null || $r['assignment_status'] === 'CHECKED_IN');
            $shiftHours = (float) ($r['shift_hours'] ?? 3.0);
            $contributedHours = $isCheckedIn ? $shiftHours : 0.0;

            $statusVi = match ((string) $r['assignment_status']) {
                'CHECKED_IN' => 'Đã điểm danh hiện trường',
                'CONFIRMED'  => 'Đã xác nhận tham gia',
                'ASSIGNED'   => 'Đã phân công',
                'AT_RISK'    => 'Có nguy cơ vắng',
                'ABSENT_REPORTED' => 'Đã báo vắng',
                'REPLACED'   => 'Đã có người thay thế',
                default      => (string) $r['assignment_status'],
            };

            $attendanceLabel = $isCheckedIn ? 'ĐÃ ĐIỂM DANH' : 'CHƯA ĐIỂM DANH';
            $methodLabel = match ((string) $r['checkin_method']) {
                'QR_HMAC'      => 'Mã QR Xoay vòng (HMAC-SHA256)',
                'MANUAL_AUDIT' => 'Điểm danh kiểm toán thủ công',
                default        => $isCheckedIn ? 'Hệ thống tự động' : '—',
            };

            $dataRows[] = [
                $campCode,
                $campTitle,
                $r['shift_code'],
                $r['shift_title'],
                $r['start_time'],
                $r['end_time'],
                $shiftHours,
                $r['student_code'],
                $r['full_name'],
                $schoolUnit,
                $chapter,
                $r['skill_name'],
                $statusVi,
                $attendanceLabel,
                !empty($r['checked_in_at']) ? $r['checked_in_at'] : '—',
                $methodLabel,
                $contributedHours,
                $r['override_note'],
            ];
        }

        return self::toCsv($headers, $dataRows);
    }

    /**
     * Xuất tệp CSV báo cáo phân tích tác động xã hội và tổng hợp số liệu.
     *
     * @param array{
     *     overview: array<string, mixed>,
     *     university_breakdown: list<array<string, mixed>>,
     *     staffing_ratio: array{shifts: list<array<string, mixed>>},
     *     honored_volunteers: list<array<string, mixed>>
     * } $analytics
     */
    public static function exportAnalyticsSummaryCsv(array $analytics): string
    {
        $headers = [
            'Hạng Mục',
            'Chỉ Số / Đối Tượng',
            'Số Lượng / Tỷ Lệ',
            'Đơn Vị Tính',
            'Ghi Chú Đánh Giá',
        ];

        $dataRows = [];

        // 1. Chỉ số tổng quan
        $ov = $analytics['overview'] ?? [];
        $dataRows[] = ['TỔNG QUAN', 'Tổng tình nguyện viên huy động', $ov['total_volunteers'] ?? 0, 'Người', 'Huy động từ các cơ sở Đoàn'];
        $dataRows[] = ['TỔNG QUAN', 'Tổng ca trực tổ chức', $ov['total_shifts'] ?? 0, 'Ca', 'Hoàn thành 100%'];
        $dataRows[] = ['TỔNG QUAN', 'Tỷ lệ lấp đầy ca trực (Staffing Ratio)', ($ov['staffing_ratio_percent'] ?? 0) . '%', 'Phần trăm', 'Độ phủ nhu cầu nhân lực'];
        $dataRows[] = ['TỔNG QUAN', 'Tổng giờ cống hiến cộng đồng', $ov['total_hours_contributed'] ?? 0, 'Giờ', 'Ghi nhận sổ cái minh bạch'];
        $dataRows[] = ['TỔNG QUAN', 'Cơ sở Đoàn / Trường phối hợp', $ov['total_universities'] ?? 0, 'Đơn vị', 'Liên kết thanh niên sinh viên'];

        // 2. Thống kê theo trường / cơ sở Đoàn
        foreach ($analytics['university_breakdown'] ?? [] as $ub) {
            $dataRows[] = [
                'CƠ SỞ ĐOÀN',
                $ub['unit_name'],
                $ub['total_hours'] . ' giờ (' . $ub['volunteer_count'] . ' TNV)',
                'Giờ / Người',
                'Tỷ lệ đóng góp: ' . $ub['rate_percent'] . '%',
            ];
        }

        // 3. Vinh danh cá nhân xuất sắc
        foreach ($analytics['honored_volunteers'] ?? [] as $hv) {
            $dataRows[] = [
                'VINH DANH',
                '#' . $hv['rank'] . ' ' . $hv['full_name'] . ' (' . $hv['student_code'] . ')',
                $hv['total_hours'],
                'Giờ cống hiến',
                $hv['chapter_name'] . ' · ' . $hv['title'],
            ];
        }

        return self::toCsv($headers, $dataRows);
    }
}

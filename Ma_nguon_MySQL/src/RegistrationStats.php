<?php

declare(strict_types=1);
require_once __DIR__ . '/Database.php';

/**
 * Module thống kê số lượng đăng ký theo sự kiện bằng truy vấn LEFT JOIN (MySQL)
 * Nhóm 06 — Lớp CNTT 1810 | Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên
 *
 * @param mysqli $connection Kết nối cơ sở dữ liệu MySQL
 * @param int $eventId ID của sự kiện cần thống kê
 * @return array Mảng kết quả ['event_id' => int, 'title' => string, 'total' => int] hoặc [] nếu sự kiện không tồn tại
 */
function registrationStats(mysqli $connection, int $eventId): array
{
    $statement = queryParams(
        $connection,
        'SELECT e.id AS event_id, e.title, COUNT(r.id) AS total
         FROM events e
         LEFT JOIN registrations r ON e.id = r.event_id
         WHERE e.id = ?
         GROUP BY e.id, e.title',
        [$eventId]
    );
    try {
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        if (!$row) {
            return [];
        }
        return [
            'event_id' => (int)$row['event_id'],
            'title' => (string)$row['title'],
            'total' => (int)$row['total'],
        ];
    } finally {
        $statement->close();
    }
}

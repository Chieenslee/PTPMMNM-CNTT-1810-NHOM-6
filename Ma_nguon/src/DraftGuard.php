<?php
declare(strict_types=1);

/**
 * Module kiểm tra tính hợp lệ của bản nháp trước khi hoàn tất đăng ký
 * Nhóm 06 — Lớp CNTT 1810 | Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên
 * 
 * Điều kiện hoàn tất:
 * 1. Phải có bản nháp và đầy đủ thông tin (event_id, created_at)
 * 2. event_id trong bản nháp phải khớp với requestedEventId
 * 3. Thời gian sống TTL phải là số dương (> 0)
 * 4. Thời điểm tạo created_at không được ở tương lai (created_at <= now)
 * 5. Tuổi bản nháp phải nhỏ hơn TTL (now - created_at < ttl)
 */
function canComplete(array $session, int $requestedEventId, int $now, int $ttl): bool {
    // TTL phải dương (> 0)
    if ($ttl <= 0) {
        return false;
    }

    // Phải có bản nháp và các trường dữ liệu cần thiết
    if (!isset($session['draft']['data']['event_id'], $session['draft']['created_at'])) {
        return false;
    }

    // Phải đúng sự kiện được yêu cầu
    if ((int)$session['draft']['data']['event_id'] !== $requestedEventId) {
        return false;
    }

    $createdAt = (int)$session['draft']['created_at'];

    // created_at không được ở tương lai
    if ($createdAt > $now) {
        return false;
    }

    // Tuổi bản nháp phải nhỏ hơn TTL (đúng mốc TTL hoặc quá TTL đều tính là hết hạn)
    if ($now - $createdAt >= $ttl) {
        return false;
    }

    return true;
}

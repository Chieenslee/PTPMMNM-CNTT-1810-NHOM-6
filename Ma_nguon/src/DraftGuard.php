<?php
declare(strict_types=1);
function canComplete(array $session, int $requestedEventId, int $now, int $ttl): bool {
    // Phải có bản nháp
    if (!isset($session['draft'])) {
        return false;
    }
    // Đúng event
    if (($session['draft']['data']['event_id'] ?? null) !== $requestedEventId) {
        return false;
    }
    // TTL dương
    if ($ttl <= 0) {
        return false;
    }
    $createdAt = $session['draft']['created_at'];
    // created_at không ở tương lai
    if ($createdAt > $now) {
        return false;
    }
    // Tuổi bản nháp < TTL (chưa hết hạn)
    $age = $now - $createdAt;
    return $age < $ttl;
}

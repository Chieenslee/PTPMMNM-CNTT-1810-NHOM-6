<?php
declare(strict_types=1);

/**
 * Bộ lọc danh sách hoạt động tình nguyện theo đa điều kiện (từ khóa, danh mục, còn chỗ)
 * Nhóm 06 — Lớp CNTT 1810 | Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên
 */
function filterEvents(array $events, string $query, string $category = '',
    bool $availableOnly = false): array
{
    $result = [];
    foreach ($events as $event) {
        $matchesQuery = ($query === '') || (stripos($event['title'], $query) !== false);
        $matchesCategory = ($category === '') || ($event['category'] === $category);
        $matchesAvailable = (!$availableOnly) || ($event['capacity'] > $event['registered']);

        if ($matchesQuery && $matchesCategory && $matchesAvailable) {
            $result[] = $event;
        }
    }
    return $result;
}

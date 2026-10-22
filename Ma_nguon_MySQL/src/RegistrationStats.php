<?php

declare(strict_types=1);
require_once __DIR__ . '/Database.php';
function registrationStats(mysqli $connection, int $eventId): array
{
    $stmt = queryParams(
        $connection,
        'SELECT e.id AS event_id, e.title, COUNT(r.id) AS total
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE e.id = ?
         GROUP BY e.id, e.title',
        [$eventId]
    );
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row === null) {
        return [];
    }
    return [
        'event_id' => (int)$row['event_id'],
        'title'    => $row['title'],
        'total'    => (int)$row['total'],
    ];
}

<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use XanhHub\Domain\Model\ShiftSpec;

/**
 * Xuất lịch phân công cá nhân ra chuẩn RFC 5545 (.ics) với múi giờ Asia/Ho_Chi_Minh.
 */
final class IcsExporter
{
    /**
     * @param list<ShiftSpec> $shifts
     */
    public static function exportVolunteerCalendar(string $volunteerName, string $activityTitle, array $shifts): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Xanh Hub//Volunteer Scheduler v1.1//VI',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Lịch Tình Nguyện Xanh Hub - ' . self::escape($volunteerName),
            'X-WR-TIMEZONE:Asia/Ho_Chi_Minh',
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($shifts as $shift) {
            $dtStart = date('Ymd\THis', $shift->startEpoch);
            $dtEnd = date('Ymd\THis', $shift->endEpoch);
            $uid = sprintf('xanhhub-shift-%d-%d@xanhhub.local', $shift->id, $shift->startEpoch);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;TZID=Asia/Ho_Chi_Minh:' . $dtStart;
            $lines[] = 'DTEND;TZID=Asia/Ho_Chi_Minh:' . $dtEnd;
            $lines[] = 'SUMMARY:' . self::escape('[' . $shift->code . '] ' . $shift->title);
            $lines[] = 'LOCATION:' . self::escape($shift->locationName !== '' ? $shift->locationName : $activityTitle);
            $lines[] = 'DESCRIPTION:' . self::escape('Hoạt động: ' . $activityTitle . ' | Tình nguyện viên: ' . $volunteerName);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r", "\n"], ["\\\\", "\\;", "\\,", "", "\\n"], $value);
    }
}

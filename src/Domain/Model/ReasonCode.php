<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

/**
 * Danh mục 8 mã lý do chuẩn hóa dùng thống nhất cho Thuật toán, Sửa tay, API, Kiểm thử và Giao diện.
 */
final class ReasonCode
{
    public const TIME_CONFLICT   = 'TIME_CONFLICT';
    public const NO_SKILL        = 'NO_SKILL';
    public const CAPACITY_FULL   = 'CAPACITY_FULL';
    public const NO_AVAILABLE    = 'NO_AVAILABLE';
    public const LOAD_LIMIT      = 'LOAD_LIMIT';
    public const TRAVEL_BUFFER   = 'TRAVEL_BUFFER';
    public const SNAPSHOT_STALE  = 'SNAPSHOT_STALE';
    public const SHIFT_LOCKED    = 'SHIFT_LOCKED';

    /**
     * Danh sách các mã chặn cứng khi Điều phối viên sửa phân công bằng tay (CN4).
     * H1 (TIME_CONFLICT), H2 (NO_SKILL), H3 (CAPACITY_FULL), SHIFT_LOCKED, SNAPSHOT_STALE.
     */
    public const HARD_MANUAL_CODES = [
        self::TIME_CONFLICT,
        self::NO_SKILL,
        self::CAPACITY_FULL,
        self::SHIFT_LOCKED,
        self::SNAPSHOT_STALE,
    ];

    /**
     * Danh sách các mã cảnh báo mềm khi sửa tay (cho phép ghi đè kèm lý do vào audit_log).
     * H4 (NO_AVAILABLE), H5 (LOAD_LIMIT), TRAVEL_BUFFER.
     */
    public const SOFT_MANUAL_CODES = [
        self::NO_AVAILABLE,
        self::LOAD_LIMIT,
        self::TRAVEL_BUFFER,
    ];

    public static function isHardInManualEdit(string $code): bool
    {
        return in_array($code, self::HARD_MANUAL_CODES, true);
    }

    /**
     * @return array{vi: string, en: string, severity_algo: string, severity_manual: string}
     */
    public static function metadata(string $code): array
    {
        $catalog = self::catalog();
        return $catalog[$code] ?? [
            'vi' => 'Vi phạm điều kiện phân công (' . $code . ')',
            'en' => 'Assignment constraint violation (' . $code . ')',
            'severity_algo' => 'HARD',
            'severity_manual' => 'HARD',
        ];
    }

    public static function messageVi(string $code): string
    {
        return self::metadata($code)['vi'];
    }

    public static function messageEn(string $code): string
    {
        return self::metadata($code)['en'];
    }

    /**
     * @return array<string, array{vi: string, en: string, severity_algo: string, severity_manual: string}>
     */
    public static function catalog(): array
    {
        return [
            self::TIME_CONFLICT => [
                'vi' => 'Trùng giờ với một ca khác đã được phân công',
                'en' => 'Time conflict with another assigned shift',
                'severity_algo' => 'HARD',
                'severity_manual' => 'HARD',
            ],
            self::NO_SKILL => [
                'vi' => 'Thiếu kỹ năng bắt buộc hoặc chưa đạt cấp tối thiểu của vị trí',
                'en' => 'Missing required skill or insufficient skill level',
                'severity_algo' => 'HARD',
                'severity_manual' => 'HARD',
            ],
            self::CAPACITY_FULL => [
                'vi' => 'Ca hoặc vị trí kỹ năng đã đủ số người tối đa',
                'en' => 'Shift or skill slot has reached maximum capacity',
                'severity_algo' => 'HARD',
                'severity_manual' => 'HARD',
            ],
            self::NO_AVAILABLE => [
                'vi' => 'Ca nằm ngoài khung lịch rảnh đã đăng ký của tình nguyện viên',
                'en' => 'Shift falls outside volunteer declared availability',
                'severity_algo' => 'HARD',
                'severity_manual' => 'SOFT_OVERRIDABLE',
            ],
            self::LOAD_LIMIT => [
                'vi' => 'Vượt quá số ca tối đa mỗi tuần của tình nguyện viên',
                'en' => 'Exceeds volunteer maximum shifts per week',
                'severity_algo' => 'HARD',
                'severity_manual' => 'SOFT_OVERRIDABLE',
            ],
            self::TRAVEL_BUFFER => [
                'vi' => 'Không đủ thời gian di chuyển giữa hai địa điểm ca liên tiếp',
                'en' => 'Insufficient travel buffer between consecutive shift locations',
                'severity_algo' => 'SOFT',
                'severity_manual' => 'SOFT_OVERRIDABLE',
            ],
            self::SNAPSHOT_STALE => [
                'vi' => 'Dữ liệu đăng ký hoặc ca đã thay đổi sau khi chạy thuật toán, cần chạy lại',
                'en' => 'Input dataset changed since plan generation; re-run required',
                'severity_algo' => 'HARD',
                'severity_manual' => 'HARD',
            ],
            self::SHIFT_LOCKED => [
                'vi' => 'Ca đã chốt và bị khóa, cần mở khóa kèm lý do trước khi sửa',
                'en' => 'Shift is locked; unlock with audit reason required before editing',
                'severity_algo' => 'HARD',
                'severity_manual' => 'HARD',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

/**
 * Dịch vụ Check-in bằng mã QR xoay vòng HMAC 30 giây (N3).
 * Chống điểm danh hộ bằng ảnh chụp màn hình:
 * - Mã QR đổi mỗi khung 30 giây (timeStep = floor(timestamp / 30)).
 * - Ký HMAC-SHA256 gắn chặt với shiftId và khung thời gian.
 * - Khi quét phải kiểm tra đúng tình nguyện viên được phân công vào ca đó và nằm trong khung giờ cho phép.
 */
final class QrCheckinService
{
    private const ROTATION_SECONDS = 30;
    private string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey
            ?? (getenv('HMAC_SECRET') ?: (getenv('APP_SECRET') ?: ($_ENV['HMAC_SECRET'] ?? ($_ENV['APP_SECRET'] ?? 'xanhhub-open-source-demo-secret-key-2026'))));
    }

    public function generateToken(int $shiftId, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $step = intdiv($ts, self::ROTATION_SECONDS);
        $mac = hash_hmac('sha256', $shiftId . ':' . $step, $this->secretKey);
        return sprintf('XHQR-%d-%d-%s', $shiftId, $step, substr($mac, 0, 24));
    }

    /**
     * Xác thực mã QR và kiểm tra các điều kiện vận hành (N3).
     *
     * @return array{valid: bool, error_code: ?string, message_vi: string, token_hash: string}
     */
    public function verifyCheckin(
        string $token,
        int $expectedShiftId,
        int $shiftStartEpoch,
        int $shiftEndEpoch,
        bool $isAssignedVolunteer,
        bool $alreadyCheckedIn,
        ?int $nowEpoch = null
    ): array {
        $now = $nowEpoch ?? time();
        $tokenHash = hash('sha256', $token);

        if (!$isAssignedVolunteer) {
            return [
                'valid' => false,
                'error_code' => 'NOT_ASSIGNED_TO_SHIFT',
                'message_vi' => 'Tài khoản của bạn không thuộc danh sách phân công của ca này.',
                'token_hash' => $tokenHash,
            ];
        }

        if ($alreadyCheckedIn) {
            return [
                'valid' => false,
                'error_code' => 'ALREADY_CHECKED_IN',
                'message_vi' => 'Bạn đã điểm danh ca này trước đó (chỉ tính 1 lượt).',
                'token_hash' => $tokenHash,
            ];
        }

        // Kiểm tra khung giờ check-in: từ 30 phút trước giờ bắt đầu đến 30 phút sau giờ kết thúc
        if ($now < ($shiftStartEpoch - 1800) || $now > ($shiftEndEpoch + 1800)) {
            return [
                'valid' => false,
                'error_code' => 'OUTSIDE_CHECKIN_WINDOW',
                'message_vi' => 'Ngoài khung giờ điểm danh cho phép (từ 30 phút trước khi ca bắt đầu).',
                'token_hash' => $tokenHash,
            ];
        }

        if (!preg_match('/^XHQR-(\d+)-(\d+)-([a-f0-9]{24})$/', trim($token), $m)) {
            return [
                'valid' => false,
                'error_code' => 'INVALID_TOKEN_FORMAT',
                'message_vi' => 'Định dạng mã QR không hợp lệ.',
                'token_hash' => $tokenHash,
            ];
        }

        $tokenShiftId = (int) $m[1];
        $tokenStep = (int) $m[2];
        $tokenMacPrefix = $m[3];

        if ($tokenShiftId !== $expectedShiftId) {
            return [
                'valid' => false,
                'error_code' => 'WRONG_SHIFT_QR',
                'message_vi' => 'Mã QR này thuộc về một ca trực khác.',
                'token_hash' => $tokenHash,
            ];
        }

        $currentStep = intdiv($now, self::ROTATION_SECONDS);
        if ($tokenStep !== $currentStep) {
            return [
                'valid' => false,
                'error_code' => 'QR_TOKEN_EXPIRED',
                'message_vi' => 'Mã QR 30 giây đã hết hạn, vui lòng quét mã mới trên màn hình điều phối.',
                'token_hash' => $tokenHash,
            ];
        }

        $expectedMac = substr(hash_hmac('sha256', $tokenShiftId . ':' . $tokenStep, $this->secretKey), 0, 24);
        if (!hash_equals($expectedMac, $tokenMacPrefix)) {
            return [
                'valid' => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message_vi' => 'Chữ ký xác thực mã QR không hợp lệ.',
                'token_hash' => $tokenHash,
            ];
        }

        return [
            'valid' => true,
            'error_code' => null,
            'message_vi' => 'Điểm danh QR thành công!',
            'token_hash' => $tokenHash,
        ];
    }
}

<?php
declare(strict_types=1);
final class RegistrationPolicy {
    public function decide(int $capacity, int $registered,
        bool $open, bool $waitingEnabled): string {
        // Kiểm tra tính hợp lệ của dữ liệu
        if ($capacity <= 0 || $registered < 0 || $registered > $capacity) {
            return 'rejected';
        }

        // Sự kiện đã đóng -> từ chối đăng ký (kể cả còn chỗ)
        if (!$open) {
            return 'rejected';
        }

        // Sự kiện mở và còn chỗ
        if ($registered < $capacity) {
            return 'confirmed';
        }

        // Sự kiện mở, hết chỗ: nếu cho phép danh sách chờ thì waiting, ngược lại rejected
        return $waitingEnabled ? 'waiting' : 'rejected';
    }
}

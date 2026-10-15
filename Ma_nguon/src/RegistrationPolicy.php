<?php
declare(strict_types=1);

/**
 * Module kiểm tra điều kiện tiếp nhận tình nguyện viên tham gia hoạt động
 * Nhóm 06 — Lớp CNTT 1810 | Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên
 * 
 * - confirmed: Còn chỗ và hoạt động đang mở
 * - waiting: Hết chỗ nhưng có danh sách chờ
 * - rejected: Không hợp lệ, hoạt động đã đóng hoặc hết chỗ không cho chờ
 */
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

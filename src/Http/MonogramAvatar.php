<?php

declare(strict_types=1);

namespace XanhHub\Http;

/**
 * Bộ sinh Avatar Chữ cái đầu Tiếng Việt (Monogram SVG Generator)
 * Chuẩn Đoàn Thanh niên Cộng sản Hồ Chí Minh với 10 Gradient tất định,
 * hiển thị đồng nhất và sắc nét trên toàn hệ thống Xanh Hub.
 */
final class MonogramAvatar
{
    /**
     * Bảng màu 10 dải Gradient rực rỡ đặc trưng phong trào Đoàn - Hội thanh niên.
     */
    private const GRADIENTS = [
        ['#0057B8', '#0091FF'], // 0: Xanh Thanh niên Truyền thống (Youth Union Signature Blue)
        ['#059669', '#34D399'], // 1: Xanh Lá Môi trường / Chủ Nhật Xanh (Volunteer Emerald)
        ['#0284C7', '#38BDF8'], // 2: Xanh Dương Đại dương / Tiếp Sức Mùa Thi (Sky Cyan)
        ['#7C3AED', '#A78BFA'], // 3: Tím Tuổi trẻ Sáng tạo / Chuyển đổi số (Digital Indigo)
        ['#DC2626', '#F87171'], // 4: Đỏ Nhiệt huyết Tình nguyện (Youth Flame Crimson)
        ['#D97706', '#FBBF24'], // 5: Vàng Cam Khát vọng (Amber Sunshine)
        ['#0D9488', '#2DD4BF'], // 6: Xanh Ngọc Biển đảo (Teal Coastal)
        ['#2563EB', '#60A5FA'], // 7: Xanh Lam Hoàng gia (Royal Blue)
        ['#4F46E5', '#818CF8'], // 8: Chàm Công nghệ (Tech Violet)
        ['#BE123C', '#FB7185'], // 9: Hồng Đóa hoa Tuổi trẻ (Rose Passion)
    ];

    /**
     * Tách chữ cái đầu tiếng Việt an toàn đa byte UTF-8.
     * Quy tắc:
     * - Họ + Tên: lấy chữ cái đầu của Họ và chữ cái đầu của Tên (VD: "Nguyễn Khánh Linh" -> "NL")
     * - 1 từ: lấy 2 ký tự đầu viết hoa (VD: "Linh" -> "LI", "Admin" -> "AD")
     * - Rỗng: trả về "XH" (Xanh Hub)
     */
    public static function extractInitials(?string $fullName): string
    {
        if ($fullName === null) {
            return 'XH';
        }

        $trimmed = trim($fullName);
        if ($trimmed === '') {
            return 'XH';
        }

        // Tách các từ dựa trên khoảng trắng
        $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) === 0) {
            return 'XH';
        }

        if (count($words) === 1) {
            $word = $words[0];
            $len = mb_strlen($word, 'UTF-8');
            if ($len >= 2) {
                return mb_strtoupper(mb_substr($word, 0, 2, 'UTF-8'), 'UTF-8');
            }
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
        }

        $firstWord = $words[0];
        $lastWord = $words[count($words) - 1];

        $firstChar = mb_substr($firstWord, 0, 1, 'UTF-8');
        $lastChar = mb_substr($lastWord, 0, 1, 'UTF-8');

        return mb_strtoupper($firstChar . $lastChar, 'UTF-8');
    }

    /**
     * Sinh chuỗi SVG vector tròn chuẩn với gradient tất định theo ID.
     */
    public static function svg(int $id, ?string $fullName = null): string
    {
        $initials = self::extractInitials($fullName);
        $gradIdx = (abs($id) > 0 ? (abs($id) - 1) : 0) % count(self::GRADIENTS);
        $colors = self::GRADIENTS[$gradIdx];
        $gradId = 'xh-avatar-grad-' . abs($id);

        $escapedInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
            . '<defs>'
            . '<linearGradient id="' . $gradId . '" x1="0%" y1="0%" x2="100%" y2="100%">'
            . '<stop offset="0%" stop-color="' . $colors[0] . '"/>'
            . '<stop offset="100%" stop-color="' . $colors[1] . '"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<circle cx="50" cy="50" r="50" fill="url(#' . $gradId . ')"/>'
            . '<circle cx="50" cy="50" r="48" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="2"/>'
            . '<text x="50" y="50" dy=".36em" text-anchor="middle" fill="#FFFFFF" '
            . 'font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif" '
            . 'font-size="38" font-weight="800" letter-spacing="1">' . $escapedInitials . '</text>'
            . '</svg>';
    }

    /**
     * Sinh Data URI dạng Base64 (an toàn 100% khi nhúng vào CSS, JSON API và HTML img src).
     */
    public static function dataUriBase64(int $id, ?string $fullName = null): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($id, $fullName));
    }

    /**
     * Sinh Data URI dạng UTF-8 URL encoded.
     */
    public static function dataUriUtf8(int $id, ?string $fullName = null): string
    {
        return 'data:image/svg+xml;utf8,' . rawurlencode(self::svg($id, $fullName));
    }
}

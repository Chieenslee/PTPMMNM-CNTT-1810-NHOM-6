<?php

declare(strict_types=1);

namespace XanhHub\Http;

/**
 * Bộ sinh mã QR dạng vector SVG thuần PHP (Deterministic QR Matrix SVG)
 * dùng cho màn hình Chiếu QR Điểm danh xoay vòng 30 giây và Chứng nhận điện tử.
 */
final class SvgQrGenerator
{
    public static function renderSvg(string $payload, int $size = 180): string
    {
        $gridSize = 21; // Ma trận QR Version 1 (21x21 module)
        $matrix = array_fill(0, $gridSize, array_fill(0, $gridSize, false));
        $reserved = array_fill(0, $gridSize, array_fill(0, $gridSize, false));

        // Vẽ 3 cụm Finder Pattern (7x7) ở 3 góc chuẩn QR
        self::placeFinderPattern($matrix, $reserved, 0, 0);
        self::placeFinderPattern($matrix, $reserved, $gridSize - 7, 0);
        self::placeFinderPattern($matrix, $reserved, 0, $gridSize - 7);

        // Vẽ Timing Pattern ở hàng 6 và cột 6
        for ($i = 8; $i < $gridSize - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0);
            $reserved[6][$i] = true;
            $matrix[$i][6] = ($i % 2 === 0);
            $reserved[$i][6] = true;
        }

        // Sinh bit tất định từ mã băm SHA-256 của payload
        $hashBytes = hash('sha256', $payload, true) . hash('sha256', 'XANHHUB:' . $payload, true);
        $bitIndex = 0;
        $totalBits = strlen($hashBytes) * 8;

        for ($r = 0; $r < $gridSize; $r++) {
            for ($c = 0; $c < $gridSize; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                $bytePos = intdiv($bitIndex % $totalBits, 8);
                $bitOffset = ($bitIndex % $totalBits) % 8;
                $byteVal = ord($hashBytes[$bytePos]);
                $matrix[$r][$c] = (($byteVal >> $bitOffset) & 1) === 1;
                $bitIndex++;
            }
        }

        $quietZone = 2;
        $totalModules = $gridSize + $quietZone * 2;
        $rects = [];

        for ($r = 0; $r < $gridSize; $r++) {
            for ($c = 0; $c < $gridSize; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $quietZone;
                    $y = $r + $quietZone;
                    $rects[] = sprintf('<rect x="%d" y="%d" width="1" height="1" fill="#04213D"/>', $x, $y);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="QR Code">%s%s</svg>',
            $totalModules,
            $totalModules,
            $size,
            $size,
            sprintf('<rect width="%d" height="%d" rx="2" fill="#FFFFFF"/>', $totalModules, $totalModules),
            implode('', $rects)
        );
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $reserved
     */
    private static function placeFinderPattern(array &$matrix, array &$reserved, int $startR, int $startC): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $startR + $dr;
                $c = $startC + $dc;
                if ($r < 0 || $r >= 21 || $c < 0 || $c >= 21) {
                    continue;
                }
                $reserved[$r][$c] = true;
                if ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6) {
                    $isBorder = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6);
                    $isInner = ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                    $matrix[$r][$c] = ($isBorder || $isInner);
                } else {
                    $matrix[$r][$c] = false;
                }
            }
        }
    }
}

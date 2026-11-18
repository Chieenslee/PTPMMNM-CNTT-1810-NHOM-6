<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

/**
 * Dịch vụ Đa ngôn ngữ Toàn trang qua Translation API (Google Translate GTX API + Bộ nhớ đệm JSON).
 *
 * - Hỗ trợ dịch hàng loạt (batch) toàn bộ văn bản DOM theo thời gian thực khi chuyển VI ↔ EN (hoặc mở rộng ngôn ngữ khác).
 * - Tích hợp từ điển chuyên ngành Đoàn Thanh niên – Hội Sinh viên & Bộ nhớ đệm đĩa để đạt tốc độ phản hồi < 5ms cho các lần lặp lại.
 */
final class TranslationService
{
    /** @var array<string, string> */
    private const PRESEED_VI_EN = [
        'Tình nguyện Thanh niên Việt Nam' => 'Youth Volunteer Hub Vietnam',
        'Trang chủ' => 'Home',
        'Chiến dịch' => 'Campaigns',
        'Nhóm Kỹ năng' => 'Skill Groups',
        'Bản đồ GIS' => 'GIS Map',
        'Bản tin & Phim' => 'News & Film',
        'Giới thiệu' => 'About Us',
        'Cổng Tình nguyện viên' => 'Volunteer Portal',
        'Tình nguyện viên' => 'Volunteers',
        'Bàn Điều phối' => 'Coordinator Desk',
        'Hồ sơ Ban Chỉ huy' => 'Commander Profile',
        'Hồ sơ Cá nhân' => 'My Profile',
        'Quản trị Hệ thống' => 'System Admin',
        'Chứng nhận' => 'Certificates',
        'Bảng Vàng' => 'Leaderboard',
        'Đăng nhập' => 'Sign In',
        'Đăng ký' => 'Register',
        'Đăng xuất' => 'Sign Out',
        'Quét QR' => 'Scan QR',
        'Tiện ích' => 'Utilities',
        'Phân tích BI' => 'BI Analytics',
        'Tất cả' => 'All',
        'Ứng tuyển ngay' => 'Apply Now',
        'Xem chi tiết' => 'View Details',
        'Điều phối' => 'Coordinate',
        'Soạn bản tin mới' => 'Compose News',
        'Soạn & Đăng Bản tin Mới' => 'Compose & Publish News',
        'Quản lý Bản tin' => 'News Management',
        'Đã xuất bản' => 'Published',
        'Bản nháp' => 'Draft',
        'Sửa' => 'Edit',
        'Xóa' => 'Delete',
        'Lưu thay đổi' => 'Save Changes',
        'Đóng' => 'Close',
        'Hủy' => 'Cancel',
        'Xác nhận' => 'Confirm',
        'Sơ cứu Y tế' => 'Medical First Aid',
        'Hậu cần Tiếp sức' => 'Logistics & Support',
        'Truyền thông' => 'Media & Comms',
        'Điều tiết Giao thông' => 'Traffic Control',
        'Điều tiết GT' => 'Traffic Control',
        'Hỗ trợ Kỹ thuật số' => 'Digital Support',
        'Kỹ thuật số' => 'Digital Tech',
        'Chiến dịch Mùa Hè Xanh 2026' => 'Green Summer Campaign 2026',
        'Chiến dịch Tiếp Sức Mùa Thi' => 'Exam Support Campaign',
        'Chiến dịch Chủ Nhật Xanh' => 'Green Sunday Campaign',
        'Đang mở' => 'Open',
        'Cần Y tế' => 'Need Medical',
        'Hàng tuần' => 'Weekly',
        'Đoàn TNCS Hồ Chí Minh' => 'Ho Chi Minh Communist Youth Union',
        'Hội Sinh viên Việt Nam' => 'Vietnam National Union of Students',
    ];

    private string $cacheFile;

    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile ?? (rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'xanhhub_i18n_cache_v2.json');
    }

    /**
     * Dịch hàng loạt mảng chuỗi sang ngôn ngữ đích (mặc định 'en').
     *
     * @param list<string> $texts
     * @return array<string, string> Map từ chuỗi gốc -> chuỗi đã dịch
     */
    public function translateBatch(array $texts, string $targetLang = 'en', string $sourceLang = 'vi'): array
    {
        $target = strtolower(trim($targetLang));
        if (!in_array($target, ['en', 'ja', 'ko', 'fr', 'zh-cn'], true)) {
            $target = 'en';
        }
        $source = strtolower(trim($sourceLang)) ?: 'vi';

        $unique = [];
        foreach ($texts as $t) {
            if (!is_string($t)) {
                continue;
            }
            $clean = trim((string) preg_replace('/\s+/u', ' ', $t));
            if ($clean === '' || mb_strlen($clean) > 600 || $this->shouldSkipTranslation($clean)) {
                continue;
            }
            $unique[$clean] = true;
        }
        $uniqueList = array_keys($unique);
        if ($uniqueList === []) {
            return [];
        }

        if ($target === $source) {
            $identity = [];
            foreach ($uniqueList as $s) {
                $identity[$s] = $s;
            }
            return $identity;
        }

        $cache = $this->loadCache();
        $langCache = $cache[$target] ?? [];
        if ($target === 'en') {
            foreach (self::PRESEED_VI_EN as $vi => $en) {
                if (!isset($langCache[$vi])) {
                    $langCache[$vi] = $en;
                }
            }
        }

        $result = [];
        $missing = [];
        foreach ($uniqueList as $item) {
            if (isset($langCache[$item]) && is_string($langCache[$item]) && $langCache[$item] !== '') {
                $result[$item] = $langCache[$item];
            } else {
                $missing[] = $item;
            }
        }

        if ($missing !== []) {
            $fetched = $this->fetchGoogleTranslateChunks($missing, $source, $target);
            foreach ($missing as $orig) {
                $translated = $fetched[$orig] ?? $orig;
                $result[$orig] = $translated;
                if ($translated !== '' && $translated !== $orig) {
                    $langCache[$orig] = $translated;
                }
            }
            $cache[$target] = $langCache;
            $this->saveCache($cache);
        }

        return $result;
    }

    private function shouldSkipTranslation(string $text): bool
    {
        // Bỏ qua số thuần túy, mã code, email, URL, tọa độ GPS
        if (preg_match('/^[\d\s.,:%+\-\/°NSEW()]+$/u', $text) === 1) {
            return true;
        }
        if (preg_match('/^(XHQR-|XH\d{4}-|CA-\d+|SV\d+|ID\s*#\d+|https?:\/\/)/i', $text) === 1) {
            return true;
        }
        if (filter_var($text, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }
        return false;
    }

    /**
     * Gửi các lô chuỗi tới Translation API (clients5 Chrome Ext + google_at + MyMemory fallback) qua curl_multi song song.
     *
     * @param list<string> $items
     * @return array<string, string>
     */
    private function fetchGoogleTranslateChunks(array $items, string $source, string $target): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $sep = "\n@@@\n";
        $chunks = [];
        $curChunk = [];
        $curLen = 0;

        foreach ($items as $item) {
            $addLen = strlen(urlencode($item)) + 16;
            if ($curChunk !== [] && ($curLen + $addLen > 1400 || count($curChunk) >= 18)) {
                $chunks[] = $curChunk;
                $curChunk = [];
                $curLen = 0;
            }
            $curChunk[] = $item;
            $curLen += $addLen;
        }
        if ($curChunk !== []) {
            $chunks[] = $curChunk;
        }

        $out = [];
        $unresolved = $this->runProviderChunks($chunks, 'clients5', $source, $target, $sep, $out);
        if ($unresolved !== []) {
            $unresolved = $this->runProviderChunks($unresolved, 'google_at', $source, $target, $sep, $out);
        }
        if ($unresolved !== []) {
            $singleChunks = [];
            foreach ($unresolved as $uChunk) {
                foreach ($uChunk as $singleItem) {
                    if (strlen($singleItem) <= 450) {
                        $singleChunks[] = [$singleItem];
                    }
                }
            }
            if ($singleChunks !== []) {
                $this->runProviderChunks($singleChunks, 'mymemory', $source, $target, $sep, $out);
            }
        }

        return $out;
    }

    /**
     * @param list<list<string>> $chunks
     * @param array<string, string> $out
     * @return list<list<string>> Các chunk chưa dịch thành công để fallback sang provider tiếp theo
     */
    private function runProviderChunks(array $chunks, string $provider, string $source, string $target, string $sep, array &$out): array
    {
        $failedChunks = [];
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

        foreach (array_chunk($chunks, 6) as $batchGroup) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($batchGroup as $idx => $chunk) {
                $joined = implode($sep, $chunk);
                $url = match ($provider) {
                    'clients5' => 'https://clients5.google.com/translate_a/t?' . http_build_query([
                        'client' => 'dict-chrome-ex',
                        'sl' => $source,
                        'tl' => $target,
                        'q' => $joined,
                    ]),
                    'google_at' => 'https://translate.google.com/translate_a/single?' . http_build_query([
                        'client' => 'at',
                        'dt' => 't',
                        'dj' => '1',
                        'sl' => $source,
                        'tl' => $target,
                        'q' => $joined,
                    ]),
                    default => 'https://api.mymemory.translated.net/get?' . http_build_query([
                        'q' => $joined,
                        'langpair' => $source . '|' . $target,
                    ]),
                };

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'User-Agent: ' . $ua,
                    'Accept: application/json, text/plain, */*',
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = ['ch' => $ch, 'chunk' => $chunk];
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running > 0);

            foreach ($handles as $entry) {
                $ch = $entry['ch'];
                $chunk = $entry['chunk'];
                $body = curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($httpCode !== 200 || !is_string($body) || $body === '') {
                    $failedChunks[] = $chunk;
                    continue;
                }

                $fullTranslated = $this->extractTranslatedString($body);
                if (trim($fullTranslated) === '') {
                    $failedChunks[] = $chunk;
                    continue;
                }

                $parts = preg_split('/\s*@\s*@\s*@\s*/u', $fullTranslated);
                if (is_array($parts) && count($parts) === count($chunk)) {
                    foreach ($chunk as $i => $orig) {
                        $tVal = trim((string) $parts[$i]);
                        if ($tVal !== '') {
                            $out[$orig] = $tVal;
                        }
                    }
                } elseif (count($chunk) === 1) {
                    $out[$chunk[0]] = trim($fullTranslated);
                } else {
                    $failedChunks[] = $chunk;
                }
            }

            curl_multi_close($mh);
        }

        return $failedChunks;
    }

    private function extractTranslatedString(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
            $buf = '';
            foreach ($decoded['sentences'] as $sent) {
                if (is_array($sent) && isset($sent['trans']) && is_string($sent['trans'])) {
                    $buf .= $sent['trans'];
                }
            }
            return $buf;
        }

        if (isset($decoded['responseData']['translatedText']) && is_string($decoded['responseData']['translatedText'])) {
            return $decoded['responseData']['translatedText'];
        }

        if (isset($decoded[0]) && is_string($decoded[0])) {
            $buf = '';
            foreach ($decoded as $seg) {
                if (is_string($seg)) {
                    $buf .= $seg;
                }
            }
            return $buf;
        }

        if (isset($decoded[0]) && is_array($decoded[0])) {
            $buf = '';
            foreach ($decoded[0] as $seg) {
                if (is_array($seg) && isset($seg[0]) && is_string($seg[0])) {
                    $buf .= $seg[0];
                }
            }
            if ($buf !== '') {
                return $buf;
            }
            foreach ($decoded as $seg) {
                if (is_array($seg) && isset($seg[0]) && is_string($seg[0])) {
                    $buf .= $seg[0];
                }
            }
            return $buf;
        }

        return '';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadCache(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }
        $raw = @file_get_contents($this->cacheFile);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, array<string, string>> $cache
     */
    private function saveCache(array $cache): void
    {
        @file_put_contents($this->cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

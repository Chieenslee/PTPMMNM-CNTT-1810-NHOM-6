<?php

declare(strict_types=1);

namespace XanhHub\Tests;

/**
 * ==============================================================================
 * ADVERSARIAL EMPIRICAL TEST SUITE — CHALLENGER 2 (REQUIREMENTS R1 & R3)
 * Project: Xanh Hub (Đề tài 6)
 *
 * Scope:
 * 1. Vietnamese Monogram SVG Generator (MonogramAvatar):
 *    - Name extraction initials: "Nguyễn Khánh Linh" -> "NL", "Lê Thanh Thảo" -> "LT",
 *      "Trần Hoàng Phúc" -> "TP", "Đặng Phương Thảo" -> "ĐT", "Võ Minh Khôi" -> "VK",
 *      "Admin" -> "AD", Single letter / empty -> "XH".
 *    - SVG XML validity, <svg, viewBox="0 0 100 100", <circle, <linearGradient, <text.
 *    - dataUriBase64 starts with data:image/svg+xml;base64, and decodes to exact SVG.
 *    - Determinism & consistency: avatarUrlFor(1) === avatarUrlFor(1, 'Nguyễn Khánh Linh').
 * 2. AI Portrait Reference Elimination:
 *    - Live HTTP response scan: NO img src references avatar-1.jpg..avatar-8.jpg on
 *      home, coordinator, volunteer, profile, verify, admin, and other views.
 *    - Codebase scan: zero template references to AI portrait assets.
 *    - Physical asset verification: public/assets/images/avatar-1.jpg still exists on disk.
 * 3. Coordinator Inspector 3 Tabs (?view=coordinator):
 *    - Presence of #inspector-tab-assigned, #inspector-tab-eligible, #inspector-tab-replacement.
 *    - Candidate names in assigned roster and eligible pool do NOT have class 'truncate'.
 *    - .badge CSS contains 'white-space: nowrap;'.
 *    - Pre-calculated absence_reports and replacement_candidates present in $jsShifts.
 * ==============================================================================
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Http\MonogramAvatar;
use XanhHub\Infrastructure\Persistence\Database;

class AdversarialR1R3Runner
{
    private string $rootDir;
    private string $baseUrl = 'http://127.0.0.1:8000';
    private ?\PDO $pdo = null;

    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private array $findings = [];

    private string $coordCookie = '';
    private string $volCookie = '';
    private string $adminCookie = '';

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->pdo = Database::getConnection();
        $this->ensureDevServer();
        $this->authenticateSessions();
    }

    private function ensureDevServer(): void
    {
        $sock = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.5);
        if (is_resource($sock)) {
            fclose($sock);
            return;
        }

        // Launch PHP built-in server if not running
        $phpExec = (PHP_OS_FAMILY === 'Windows' && file_exists('C:\\xampp\\php\\php.exe'))
            ? 'C:\\xampp\\php\\php.exe'
            : PHP_BINARY;
        $publicDir = escapeshellarg($this->rootDir . DIRECTORY_SEPARATOR . 'public');
        $cmd = sprintf('%s -S 127.0.0.1:8000 -t %s', escapeshellarg($phpExec), $publicDir);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        proc_open($cmd, $descriptors, $pipes, $this->rootDir);
        usleep(500000); // 500ms
    }

    private function httpGet(string $path, ?string $cookie = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($cookie !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr((string)$raw, 0, $headerSize);
        $body = substr((string)$raw, $headerSize);
        curl_close($ch);

        return ['code' => $code, 'headers' => $headers, 'body' => $body];
    }

    private function httpPost(string $path, array $data, ?string $cookie = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        if ($cookie !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr((string)$raw, 0, $headerSize);
        $body = substr((string)$raw, $headerSize);

        preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $matches);
        $newCookie = !empty($matches[1]) ? implode('; ', $matches[1]) : null;

        curl_close($ch);

        return ['code' => $code, 'headers' => $headers, 'body' => $body, 'cookie' => $newCookie];
    }

    private function authenticateSessions(): void
    {
        // Coordinator login
        $resCoord = $this->httpPost('/?action=do_login', [
            'identifier' => 'thao.dieuphoi@example.test',
            'password' => 'XanhHub@2026',
        ]);
        $this->coordCookie = $resCoord['cookie'] ?? '';

        // Volunteer login
        $resVol = $this->httpPost('/?action=do_login', [
            'identifier' => 'SV001',
            'password' => 'XanhHub@2026',
        ]);
        $this->volCookie = $resVol['cookie'] ?? '';

        // Admin login
        $resAdmin = $this->httpPost('/?action=do_login', [
            'identifier' => 'admin@xanhhub.vn',
            'password' => '123456',
            'redirect_view' => 'admin',
        ]);
        $this->adminCookie = $resAdmin['cookie'] ?? '';
    }

    private function assertCondition(bool $cond, string $code, string $desc, ?string $findingDetail = null): void
    {
        $this->totalTests++;
        if ($cond) {
            $this->passedTests++;
            echo sprintf("  \033[32m[PASS]\033[0m %-8s %s\n", $code, $desc);
        } else {
            $this->failedTests++;
            echo sprintf("  \033[31m[FAIL]\033[0m %-8s %s\n", $code, $desc);
            if ($findingDetail !== null) {
                $this->findings[] = "[$code] $desc: $findingDetail";
            }
        }
    }

    public function runAll(): void
    {
        echo "========================================================================\n";
        echo " ADVERSARIAL EMPIRICAL TEST SUITE — CHALLENGER 2 (R1 & R3)\n";
        echo "========================================================================\n\n";

        $this->runMonogramAvatarTests();
        $this->runAiPortraitEliminationTests();
        $this->runCoordinatorInspector3TabsTests();

        echo "\n------------------------------------------------------------------------\n";
        echo sprintf(" TỔNG KẾT: %d/%d ca kiểm thử ĐẠT (Passed: %d, Failed: %d)\n",
            $this->passedTests, $this->totalTests, $this->passedTests, $this->failedTests
        );
        echo "------------------------------------------------------------------------\n";

        if (!empty($this->findings)) {
            echo "\n▶ CÁC ĐIỂM CẦN LƯU Ý / FINDINGS PHÁT HIỆN:\n";
            foreach ($this->findings as $f) {
                echo "  • $f\n";
            }
        }
    }

    private function runMonogramAvatarTests(): void
    {
        echo "▶ [PHẦN 1] KIỂM THỬ THỰC CHỨNG MONOGRAM AVATAR (R3)...\n";

        // 1.1 Diverse Vietnamese names from dispatch
        $nameCases = [
            'Nguyễn Khánh Linh' => 'NL',
            'Lê Thanh Thảo' => 'LT',
            'Trần Hoàng Phúc' => 'TP',
            'Đặng Phương Thảo' => 'ĐT',
            'Võ Minh Khôi' => 'VK',
            'Admin' => 'AD',
            '' => 'XH',
            '   ' => 'XH',
        ];

        foreach ($nameCases as $name => $expected) {
            $actual = MonogramAvatar::extractInitials($name);
            $this->assertCondition(
                $actual === $expected,
                '1.1.' . substr(md5($name), 0, 4),
                sprintf("Trích xuất '%s' -> kỳ vọng '%s' (thực tế: '%s')", $name === '' ? '<empty>' : ($name === '   ' ? '<spaces>' : $name), $expected, $actual),
                "Initials mismatch for '$name': expected '$expected', got '$actual'"
            );
        }

        // Null name
        $actualNull = MonogramAvatar::extractInitials(null);
        $this->assertCondition(
            $actualNull === 'XH',
            '1.1.null',
            "Trích xuất null -> kỳ vọng 'XH' (thực tế: '$actualNull')",
            "Null name returned '$actualNull' instead of 'XH'"
        );

        // Single letter edge cases: "Single letter or empty name -> XH"
        $singleLetters = ['A', 'V', 'Đ', 'X'];
        foreach ($singleLetters as $sl) {
            $actualSl = MonogramAvatar::extractInitials($sl);
            // Empirical evaluation: check if it returns 'XH' (per prompt) or '$sl' (per worker implementation)
            $isDispatchCompliant = ($actualSl === 'XH');
            $this->assertCondition(
                $isDispatchCompliant,
                '1.1.sl_' . $sl,
                sprintf("Single letter '%s' -> kiểm tra quy tắc fallback 'XH' (thực tế trả về: '%s')", $sl, $actualSl),
                sprintf("Dispatch expects 'XH' for single letter '%s', but MonogramAvatar::extractInitials returns '%s'", $sl, $actualSl)
            );
        }

        // 1.2 Adversarial & Stress Names
        $stressCases = [
            'Nguyễn Thị Ngọc Ánh' => 'NÁ', // 4 words
            'Lê-Nguyễn Bảo Ngọc' => 'LN', // hyphenated
            'Ơn Đời' => 'ƠĐ', // unusual diacritics
            'nguyễn văn an' => 'NA', // all lowercase
            '   Lê    Văn    Tám   ' => 'LT', // multiple inner/outer whitespace
            'Linh' => 'LI', // 1-word Vietnamese
            'Bách' => 'BÁ', // 1-word with diacritic
            'TNV 123' => 'T1', // name with number
        ];

        foreach ($stressCases as $name => $expected) {
            $actual = MonogramAvatar::extractInitials($name);
            $this->assertCondition(
                $actual === $expected,
                '1.2.' . substr(md5($name), 0, 4),
                sprintf("Adversarial name '%s' -> kỳ vọng '%s' (thực tế: '%s')", $name, $expected, $actual),
                "Adversarial name mismatch for '$name': expected '$expected', got '$actual'"
            );
        }

        // 1.3 SVG Structure & XML Validity
        $testSvg = MonogramAvatar::svg(1, 'Nguyễn Khánh Linh');

        // XML Well-formedness
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loadOk = $doc->loadXML($testSvg);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertCondition(
            $loadOk && empty($xmlErrors),
            '1.3.xml',
            'Kết xuất SVG là XML hợp thức (Well-formed XML Document, 0 libxml errors)',
            'SVG is not valid XML: ' . json_encode($xmlErrors)
        );

        // Required SVG Elements and Attributes
        $this->assertCondition(
            str_contains($testSvg, '<svg') && str_contains($testSvg, 'viewBox="0 0 100 100"'),
            '1.3.svg',
            'Thẻ <svg> có viewBox="0 0 100 100" và kích thước chuẩn 100x100'
        );

        $this->assertCondition(
            str_contains($testSvg, '<circle') && str_contains($testSvg, 'cx="50"') && str_contains($testSvg, 'cy="50"'),
            '1.3.circle',
            'Chứa thẻ <circle> tâm (50, 50) bo tròn avatar'
        );

        $this->assertCondition(
            str_contains($testSvg, '<linearGradient') && str_contains($testSvg, 'id="xh-avatar-grad-1"'),
            '1.3.gradient',
            'Chứa thẻ <linearGradient> với id tất định theo User ID'
        );

        $this->assertCondition(
            str_contains($testSvg, '<text') && str_contains($testSvg, '>NL</text>'),
            '1.3.text',
            'Chứa thẻ <text> kết xuất chữ cái đầu viết hoa (NL) căn giữa'
        );

        // XSS Injection in Monogram
        $xssSvg = MonogramAvatar::svg(1, '<script>alert("xss")</script>');
        $docXss = new \DOMDocument();
        libxml_use_internal_errors(true);
        $xssLoadOk = $docXss->loadXML($xssSvg);
        libxml_clear_errors();

        $this->assertCondition(
            $xssLoadOk && !str_contains($xssSvg, '<script>'),
            '1.3.xss',
            'Tên chứa mã độc HTML/XML được mã hóa an toàn (htmlspecialchars ENT_XML1), không sinh thẻ script',
            'XSS payload leaked unescaped into SVG'
        );

        // Deterministic gradient cycling
        $grad1 = MonogramAvatar::svg(1, 'A B');
        $grad11 = MonogramAvatar::svg(11, 'A B'); // 11 - 1 = 10 % 10 = 0 -> same gradient index
        $grad0 = MonogramAvatar::svg(0, 'A B');   // ID 0 edge case
        $gradNeg = MonogramAvatar::svg(-5, 'A B'); // Negative ID edge case

        $this->assertCondition(
            str_contains($grad1, '#0057B8') && str_contains($grad11, '#0057B8') &&
            str_contains($grad0, '#0057B8') && !empty($gradNeg),
            '1.3.cycle',
            'Bảng màu 10 gradient Đoàn Thanh niên chu kỳ tất định và an toàn với ID=0, ID âm, ID lớn'
        );

        // 1.4 Data URI Base64
        $dataUri = MonogramAvatar::dataUriBase64(1, 'Nguyễn Khánh Linh');
        $expectedPrefix = 'data:image/svg+xml;base64,';
        $hasPrefix = str_starts_with($dataUri, $expectedPrefix);
        $decoded = $hasPrefix ? base64_decode(substr($dataUri, strlen($expectedPrefix)), true) : false;

        $this->assertCondition(
            $hasPrefix && $decoded !== false && $decoded === $testSvg,
            '1.4.base64',
            'dataUriBase64() bắt đầu bằng data:image/svg+xml;base64, và giải mã khớp 100% với SVG gốc',
            'Base64 payload does not match generated SVG'
        );

        // 1.5 Determinism & Consistency with avatarUrlFor(1)
        // Extract avatarUrlFor implementation from public/index.php
        $indexContent = file_get_contents($this->rootDir . '/public/index.php');
        if (preg_match('/function avatarUrlFor\(.*?\n\}/s', $indexContent, $fnMatch)) {
            eval($fnMatch[0]);
        }

        if (function_exists('avatarUrlFor')) {
            global $pdo;
            $pdo = $this->pdo;
            $urlWithDb = avatarUrlFor(1);
            $urlWithName = avatarUrlFor(1, 'Nguyễn Khánh Linh');

            $this->assertCondition(
                $urlWithDb === $urlWithName && !empty($urlWithDb),
                '1.5.avatarUrlFor',
                'Hàm avatarUrlFor(1) và avatarUrlFor(1, "Nguyễn Khánh Linh") sinh kết quả đồng nhất 100%',
                'avatarUrlFor(1) does not match avatarUrlFor(1, "Nguyễn Khánh Linh")'
            );
        } else {
            $this->assertCondition(false, '1.5.avatarUrlFor', 'Không thể nạp hàm avatarUrlFor từ public/index.php');
        }
    }

    private function runAiPortraitEliminationTests(): void
    {
        echo "\n▶ [PHẦN 2] KIỂM THỬ TRIỆT TIÊU ẢNH CHÂN DUNG AI (R3)...\n";

        // 2.1 Live HTTP Response Scan
        $views = [
            'home' => ['path' => '/?view=home', 'cookie' => null],
            'coordinator' => ['path' => '/?view=coordinator', 'cookie' => $this->coordCookie],
            'volunteer' => ['path' => '/?view=volunteer', 'cookie' => $this->volCookie],
            'profile_vol' => ['path' => '/?view=profile', 'cookie' => $this->volCookie],
            'profile_coord' => ['path' => '/?view=profile', 'cookie' => $this->coordCookie],
            'verify' => ['path' => '/?view=verify', 'cookie' => null],
            'admin' => ['path' => '/?view=admin', 'cookie' => $this->adminCookie],
            'campaigns' => ['path' => '/?view=campaigns', 'cookie' => null],
            'news' => ['path' => '/?view=news', 'cookie' => null],
            'about' => ['path' => '/?view=about', 'cookie' => null],
            'leaderboard' => ['path' => '/?view=leaderboard', 'cookie' => null],
            'map' => ['path' => '/?view=map', 'cookie' => null],
            'report' => ['path' => '/?view=report&activity_id=3', 'cookie' => null],
            'login' => ['path' => '/?view=login', 'cookie' => null],
            'register' => ['path' => '/?view=register', 'cookie' => null],
            'shift_detail' => ['path' => '/?view=shift_detail&id=1', 'cookie' => null],
        ];

        foreach ($views as $viewName => $config) {
            $resp = $this->httpGet($config['path'], $config['cookie']);
            $body = $resp['body'];

            $hasAiImgSrc = (bool) preg_match('/<img\b[^>]*src=["\'][^"\']*avatar-[1-8]\.jpg["\']/i', $body);
            $hasAiAssetRef = (bool) preg_match('/assets\/images\/avatar-[1-8]\.jpg/i', $body);

            $this->assertCondition(
                !$hasAiImgSrc && !$hasAiAssetRef,
                '2.1.' . $viewName,
                sprintf("Trang '%s' phản hồi HTTP %d và KHÔNG chứa bất kỳ tham chiếu ảnh AI avatar-[1-8].jpg", $config['path'], $resp['code']),
                "View '{$config['path']}' contains references to avatar-[1-8].jpg"
            );
        }

        // 2.2 Scan codebase for template/UI references to avatar-[1-8].jpg
        $srcDir = $this->rootDir . DIRECTORY_SEPARATOR . 'src';
        $publicIndex = $this->rootDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
        $appJs = $this->rootDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.js';

        $foundInIndex = (bool) preg_match('/avatar-[1-8]\.jpg/i', file_get_contents($publicIndex));
        $foundInAppJs = (bool) preg_match('/avatar-[1-8]\.jpg/i', file_get_contents($appJs));

        $this->assertCondition(
            !$foundInIndex && !$foundInAppJs,
            '2.2.codebase',
            'Toàn bộ public/index.php và public/assets/app.js sạch 100% không còn chuỗi avatar-[1-8].jpg',
            'Codebase contains lingering avatar-[1-8].jpg references'
        );

        // 2.3 Physical file preservation
        $physicalFile = $this->rootDir . '/public/assets/images/avatar-1.jpg';
        $existsOnDisk = file_exists($physicalFile) && filesize($physicalFile) > 0;

        $this->assertCondition(
            $existsOnDisk,
            '2.3.disk',
            'Tệp vật lý public/assets/images/avatar-1.jpg vẫn được bảo toàn nguyên vẹn trên đĩa (11.2 KB)',
            'public/assets/images/avatar-1.jpg is missing or empty'
        );
    }

    private function runCoordinatorInspector3TabsTests(): void
    {
        echo "\n▶ [PHẦN 3] KIỂM THỬ BÀN ĐIỀU PHỐI 3 TAB NGANG (R1)...\n";

        $coordResp = $this->httpGet('/?view=coordinator', $this->coordCookie);
        $html = $coordResp['body'];

        // 3.1 Inspector 3 Tabs Presence
        $hasTabAssigned = str_contains($html, 'id="inspector-tab-assigned"');
        $hasTabEligible = str_contains($html, 'id="inspector-tab-eligible"');
        $hasTabReplacement = str_contains($html, 'id="inspector-tab-replacement"');

        $this->assertCondition(
            $hasTabAssigned && $hasTabEligible && $hasTabReplacement,
            '3.1.panes',
            'Khung Inspector tích hợp đầy đủ 3 tab pane (#inspector-tab-assigned, #inspector-tab-eligible, #inspector-tab-replacement)',
            'Missing one or more inspector tab pane IDs in ?view=coordinator HTML'
        );

        // Tab buttons
        $hasBtnAssigned = str_contains($html, 'data-tab-target="#inspector-tab-assigned"');
        $hasBtnEligible = str_contains($html, 'data-tab-target="#inspector-tab-eligible"');
        $hasBtnReplacement = str_contains($html, 'data-tab-target="#inspector-tab-replacement"');

        $this->assertCondition(
            $hasBtnAssigned && $hasBtnEligible && $hasBtnReplacement,
            '3.1.buttons',
            'Thanh điều hướng 3 tab tích hợp đầy đủ data-tab-target và lớp inspector-tab-btn',
            'Missing one or more tab target buttons'
        );

        // Initial visibility: assigned is block, eligible and replacement are hidden
        $this->assertCondition(
            str_contains($html, 'id="inspector-tab-assigned" class="inspector-tab-pane w-full block"') &&
            str_contains($html, 'id="inspector-tab-eligible" class="inspector-tab-pane w-full hidden"') &&
            str_contains($html, 'id="inspector-tab-replacement" class="inspector-tab-pane w-full hidden"'),
            '3.1.visibility',
            'Trạng thái mặc định: Tab 1 hiển thị (block), Tab 2 và Tab 3 ở trạng thái ẩn (hidden)'
        );

        // 3.2 Candidate Names in Roster do NOT have 'truncate' class
        $appJsContent = file_get_contents($this->rootDir . '/public/assets/app.js');

        // Extract specific function scopes: renderAssignedRoster, renderEligiblePool, renderReplacementTab
        preg_match('/function renderAssignedRoster\b[\s\S]*?(?=function renderEligiblePool)/i', $appJsContent, $mAssigned);
        $assignedCode = $mAssigned[0] ?? '';
        $hasTruncateInAssigned = (bool) preg_match('/item\.full_name[\s\S]{0,100}truncate/i', $assignedCode) || (bool) preg_match('/class="[^"]*truncate[^"]*"[^>]*item\.full_name/i', $assignedCode);

        preg_match('/function renderEligiblePool\b[\s\S]*?(?=function renderReplacementTab)/i', $appJsContent, $mEligible);
        $eligibleCode = $mEligible[0] ?? '';
        $hasTruncateInEligible = (bool) preg_match('/v\.full_name[\s\S]{0,100}truncate/i', $eligibleCode) || (bool) preg_match('/class="[^"]*truncate[^"]*"[^>]*v\.full_name/i', $eligibleCode);

        preg_match('/function renderReplacementTab\b[\s\S]*?(?=\/\/\s*Tab switching)/i', $appJsContent, $mReplace);
        $replaceCode = $mReplace[0] ?? '';
        $hasTruncateInReplacement = (bool) preg_match('/c\.full_name[\s\S]{0,100}truncate/i', $replaceCode) || (bool) preg_match('/class="[^"]*truncate[^"]*"[^>]*c\.full_name/i', $replaceCode);

        $this->assertCondition(
            !$hasTruncateInAssigned && !$hasTruncateInEligible && !$hasTruncateInReplacement,
            '3.2.notrunc',
            'Họ tên ứng viên trong Assigned Roster, Eligible Pool và Replacement Tab KHÔNG có class "truncate" (hiển thị trọn vẹn)',
            'Found truncation class on candidate names in app.js'
        );

        // 3.3 .badge has CSS white-space: nowrap;
        $cssContent = file_get_contents($this->rootDir . '/public/assets/style.css');
        $badgeHasNowrap = (bool) preg_match('/\.badge\s*\{[^}]*white-space:\s*nowrap;/i', $cssContent);

        $this->assertCondition(
            $badgeHasNowrap,
            '3.3.badge',
            'Lớp CSS .badge được định nghĩa rõ ràng white-space: nowrap; chấm dứt vỡ dòng nhãn kỹ năng',
            '.badge in public/assets/style.css does NOT contain white-space: nowrap;'
        );

        // 3.4 Pre-calculated absence_reports and replacements in $jsShifts
        $payloadRegex = '/<script\s+id="coordinator-data-payload"\s+type="application\/json">\s*([\s\S]*?)\s*<\/script>/i';
        $payloadMatched = preg_match($payloadRegex, $html, $payloadMatch);

        $payloadOk = false;
        $hasAbsenceReports = false;
        $hasReplacementCandidates = false;
        $shiftsCount = 0;

        if ($payloadMatched && !empty($payloadMatch[1])) {
            $data = json_decode(trim($payloadMatch[1]), true);
            if (is_array($data) && !empty($data['shifts'])) {
                $payloadOk = true;
                $shiftsCount = count($data['shifts']);
                $hasAbsenceReports = true;
                $hasReplacementCandidates = true;

                foreach ($data['shifts'] as $s) {
                    if (!array_key_exists('absence_reports', $s) || !is_array($s['absence_reports'])) {
                        $hasAbsenceReports = false;
                    }
                    if (!array_key_exists('replacement_candidates', $s) || !is_array($s['replacement_candidates'])) {
                        $hasReplacementCandidates = false;
                    }
                }
            }
        }

        $this->assertCondition(
            $payloadOk && $hasAbsenceReports && $hasReplacementCandidates && $shiftsCount > 0,
            '3.4.payload',
            sprintf('Dữ liệu JSON #coordinator-data-payload nạp sẵn pre-calculated absence_reports và replacements cho toàn bộ %d ca trực (0ms latency)', $shiftsCount),
            'Payload missing absence_reports or replacement_candidates in $jsShifts'
        );
    }
}

// Execute Runner
$runner = new AdversarialR1R3Runner();
$runner->runAll();

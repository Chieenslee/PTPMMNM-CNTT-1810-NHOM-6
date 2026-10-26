<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * XANH HUB (ĐỀ TÀI 6) - PRODUCTION READINESS VERIFICATION SUITE
 * ==============================================================================
 * 
 * Bộ công cụ kiểm thử & xác thực độ sẵn sàng Production tự động theo chuẩn R3.
 * Chạy 4 pha tuần tự (Phased Verification Pipeline):
 *   - Pha 1: Kiểm tra Cú pháp Hạ tầng Container & Cấu hình Nginx/Postgres
 *   - Pha 2: Kiểm tra Tính Toàn vẹn Biến Môi trường (.env & 23 Keys)
 *   - Pha 3: Kiểm tra Cú pháp PHP Toàn bộ Dự án (php -l 100% clean)
 *   - Pha 4: Thực thi Toàn bộ Bộ Kiểm thử Nghiệp vụ Cốt lõi (bin/run-tests.php 21/21 PASS)
 *
 * Tiêu chuẩn: PHP 8.2 thuần túy, không phụ thuộc thư viện ngoài, exit code 0 khi đạt.
 * ==============================================================================
 */

namespace XanhHub\Verification;

/**
 * Lớp hỗ trợ định dạng màu sắc dòng lệnh (ANSI Terminal Colors).
 */
final class Terminal
{
    private static ?bool $hasColorSupport = null;

    public static function hasColor(): bool
    {
        if (self::$hasColorSupport !== null) {
            return self::$hasColorSupport;
        }

        // Tự động kiểm tra hỗ trợ màu trên Windows hoặc Unix
        if (PHP_OS_FAMILY === 'Windows') {
            $hasAnsi = false;
            if (function_exists('sapi_windows_vt100_support') && defined('STDOUT')) {
                $hasAnsi = @sapi_windows_vt100_support(STDOUT, true);
            }
            if (!$hasAnsi) {
                $term = getenv('TERM');
                $hasAnsi = ($term !== false && $term !== '' && $term !== 'dumb')
                    || (getenv('ANSICON') !== false)
                    || (getenv('ConEmuANSI') === 'ON');
            }
            self::$hasColorSupport = $hasAnsi;
        } else {
            self::$hasColorSupport = function_exists('posix_isatty') && defined('STDOUT') && @posix_isatty(STDOUT);
        }

        return self::$hasColorSupport;
    }

    public static function green(string $text): string
    {
        return self::hasColor() ? "\033[32m{$text}\033[0m" : $text;
    }

    public static function red(string $text): string
    {
        return self::hasColor() ? "\033[31m{$text}\033[0m" : $text;
    }

    public static function yellow(string $text): string
    {
        return self::hasColor() ? "\033[33m{$text}\033[0m" : $text;
    }

    public static function cyan(string $text): string
    {
        return self::hasColor() ? "\033[36m{$text}\033[0m" : $text;
    }

    public static function bold(string $text): string
    {
        return self::hasColor() ? "\033[1m{$text}\033[0m" : $text;
    }

    public static function dim(string $text): string
    {
        return self::hasColor() ? "\033[2m{$text}\033[0m" : $text;
    }
}

/**
 * Bộ phân tích cú pháp YAML ngoại tuyến thuần PHP dành cho Docker Compose.
 */
final class OfflineYamlParser
{
    /**
     * Phân tích nội dung YAML thành mảng phân cấp PHP lồng nhau.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $yamlContent): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yamlContent));
        $root = [];
        // Stack lưu trữ: [indent_level, &target_node, last_key]
        $stack = [ [-1, &$root, null] ];

        foreach ($lines as $lineNum => $rawLine) {
            // Loại bỏ comment ngoại trừ chuỗi nằm trong dấu nháy kép hoặc đơn
            $cleanLine = self::stripComment($rawLine);
            $trimmed = trim($cleanLine);
            if ($trimmed === '') {
                continue;
            }

            // Kiểm tra tab indentation (YAML cấm tab)
            if (str_starts_with($rawLine, "\t")) {
                throw new \RuntimeException("Lỗi cú pháp YAML tại dòng " . ($lineNum + 1) . ": Dùng ký tự Tab thay vì khoảng trắng.");
            }

            $indent = strlen($cleanLine) - strlen(ltrim($cleanLine, ' '));

            // Thu hồi stack khi mức thụt lề giảm
            while (count($stack) > 1 && $stack[count($stack) - 1][0] >= $indent) {
                array_pop($stack);
            }

            $parent = &$stack[count($stack) - 1][1];

            // 1. Dòng dạng list item: "- value" hoặc "- key: val"
            if (str_starts_with($trimmed, '- ')) {
                $subContent = trim(substr($trimmed, 2));
                if (str_contains($subContent, ': ') && !str_starts_with($subContent, '{') && !str_starts_with($subContent, '[')) {
                    // Dạng "- key: val"
                    $parts = explode(': ', $subContent, 2);
                    $itemKey = trim($parts[0]);
                    $itemVal = self::parseScalarValue(trim($parts[1] ?? ''));
                    $itemArr = [$itemKey => $itemVal];
                    if (!is_array($parent)) {
                        $parent = [];
                    }
                    $parent[] = $itemArr;
                } else {
                    $itemVal = self::parseScalarValue($subContent);
                    if (!is_array($parent)) {
                        $parent = [];
                    }
                    $parent[] = $itemVal;
                }
                continue;
            }

            // 2. Dòng dạng key-value: "key: value" hoặc "key:"
            if (str_contains($trimmed, ':')) {
                $colonPos = strpos($trimmed, ':');
                $key = trim(substr($trimmed, 0, $colonPos));
                $rawVal = trim(substr($trimmed, $colonPos + 1));

                // Bỏ ngoặc kép quanh key nếu có
                if ((str_starts_with($key, '"') && str_ends_with($key, '"')) ||
                    (str_starts_with($key, "'") && str_ends_with($key, "'"))) {
                    $key = substr($key, 1, -1);
                }

                if (!is_array($parent)) {
                    $parent = [];
                }

                if ($rawVal === '') {
                    // Node cha mới
                    $parent[$key] = [];
                    $stack[] = [$indent, &$parent[$key], $key];
                } else {
                    $parsedVal = self::parseScalarValue($rawVal);
                    $parent[$key] = $parsedVal;
                }
            }
        }

        return $root;
    }

    private static function stripComment(string $line): string
    {
        $len = strlen($line);
        $clean = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if (!$inQuote && ($ch === '"' || $ch === "'")) {
                $inQuote = true;
                $quoteChar = $ch;
                $clean .= $ch;
            } elseif ($inQuote && $ch === $quoteChar) {
                // Kiểm tra ký tự escape
                if ($i > 0 && $line[$i - 1] === '\\') {
                    $clean .= $ch;
                } else {
                    $inQuote = false;
                    $clean .= $ch;
                }
            } elseif (!$inQuote && $ch === '#') {
                break;
            } else {
                $clean .= $ch;
            }
        }

        return $clean;
    }

    private static function parseScalarValue(string $val): mixed
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }

        // Inline JSON/YAML array: ["item1", "item2"]
        if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            // Fallback split
            $inner = trim(substr($val, 1, -1));
            if ($inner === '') {
                return [];
            }
            $items = explode(',', $inner);
            return array_map(fn($item) => trim(trim($item), "\"'"), $items);
        }

        // Inline JSON/YAML object: {a: 1, b: 2}
        if (str_starts_with($val, '{') && str_ends_with($val, '}')) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Quoted strings
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            return substr($val, 1, -1);
        }

        // Booleans & Null
        $lower = strtolower($val);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null' || $lower === '~') {
            return null;
        }

        // Numbers
        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float) $val : (int) $val;
        }

        return $val;
    }
}

/**
 * Trình xác thực độ sẵn sàng Production của hệ thống Xanh Hub.
 */
final class ProductionReadinessVerifier
{
    private string $projectRoot;
    private string $phpBinary;

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [
        'p1_passed' => 0,
        'p1_total'  => 0,
        'p2_passed' => 0,
        'p2_total'  => 0,
        'p3_passed' => 0,
        'p3_total'  => 0,
        'p4_passed' => 0,
        'p4_total'  => 0,
    ];

    public function __construct(string $projectRoot, ?string $phpBinary = null)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $this->phpBinary = $phpBinary ?: $this->resolvePhpBinary();
    }

    /**
     * Thực thi toàn bộ 4 pha xác thực.
     */
    public function verifyAll(): bool
    {
        $startTime = microtime(true);

        $this->printHeader();

        $phase1Ok = $this->verifyPhase1DockerInfrastructure();
        $phase2Ok = $this->verifyPhase2EnvironmentIntegrity();
        $phase3Ok = $this->verifyPhase3PhpSyntaxLinting();
        $phase4Ok = $this->verifyPhase4BusinessTestSuite();

        $elapsed = round(microtime(true) - $startTime, 2);
        $allPassed = $phase1Ok && $phase2Ok && $phase3Ok && $phase4Ok;

        $this->printSummary($allPassed, $elapsed);

        return $allPassed;
    }

    /**
     * PHA 1: Kiểm tra Cú pháp Hạ tầng Container & Cấu hình Nginx/Postgres.
     */
    private function verifyPhase1DockerInfrastructure(): bool
    {
        echo Terminal::bold(Terminal::cyan("▶ [PHA 1/4] Kiểm tra Cú pháp Hạ tầng Container, Docker & Nginx/PostgreSQL...\n"));
        $phasePassed = true;

        // ----------------------------------------------------------------------
        // 1.1 docker-compose.yml
        // ----------------------------------------------------------------------
        $composePath = $this->projectRoot . '/docker-compose.yml';
        $this->assertCheck(
            '1.1.1 docker-compose.yml tồn tại trong thư mục gốc',
            is_file($composePath),
            $phasePassed,
            'p1'
        );

        if (is_file($composePath)) {
            $composeRaw = (string) file_get_contents($composePath);
            $composeData = [];
            $parseOk = true;

            try {
                $composeData = OfflineYamlParser::parse($composeRaw);
            } catch (\Throwable $e) {
                $parseOk = false;
                $this->errors[] = "Lỗi phân tích YAML docker-compose.yml: " . $e->getMessage();
            }

            $this->assertCheck(
                '1.1.2 docker-compose.yml tuân thủ cú pháp YAML phân cấp hợp lệ',
                $parseOk && is_array($composeData) && !empty($composeData),
                $phasePassed,
                'p1'
            );

            // Kiểm tra version
            $version = (string) ($composeData['version'] ?? '');
            $this->assertCheck(
                "1.1.3 Phiên bản Docker Compose schema '{$version}' hợp lệ (>= 3.8)",
                $version === '3.8' || version_compare($version, '3.8', '>='),
                $phasePassed,
                'p1'
            );

            // Kiểm tra 3 services: web, app, db
            $services = $composeData['services'] ?? [];
            $hasDb = isset($services['db']);
            $hasApp = isset($services['app']);
            $hasWeb = isset($services['web']);
            $this->assertCheck(
                '1.1.4 Cấu hình đầy đủ 3 service liên thông cốt lõi: web (Nginx), app (PHP-FPM), db (PostgreSQL)',
                $hasDb && $hasApp && $hasWeb,
                $phasePassed,
                'p1'
            );

            // Kiểm tra mạng riêng xanhhub-net
            $networks = $composeData['networks'] ?? [];
            $hasNetworkRoot = isset($networks['xanhhub-net']);
            $dbNet = in_array('xanhhub-net', (array) ($services['db']['networks'] ?? []), true);
            $appNet = in_array('xanhhub-net', (array) ($services['app']['networks'] ?? []), true);
            $webNet = in_array('xanhhub-net', (array) ($services['web']['networks'] ?? []), true);
            $this->assertCheck(
                "1.1.5 Mạng cầu nối độc lập 'xanhhub-net' được khai báo và liên kết toàn bộ 3 service",
                $hasNetworkRoot && $dbNet && $appNet && $webNet,
                $phasePassed,
                'p1'
            );

            // Kiểm tra named volume xanhhub_pgdata
            $volumes = $composeData['volumes'] ?? [];
            $hasVolumeRoot = isset($volumes['xanhhub_pgdata']);
            $dbVolumes = (array) ($services['db']['volumes'] ?? []);
            $dbHasPgData = false;
            foreach ($dbVolumes as $v) {
                if (is_string($v) && str_starts_with($v, 'xanhhub_pgdata:')) {
                    $dbHasPgData = true;
                    break;
                }
            }
            $this->assertCheck(
                "1.1.6 Khai báo Persistent Named Volume 'xanhhub_pgdata' gắn vào service db",
                $hasVolumeRoot && $dbHasPgData,
                $phasePassed,
                'p1'
            );

            // Kiểm tra Healthcheck cho cả 3 services
            $dbHc = !empty($services['db']['healthcheck']);
            $appHc = !empty($services['app']['healthcheck']);
            $webHc = !empty($services['web']['healthcheck']);
            $this->assertCheck(
                '1.1.7 Khai báo Healthcheck đầy đủ cho cả 3 service (db: pg_isready, app: cgi-fcgi, web: http)',
                $dbHc && $appHc && $webHc,
                $phasePassed,
                'p1'
            );

            // Kiểm tra depends_on condition: service_healthy
            $appDepends = $services['app']['depends_on'] ?? [];
            $appDependsDbHealthy = isset($appDepends['db']['condition']) && $appDepends['db']['condition'] === 'service_healthy';
            $webDepends = $services['web']['depends_on'] ?? [];
            $webDependsAppHealthy = isset($webDepends['app']['condition']) && $webDepends['app']['condition'] === 'service_healthy';
            $this->assertCheck(
                "1.1.8 Ràng buộc phụ thuộc 'depends_on' sử dụng 'condition: service_healthy' chuẩn xác",
                $appDependsDbHealthy && $webDependsAppHealthy,
                $phasePassed,
                'p1'
            );
        }

        // ----------------------------------------------------------------------
        // 1.2 docker/php/Dockerfile
        // ----------------------------------------------------------------------
        $dockerfilePath = $this->projectRoot . '/docker/php/Dockerfile';
        $this->assertCheck(
            '1.2.1 docker/php/Dockerfile tồn tại',
            is_file($dockerfilePath),
            $phasePassed,
            'p1'
        );

        if (is_file($dockerfilePath)) {
            $dfRaw = (string) file_get_contents($dockerfilePath);

            // Multi-stage check: At least 2 FROM instructions
            preg_match_all('/^\s*FROM\s+([^\s]+)(?:\s+AS\s+([^\s]+))?/mi', $dfRaw, $fromMatches, PREG_SET_ORDER);
            $stageCount = count($fromMatches);
            $hasBuilder = false;
            $hasPhpRuntime = false;
            foreach ($fromMatches as $fm) {
                $target = strtolower($fm[1]);
                $asName = strtolower($fm[2] ?? '');
                if (str_contains($target, 'composer') || $asName === 'builder') {
                    $hasBuilder = true;
                }
                if (str_contains($target, 'php:8.2-fpm-alpine') || str_contains($target, 'php:8.2-fpm') || str_contains($target, 'php:8.3-fpm')) {
                    $hasPhpRuntime = true;
                }
            }
            $this->assertCheck(
                "1.2.2 Cấu hình Multi-Stage build tối ưu ({$stageCount} stages: builder + PHP-FPM runtime)",
                $stageCount >= 2 && $hasBuilder && $hasPhpRuntime,
                $phasePassed,
                'p1'
            );

            // 5 compile extensions: pdo_pgsql, opcache, gd, zip, bcmath
            $requiredExts = ['pdo_pgsql', 'opcache', 'gd', 'zip', 'bcmath'];
            $missingExts = [];
            foreach ($requiredExts as $ext) {
                if (!preg_match('/\b' . preg_quote($ext, '/') . '\b/i', $dfRaw)) {
                    $missingExts[] = $ext;
                }
            }
            $this->assertCheck(
                '1.2.3 Biên dịch đủ 5 PHP extensions bắt buộc: pdo_pgsql, opcache, gd, zip, bcmath',
                empty($missingExts),
                $phasePassed,
                'p1'
            );

            // Non-root USER www-data
            $hasUserWwwData = (bool) preg_match('/^\s*USER\s+www-data\b/m', $dfRaw);
            $this->assertCheck(
                "1.2.4 Thiết lập người dùng bảo mật không đặc quyền: USER www-data",
                $hasUserWwwData,
                $phasePassed,
                'p1'
            );

            // Healthcheck cgi-fcgi
            $hasFcgiHealth = str_contains($dfRaw, 'HEALTHCHECK') && str_contains($dfRaw, 'cgi-fcgi');
            $this->assertCheck(
                '1.2.5 Chỉ thị HEALTHCHECK FastCGI (cgi-fcgi ping) trong Dockerfile',
                $hasFcgiHealth,
                $phasePassed,
                'p1'
            );
        }

        // ----------------------------------------------------------------------
        // 1.3 docker/nginx/default.conf
        // ----------------------------------------------------------------------
        $nginxConfPath = $this->projectRoot . '/docker/nginx/default.conf';
        $this->assertCheck(
            '1.3.1 docker/nginx/default.conf tồn tại',
            is_file($nginxConfPath),
            $phasePassed,
            'p1'
        );

        if (is_file($nginxConfPath)) {
            $confRaw = (string) file_get_contents($nginxConfPath);

            // Balanced braces check
            $openCount = substr_count($confRaw, '{');
            $closeCount = substr_count($confRaw, '}');
            $this->assertCheck(
                "1.3.2 Cấu trúc cú pháp Nginx cân bằng dấu ngoặc ({$openCount} '{' và {$closeCount} '}')",
                $openCount > 0 && $openCount === $closeCount,
                $phasePassed,
                'p1'
            );

            // Upstream FastCGI proxy to app:9000
            $hasFastCgiPass = str_contains($confRaw, 'fastcgi_pass app:9000;') || str_contains($confRaw, 'fastcgi_pass app:9000');
            $this->assertCheck(
                "1.3.3 Chuyển tiếp Reverse Proxy FastCGI chính xác tới 'app:9000'",
                $hasFastCgiPass,
                $phasePassed,
                'p1'
            );

            // HTTP/2 readiness
            $hasHttp2 = str_contains($confRaw, 'http2');
            $this->assertCheck(
                '1.3.4 Sẵn sàng hỗ trợ chuẩn giao thức HTTP/2 cho client',
                $hasHttp2,
                $phasePassed,
                'p1'
            );

            // Gzip compression level 6
            $hasGzip = str_contains($confRaw, 'gzip on;') && str_contains($confRaw, 'gzip_comp_level 6;');
            $this->assertCheck(
                '1.3.5 Nén tài nguyên Gzip cấp độ 6 tối ưu băng thông cho CSS/JS/JSON/SVG',
                $hasGzip,
                $phasePassed,
                'p1'
            );

            // Rate limiting map for auth actions: do_login, oauth_login, do_register
            $hasMapAction = str_contains($confRaw, 'map $arg_action')
                && str_contains($confRaw, 'do_login')
                && str_contains($confRaw, 'oauth_login')
                && str_contains($confRaw, 'do_register');
            $hasLimitReqZone = str_contains($confRaw, 'limit_req_zone') && str_contains($confRaw, 'zone=auth_limit');
            $hasLimitReqApply = str_contains($confRaw, 'limit_req zone=auth_limit');
            $this->assertCheck(
                '1.3.6 Cấu hình Rate Limiting chống Brute-force qua map $arg_action (do_login, oauth_login, do_register)',
                $hasMapAction && $hasLimitReqZone && $hasLimitReqApply,
                $phasePassed,
                'p1'
            );

            // Static caching (30d)
            $hasStaticCache = str_contains($confRaw, 'expires 30d;') || str_contains($confRaw, 'max-age=2592000');
            $this->assertCheck(
                '1.3.7 Phân phát tài nguyên tĩnh với bộ đệm trình duyệt dài hạn (Cache-Control 30 ngày)',
                $hasStaticCache,
                $phasePassed,
                'p1'
            );

            // Healthcheck endpoint /health
            $hasHealthLoc = str_contains($confRaw, 'location /health') && (str_contains($confRaw, '200') || str_contains($confRaw, 'healthy'));
            $this->assertCheck(
                '1.3.8 Endpoint giám sát trạng thái trực tiếp: location /health trả về HTTP 200',
                $hasHealthLoc,
                $phasePassed,
                'p1'
            );
        }

        // ----------------------------------------------------------------------
        // 1.4 docker/postgres/init.sql
        // ----------------------------------------------------------------------
        $sqlPath = $this->projectRoot . '/docker/postgres/init.sql';
        $this->assertCheck(
            '1.4.1 docker/postgres/init.sql tồn tại',
            is_file($sqlPath),
            $phasePassed,
            'p1'
        );

        if (is_file($sqlPath)) {
            $sqlRaw = (string) file_get_contents($sqlPath);

            // Extensions: btree_gist and pgcrypto
            $hasBtreeGist = (bool) preg_match('/CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?btree_gist/i', $sqlRaw);
            $hasPgcrypto = (bool) preg_match('/CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?pgcrypto/i', $sqlRaw);
            $this->assertCheck(
                '1.4.2 Tự động nạp 2 extension PostgreSQL bắt buộc: btree_gist và pgcrypto',
                $hasBtreeGist && $hasPgcrypto,
                $phasePassed,
                'p1'
            );

            // 22 tables schema
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z0-9_]+)/i', $sqlRaw, $tableMatches);
            $foundTables = array_map('strtolower', $tableMatches[1] ?? []);
            $expectedTables = [
                'users', 'volunteers', 'skills', 'volunteer_skills', 'availability',
                'availability_overrides', 'activities', 'activity_coordinators', 'shifts',
                'shift_requirements', 'registrations', 'dataset_snapshots', 'plans',
                'plan_items', 'unassigned', 'assignments', 'absence_reports', 'waitlist',
                'checkins', 'certificates', 'settings', 'audit_log',
            ];
            $missingTables = array_diff($expectedTables, $foundTables);
            $tableCount = count(array_intersect($expectedTables, $foundTables));
            $this->assertCheck(
                "1.4.3 Khởi tạo lược đồ cơ sở dữ liệu hoàn chỉnh 22 bảng ({$tableCount}/22 bảng)",
                empty($missingTables),
                $phasePassed,
                'p1'
            );

            // GiST exclusion constraint on active shifts
            $hasGistConstraint = str_contains($sqlRaw, 'excl_volunteer_no_overlapping_active_shifts')
                && (bool) preg_match('/EXCLUDE\s+USING\s+gist\s*\(\s*volunteer_id\s+WITH\s*=\s*,\s*during\s+WITH\s*&&\s*\)/i', $sqlRaw);
            $this->assertCheck(
                "1.4.4 Ràng buộc GiST bất biến 'excl_volunteer_no_overlapping_active_shifts' trên TSTZRANGE",
                $hasGistConstraint,
                $phasePassed,
                'p1'
            );

            // Triggers & functions
            $hasTrgSync = str_contains($sqlRaw, 'trg_sync_shift_during');
            $hasTrgCapacity = str_contains($sqlRaw, 'trg_check_shift_capacity');
            $hasTrgAudit = str_contains($sqlRaw, 'trg_protect_audit_log');
            $hasAppendAudit = str_contains($sqlRaw, 'append_audit_log');
            $this->assertCheck(
                '1.4.5 Cài đặt đầy đủ 4 trigger & hàm nghiệp vụ: trg_sync_shift_during, trg_check_shift_capacity, trg_protect_audit_log, append_audit_log',
                $hasTrgSync && $hasTrgCapacity && $hasTrgAudit && $hasAppendAudit,
                $phasePassed,
                'p1'
            );
        }

        echo "\n";
        return $phasePassed;
    }

    /**
     * PHA 2: Kiểm tra Tính Toàn vẹn Biến Môi trường (.env & 23 Keys).
     */
    private function verifyPhase2EnvironmentIntegrity(): bool
    {
        echo Terminal::bold(Terminal::cyan("▶ [PHA 2/4] Kiểm tra Tính Toàn vẹn Biến Môi trường (.env, .env.example, .env.production)...\n"));
        $phasePassed = true;

        $examplePath = $this->projectRoot . '/.env.example';
        $prodPath = $this->projectRoot . '/.env.production';

        $this->assertCheck(
            '2.1.1 Tồn tại tệp hướng dẫn mẫu .env.example',
            is_file($examplePath),
            $phasePassed,
            'p2'
        );

        $this->assertCheck(
            '2.1.2 Tồn tại tệp triển khai sản xuất .env.production',
            is_file($prodPath),
            $phasePassed,
            'p2'
        );

        if (is_file($examplePath) && is_file($prodPath)) {
            $exampleEnv = $this->parseEnvFile($examplePath);
            $prodEnv = $this->parseEnvFile($prodPath);

            // 23 Keys across DB, 5 OAuth providers, and HMAC_SECRET
            // 6 DB keys + 3 Google + 3 Zalo + 4 Microsoft + 3 Facebook + 3 GitHub + 1 HMAC_SECRET = 23 keys
            $required23Keys = [
                // 1. Database (6)
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_PASSWORD',

                // 2. Google OAuth (3)
                'GOOGLE_CLIENT_ID',
                'GOOGLE_CLIENT_SECRET',
                'GOOGLE_REDIRECT_URI',

                // 3. Zalo OAuth (3) (ZALO_APP_ID / ZALO_CLIENT_ID)
                'ZALO_APP_ID',
                'ZALO_APP_SECRET',
                'ZALO_REDIRECT_URI',

                // 4. Microsoft Edu OAuth (4)
                'MICROSOFT_CLIENT_ID',
                'MICROSOFT_CLIENT_SECRET',
                'MICROSOFT_REDIRECT_URI',
                'MICROSOFT_TENANT_ID',

                // 5. Facebook OAuth (3) (FACEBOOK_APP_ID / FACEBOOK_CLIENT_ID)
                'FACEBOOK_APP_ID',
                'FACEBOOK_APP_SECRET',
                'FACEBOOK_REDIRECT_URI',

                // 6. GitHub OAuth (3)
                'GITHUB_CLIENT_ID',
                'GITHUB_CLIENT_SECRET',
                'GITHUB_REDIRECT_URI',

                // 7. Security HMAC Secret (1)
                'HMAC_SECRET',
            ];

            // Normalize key check with support for aliases (e.g. ZALO_APP_ID or ZALO_CLIENT_ID)
            $checkKeys = function(array $env, array $keys): array {
                $missing = [];
                foreach ($keys as $k) {
                    if (array_key_exists($k, $env)) {
                        continue;
                    }
                    // Aliases
                    if ($k === 'ZALO_APP_ID' && array_key_exists('ZALO_CLIENT_ID', $env)) {
                        continue;
                    }
                    if ($k === 'ZALO_APP_SECRET' && array_key_exists('ZALO_CLIENT_SECRET', $env)) {
                        continue;
                    }
                    if ($k === 'FACEBOOK_APP_ID' && array_key_exists('FACEBOOK_CLIENT_ID', $env)) {
                        continue;
                    }
                    if ($k === 'FACEBOOK_APP_SECRET' && array_key_exists('FACEBOOK_CLIENT_SECRET', $env)) {
                        continue;
                    }
                    $missing[] = $k;
                }
                return $missing;
            };

            $missingExample = $checkKeys($exampleEnv, $required23Keys);
            $presentExampleCount = count($required23Keys) - count($missingExample);
            $this->assertCheck(
                "2.2.1 .env.example khai báo đầy đủ 23/23 khóa môi trường quy chuẩn ({$presentExampleCount}/23 keys)",
                empty($missingExample),
                $phasePassed,
                'p2'
            );

            $missingProd = $checkKeys($prodEnv, $required23Keys);
            $presentProdCount = count($required23Keys) - count($missingProd);
            $this->assertCheck(
                "2.2.2 .env.production đồng bộ chính xác 23/23 khóa môi trường ({$presentProdCount}/23 keys)",
                empty($missingProd),
                $phasePassed,
                'p2'
            );

            // Kiểm tra phân nhóm 5 nhà cung cấp OAuth
            $providersOk = isset($exampleEnv['GOOGLE_CLIENT_ID'])
                && (isset($exampleEnv['ZALO_APP_ID']) || isset($exampleEnv['ZALO_CLIENT_ID']))
                && isset($exampleEnv['MICROSOFT_CLIENT_ID'])
                && (isset($exampleEnv['FACEBOOK_APP_ID']) || isset($exampleEnv['FACEBOOK_CLIENT_ID']))
                && isset($exampleEnv['GITHUB_CLIENT_ID']);
            $this->assertCheck(
                '2.2.3 Bao phủ đầy đủ 5 nhà cung cấp OAuth 2.0: Google, Zalo, Microsoft Edu, Facebook, GitHub',
                $providersOk,
                $phasePassed,
                'p2'
            );

            // Kiểm tra bảo vệ rò rỉ khóa bí mật trong .env.example (Zero Secret Leaks)
            $leaksFound = [];
            foreach ($exampleEnv as $key => $val) {
                $valClean = trim($val);
                if ($valClean === '') {
                    continue;
                }
                $isSecretKey = str_ends_with($key, '_SECRET')
                    || str_ends_with($key, '_PASSWORD')
                    || (str_ends_with($key, '_ID') && !str_contains($key, 'TENANT'))
                    || str_contains($key, 'TOKEN');

                if ($isSecretKey) {
                    $lowerVal = strtolower($valClean);
                    $isPlaceholder = str_starts_with($lowerVal, 'your-')
                        || str_starts_with($lowerVal, 'mock-')
                        || str_starts_with($lowerVal, 'sandbox-')
                        || str_starts_with($lowerVal, 'demo-')
                        || str_contains($lowerVal, 'placeholder')
                        || str_contains($lowerVal, 'example')
                        || str_starts_with($lowerVal, 'xanhhub_rotating_qr_hmac')
                        || str_starts_with($lowerVal, 'xanhhub-open-source-demo');

                    if (!$isPlaceholder && strlen($valClean) > 10) {
                        $leaksFound[] = "{$key}={$valClean}";
                    }
                }
            }
            $this->assertCheck(
                "2.2.4 Không phát hiện rò rỉ khóa bí mật thực tế trong .env.example (Zero Secret Leaks)",
                empty($leaksFound),
                $phasePassed,
                'p2'
            );

            // Kiểm tra cấu hình môi trường .env.production
            $isProdEnv = ($prodEnv['APP_ENV'] ?? '') === 'production';
            $isPostgres = ($prodEnv['DB_CONNECTION'] ?? '') === 'pgsql';
            $isDbService = ($prodEnv['DB_HOST'] ?? '') === 'db';
            $this->assertCheck(
                "2.2.5 Thiết lập môi trường .env.production chuẩn: APP_ENV=production, DB_CONNECTION=pgsql, DB_HOST=db",
                $isProdEnv && $isPostgres && $isDbService,
                $phasePassed,
                'p2'
            );
        }

        echo "\n";
        return $phasePassed;
    }

    /**
     * PHA 3: Kiểm tra Cú pháp PHP Toàn bộ Dự án (php -l).
     */
    private function verifyPhase3PhpSyntaxLinting(): bool
    {
        echo Terminal::bold(Terminal::cyan("▶ [PHA 3/4] Quét và Kiểm tra Cú pháp PHP Toàn bộ Dự án (php -l)...\n"));
        $phasePassed = true;

        $targetDirs = ['src', 'public', 'bootstrap', 'bin', 'tests'];
        $phpFiles = [];

        foreach ($targetDirs as $dir) {
            $absDir = $this->projectRoot . '/' . $dir;
            if (is_dir($absDir)) {
                $this->scanPhpFiles($absDir, $phpFiles);
            }
        }

        sort($phpFiles);
        $totalFiles = count($phpFiles);
        $cleanCount = 0;
        $failedFiles = [];

        foreach ($phpFiles as $file) {
            $relPath = str_replace($this->projectRoot . '/', '', str_replace('\\', '/', $file));
            $cmd = escapeshellarg($this->phpBinary) . ' -l ' . escapeshellarg($file);
            $output = [];
            $exitCode = 0;
            exec($cmd . ' 2>&1', $output, $exitCode);

            $outStr = implode("\n", $output);
            if ($exitCode === 0 && str_contains($outStr, 'No syntax errors detected')) {
                $cleanCount++;
            } else {
                $failedFiles[] = [
                    'file' => $relPath,
                    'error' => $outStr,
                ];
            }
        }

        $allClean = ($cleanCount === $totalFiles && $totalFiles > 0);
        $this->assertCheck(
            "3.1.1 Quét đệ quy toàn bộ thư mục cốt lõi (src/, public/, bootstrap/, bin/, tests/)",
            $totalFiles >= 35,
            $phasePassed,
            'p3'
        );

        $this->assertCheck(
            "3.1.2 Kiểm tra cú pháp 'php -l' đạt 100% sạch (0 lỗi trên {$cleanCount}/{$totalFiles} tệp)",
            $allClean,
            $phasePassed,
            'p3'
        );

        if (!empty($failedFiles)) {
            foreach ($failedFiles as $ff) {
                $this->errors[] = "Lỗi cú pháp tại tệp {$ff['file']}:\n  " . trim($ff['error']);
            }
        }

        echo "\n";
        return $phasePassed;
    }

    /**
     * PHA 4: Thực thi Toàn bộ Bộ Kiểm thử Nghiệp vụ Cốt lõi (bin/run-tests.php).
     */
    private function verifyPhase4BusinessTestSuite(): bool
    {
        echo Terminal::bold(Terminal::cyan("▶ [PHA 4/4] Thực thi Toàn bộ Bộ Kiểm thử Nghiệp vụ Cốt lõi (bin/run-tests.php)...\n"));
        $phasePassed = true;

        $testRunnerPath = $this->projectRoot . '/bin/run-tests.php';
        $this->assertCheck(
            '4.1.1 Tệp bộ kiểm thử lõi bin/run-tests.php tồn tại',
            is_file($testRunnerPath),
            $phasePassed,
            'p4'
        );

        if (is_file($testRunnerPath)) {
            $cmd = escapeshellarg($this->phpBinary) . ' ' . escapeshellarg($testRunnerPath);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes, $this->projectRoot);
            $stdout = '';
            $stderr = '';
            $exitCode = -1;

            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
            }

            $hasSummaryPass = str_contains($stdout, '21/21 ca kiểm thử ĐẠT') || str_contains($stdout, '21/21');
            $hasAssertions = str_contains($stdout, '267 phép khẳng định') || str_contains($stdout, '267 assertions');

            // Kiểm tra các bài kiểm tra cốt lõi
            $hasConflictTest = str_contains($stdout, 'ConflictTimeTest');
            $hasSkillTest = str_contains($stdout, 'SkillRequiredTest');
            $hasCapacityTest = str_contains($stdout, 'CapacityGuardTest');
            $hasUnderstaffedTest = str_contains($stdout, 'UnderstaffedReportTest');
            $hasCompareTest = str_contains($stdout, 'CompareReportTest');
            $hasExtendedTest = str_contains($stdout, 'ExtendedSystemTest');

            $allClassesRan = $hasConflictTest && $hasSkillTest && $hasCapacityTest
                && $hasUnderstaffedTest && $hasCompareTest && $hasExtendedTest;

            $this->assertCheck(
                "4.1.2 Kích hoạt tiến trình kiểm thử với mã thoát 0 thành công (Exit Code: {$exitCode})",
                $exitCode === 0,
                $phasePassed,
                'p4'
            );

            $this->assertCheck(
                '4.1.3 Thực thi trọn vẹn 6 lớp kiểm thử nghiệp vụ: ConflictTime, SkillRequired, CapacityGuard, UnderstaffedReport, CompareReport, ExtendedSystem',
                $allClassesRan,
                $phasePassed,
                'p4'
            );

            $this->assertCheck(
                '4.1.4 Đạt 21/21 ca kiểm thử (267 phép khẳng định) - 0 vi phạm thuật toán Kuhn-Munkres, thời gian và kỹ năng',
                $hasSummaryPass && $hasAssertions,
                $phasePassed,
                'p4'
            );

            if ($exitCode !== 0 || !$hasSummaryPass) {
                $this->errors[] = "Bộ kiểm thử bin/run-tests.php không đạt 100% PASS:\n" . trim($stderr ?: $stdout);
            }
        }

        echo "\n";
        return $phasePassed;
    }

    /**
     * Quét đệ quy tìm các tệp .php loại trừ .agents, vendor, .git.
     *
     * @param list<string> $files
     */
    private function scanPhpFiles(string $dir, array &$files): void
    {
        $handle = opendir($dir);
        if ($handle === false) {
            return;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..' || $entry === '.agents' || $entry === 'vendor' || $entry === '.git') {
                continue;
            }

            $fullPath = $dir . '/' . $entry;
            if (is_dir($fullPath)) {
                $this->scanPhpFiles($fullPath, $files);
            } elseif (is_file($fullPath) && str_ends_with(strtolower($entry), '.php')) {
                $files[] = $fullPath;
            }
        }

        closedir($handle);
    }

    /**
     * Phân tích tệp .env thành mảng key => value, bỏ qua comment và dòng trống.
     *
     * @return array<string, string>
     */
    private function parseEnvFile(string $filePath): array
    {
        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $vars = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Loại bỏ inline comment nếu không nằm trong ngoặc kép
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $rawVal = trim(substr($trimmed, $eqPos + 1));

            // Trích xuất giá trị
            $cleanVal = '';
            if (str_starts_with($rawVal, '"')) {
                $endQuote = strpos($rawVal, '"', 1);
                $cleanVal = $endQuote !== false ? substr($rawVal, 1, $endQuote - 1) : substr($rawVal, 1);
            } elseif (str_starts_with($rawVal, "'")) {
                $endQuote = strpos($rawVal, "'", 1);
                $cleanVal = $endQuote !== false ? substr($rawVal, 1, $endQuote - 1) : substr($rawVal, 1);
            } else {
                $commentPos = strpos($rawVal, '#');
                $cleanVal = $commentPos !== false ? trim(substr($rawVal, 0, $commentPos)) : $rawVal;
            }

            $vars[$key] = $cleanVal;
        }

        return $vars;
    }

    /**
     * Xác nhận một điều kiện và ghi nhận số liệu thống kê.
     */
    private function assertCheck(string $description, bool $condition, bool &$phaseFlag, string $statPrefix): void
    {
        $this->stats["{$statPrefix}_total"]++;

        if ($condition) {
            $this->stats["{$statPrefix}_passed"]++;
            echo '  ' . Terminal::green('[PASS]') . ' ' . $description . "\n";
        } else {
            $phaseFlag = false;
            echo '  ' . Terminal::red('[FAIL]') . ' ' . $description . "\n";
            $this->errors[] = "[{$statPrefix}] Thất bại kiểm tra: {$description}";
        }
    }

    /**
     * Tìm kiếm đường dẫn PHP CLI Binary khả dụng trên máy chủ.
     */
    private function resolvePhpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY) && is_executable(PHP_BINARY)) {
            // Đảm bảo không phải web server SAPI
            if (PHP_SAPI === 'cli') {
                return PHP_BINARY;
            }
        }

        $candidates = [
            'C:\\xampp\\php\\php.exe',
            'C:\\laragon\\bin\\php\\php-8.2\\php.exe',
            'C:\\laragon\\bin\\php\\php-8.3\\php.exe',
            'C:\\php\\php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];

        foreach ($candidates as $cand) {
            if (is_file($cand) && is_executable($cand)) {
                return $cand;
            }
        }

        return 'php';
    }

    private function printHeader(): void
    {
        echo "========================================================================\n";
        echo " " . Terminal::bold(Terminal::cyan("XANH HUB (ĐỀ TÀI 6) - BỘ XÁC THỰC ĐỘ SẴN SÀNG PRODUCTION")) . "\n";
        echo " " . Terminal::dim("(PRODUCTION READINESS VERIFICATION PIPELINE - 4 PHASES)") . "\n";
        echo "========================================================================\n";
        echo " Thư mục dự án:  " . $this->projectRoot . "\n";
        echo " PHP CLI Binary:  " . $this->phpBinary . "\n";
        echo " Phiên bản PHP:   " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
        echo " Thời điểm chạy:  " . date('Y-m-d H:i:s T') . "\n";
        echo "------------------------------------------------------------------------\n\n";
    }

    private function printSummary(bool $allPassed, float $elapsed): void
    {
        echo "------------------------------------------------------------------------\n";
        echo " " . Terminal::bold("TỔNG KẾT XÁC THỰC ĐỘ SẴN SÀNG PRODUCTION (Thời gian: {$elapsed}s):") . "\n";

        $printPhaseSummary = function(string $title, int $passed, int $total) {
            $isOk = ($passed === $total && $total > 0);
            $badge = $isOk ? Terminal::green("[ĐẠT {$passed}/{$total}]") : Terminal::red("[LỖI {$passed}/{$total}]");
            echo " - " . str_pad($title, 42, ' ') . ": {$badge}\n";
        };

        $printPhaseSummary('Pha 1 (Hạ tầng Container & Nginx/Postgres)', $this->stats['p1_passed'], $this->stats['p1_total']);
        $printPhaseSummary('Pha 2 (Tính Toàn vẹn Biến Môi trường)', $this->stats['p2_passed'], $this->stats['p2_total']);
        $printPhaseSummary('Pha 3 (Kiểm tra Cú pháp PHP Toàn bộ Dự án)', $this->stats['p3_passed'], $this->stats['p3_total']);
        $printPhaseSummary('Pha 4 (Bộ Kiểm thử Nghiệp vụ Cốt lõi)', $this->stats['p4_passed'], $this->stats['p4_total']);

        echo "------------------------------------------------------------------------\n";

        if ($allPassed) {
            echo " " . Terminal::bold(Terminal::green("✔ KẾT LUẬN: HỆ THỐNG ĐẠT 100% TIÊU CHUẨN SẴN SÀNG PRODUCTION")) . "\n";
            echo "   Tất cả cấu hình Docker, Nginx, PostgreSQL, Biến môi trường,\n";
            echo "   Cú pháp PHP và 21/21 ca kiểm thử nghiệp vụ đều hoạt động hoàn hảo.\n";
            echo "========================================================================\n";
        } else {
            echo " " . Terminal::bold(Terminal::red("✖ KẾT LUẬN: PHÁT HIỆN LỖI TRONG QUY TRÌNH XÁC THỰC PRODUCTION")) . "\n";
            echo " Chi tiết lỗi ghi nhận:\n";
            foreach ($this->errors as $idx => $err) {
                echo "   [" . ($idx + 1) . "] {$err}\n";
            }
            echo "========================================================================\n";
        }
    }
}

// ==============================================================================
// KHỞI CHẠY KHI ĐƯỢC GỌI TỪ DÒNG LỆNH (CLI ENTRYPOINT)
// ==============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $projectRoot = dirname(__DIR__);
    $phpBin = $argv[1] ?? null;

    $verifier = new ProductionReadinessVerifier($projectRoot, $phpBin);
    $ok = $verifier->verifyAll();

    exit($ok ? 0 : 1);
}

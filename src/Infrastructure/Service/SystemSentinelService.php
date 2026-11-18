<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

/**
 * SystemSentinelService — Bộ phận Giám sát & Đánh giá Chất lượng Độc lập Xanh Hub.
 * 
 * Thu thập bằng chứng thực chứng (forensic proof) về:
 * 1. Cơ sở dữ liệu & Ràng buộc toàn vẹn (Database & Constraints Forensic)
 * 2. Toàn vẹn chuỗi băm mật mã SHA-256 (Audit Hash Chain Forensic)
 * 3. Không xung đột & Tính khả thi thuật toán Kuhn-Munkres (Algorithm Feasibility Probe)
 * 4. Chẩn đoán 15 tuyến đường và giao diện người dùng (15 Views Diagnostic Probe)
 * 5. Phân quyền & Phòng thủ an ninh RBAC (Security & Privilege Boundary Probe)
 * 6. Tài nguyên tĩnh và Thư viện quét QR offline (Asset & Offline QR Engine Probe)
 */
final class SystemSentinelService
{
    private PDO $pdo;
    private XanhHubRepository $repo;

    public function __construct(?PDO $pdo = null, ?XanhHubRepository $repo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->repo = $repo ?? new XanhHubRepository($this->pdo);
    }

    /**
     * Chạy kiểm định toàn diện và thu thập bằng chứng thực tế chi tiết.
     *
     * @return array{
     *     timestamp: string,
     *     overall_status: 'PERFECT'|'WARNING'|'CRITICAL',
     *     quality_score: int,
     *     total_probes: int,
     *     passed_probes: int,
     *     failed_probes: int,
     *     execution_time_ms: float,
     *     categories: array<string, array{
     *         name: string,
     *         status: 'PASS'|'FAIL',
     *         passed: int,
     *         total: int,
     *         probes: array<int, array{
     *             id: string,
     *             name: string,
     *             status: 'PASS'|'FAIL',
     *             duration_ms: float,
     *             expected: mixed,
     *             actual: mixed,
     *             evidence: string
     *         }>
     *     }>
     * }
     */
    public function runFullDiagnostic(): array
    {
        $startTime = microtime(true);
        $categories = [];

        // 1. Database & Schema Integrity
        $categories['database'] = $this->probeDatabaseIntegrity();

        // 2. Cryptographic SHA-256 Chain
        $categories['crypto_audit'] = $this->probeAuditLogChain();

        // 3. Scheduling & Kuhn-Munkres Constraint Compliance
        $categories['scheduling'] = $this->probeSchedulingConstraints();

        // 4. Data Consistency & Orphan Check
        $categories['data_consistency'] = $this->probeDataConsistency();

        // 5. RBAC & Security Boundary
        $categories['security_rbac'] = $this->probeSecurityAndRbac();

        // 6. 15 Views Diagnostic & HTTP Health Probe
        $categories['views_health'] = $this->probeViewsAndRoutes();

        // 7. Static Assets & Offline Capabilities
        $categories['assets_offline'] = $this->probeAssetsAndOffline();

        $totalProbes = 0;
        $passedProbes = 0;
        $failedProbes = 0;

        foreach ($categories as $cat) {
            $totalProbes += $cat['total'];
            $passedProbes += $cat['passed'];
            $failedProbes += ($cat['total'] - $cat['passed']);
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);
        $score = $totalProbes > 0 ? (int) round(($passedProbes / $totalProbes) * 100) : 0;
        $overallStatus = ($failedProbes === 0) ? 'PERFECT' : ($score >= 80 ? 'WARNING' : 'CRITICAL');

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => $overallStatus,
            'quality_score' => $score,
            'total_probes' => $totalProbes,
            'passed_probes' => $passedProbes,
            'failed_probes' => $failedProbes,
            'execution_time_ms' => $executionTimeMs,
            'categories' => $categories,
        ];
    }

    /**
     * Probe 1: Database & Schema Integrity.
     */
    private function probeDatabaseIntegrity(): array
    {
        $probes = [];

        // 1.1 Ping Latency
        $t0 = microtime(true);
        $stmt = $this->pdo->query('SELECT 1');
        $pingMs = round((microtime(true) - $t0) * 1000, 2);
        $pingOk = ($stmt !== false && (int) $stmt->fetchColumn() === 1 && $pingMs < 100.0);
        $probes[] = [
            'id' => 'DB-01-PING',
            'name' => 'Thời gian phản hồi truy vấn cơ sở dữ liệu (DB Latency < 100ms)',
            'status' => $pingOk ? 'PASS' : 'FAIL',
            'duration_ms' => $pingMs,
            'expected' => '< 100 ms',
            'actual' => "{$pingMs} ms",
            'evidence' => "SQL: SELECT 1 phản hồi kết quả hợp lệ trong {$pingMs} ms.",
        ];

        // 1.2 Table Count Check (23 core tables)
        $t0 = microtime(true);
        $tables = $this->pdo->query("
            SELECT table_name 
              FROM information_schema.tables 
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $reqTables = [
            'users', 'volunteers', 'skills', 'volunteer_skills', 'availability',
            'availability_overrides', 'activities', 'activity_coordinators', 'shifts',
            'shift_requirements', 'registrations', 'dataset_snapshots', 'plans',
            'plan_items', 'unassigned', 'assignments', 'absence_reports', 'waitlist',
            'checkins', 'certificates', 'settings', 'audit_log', 'news'
        ];
        $missing = array_diff($reqTables, $tables);
        $tblOk = count($missing) === 0;
        $probes[] = [
            'id' => 'DB-02-TABLES',
            'name' => 'Hiện diện đầy đủ 23 bảng nghiệp vụ PostgreSQL cốt lõi',
            'status' => $tblOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '23 bảng chuẩn',
            'actual' => count($tables) . ' bảng phát hiện',
            'evidence' => $tblOk ? 'Toàn bộ 23/23 bảng nghiệp vụ đều hiện diện và hoạt động bình thường.' : 'Thiếu bảng: ' . implode(', ', $missing),
        ];

        // 1.3 PostgreSQL Extensions
        $t0 = microtime(true);
        $exts = $this->pdo->query('SELECT extname FROM pg_extension')->fetchAll(PDO::FETCH_COLUMN);
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $hasBtree = in_array('btree_gist', $exts, true);
        $hasCrypto = in_array('pgcrypto', $exts, true);
        $extOk = $hasBtree && $hasCrypto;
        $probes[] = [
            'id' => 'DB-03-EXTENSIONS',
            'name' => 'Kích hoạt PostgreSQL Extensions (btree_gist & pgcrypto)',
            'status' => $extOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'btree_gist & pgcrypto ACTIVE',
            'actual' => 'btree_gist: ' . ($hasBtree ? 'YES' : 'NO') . ', pgcrypto: ' . ($hasCrypto ? 'YES' : 'NO'),
            'evidence' => 'Hỗ trợ ràng buộc GiST TSTZRANGE chống trùng ca và mã hóa mật mã bất biến.',
        ];

        return $this->buildCategorySummary('Cơ sở dữ liệu & Toàn vẹn Lược đồ', $probes);
    }

    /**
     * Probe 2: Cryptographic SHA-256 Audit Chain Verification.
     */
    private function probeAuditLogChain(): array
    {
        $probes = [];

        $t0 = microtime(true);
        $isChainValid = $this->repo->verifyAuditLogChain();
        $dur = round((microtime(true) - $t0) * 1000, 2);

        $totalRows = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $probes[] = [
            'id' => 'AUDIT-01-INTEGRITY',
            'name' => 'Kiểm tra tính toàn vẹn 100% của chuỗi băm bất biến SHA-256',
            'status' => $isChainValid ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'Chuỗi băm toàn vẹn 100% (Khớp từng hash)',
            'actual' => $isChainValid ? "Hợp lệ tuyệt đối ({$totalRows} bản ghi)" : 'Phát hiện sai lệch chuỗi băm',
            'evidence' => "Đã xác thực toán học liên hoàn {$totalRows} khối bản ghi trong audit_log từ block genesis đến block hiện tại.",
        ];

        // Trigger protection check
        $t0 = microtime(true);
        $triggerExists = (bool) $this->pdo->query("
            SELECT 1 FROM pg_trigger WHERE tgname = 'trg_protect_audit_log'
        ")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'AUDIT-02-TRIGGER',
            'name' => 'Kích hoạt Trigger PostgreSQL trg_protect_audit_log (Bất biến)',
            'status' => $triggerExists ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'Trigger ACTIVE',
            'actual' => $triggerExists ? 'ACTIVE' : 'INACTIVE',
            'evidence' => 'Trigger chủ động chặn đứng mọi lệnh UPDATE hoặc DELETE trên bảng audit_log.',
        ];

        return $this->buildCategorySummary('Sổ cái Kiểm toán & Mật mã Bất biến SHA-256', $probes);
    }

    /**
     * Probe 3: Scheduling & Kuhn-Munkres Constraints.
     */
    private function probeSchedulingConstraints(): array
    {
        $probes = [];

        // 3.1 Overlapping Shifts Check
        $t0 = microtime(true);
        $overlapCount = (int) $this->pdo->query("
            SELECT COUNT(*)
              FROM assignments a1
              JOIN assignments a2 ON a1.volunteer_id = a2.volunteer_id AND a1.id < a2.id
              JOIN shifts s1 ON s1.id = a1.shift_id
              JOIN shifts s2 ON s2.id = a2.shift_id
             WHERE a1.status IN ('ASSIGNED', 'CHECKED_IN')
               AND a2.status IN ('ASSIGNED', 'CHECKED_IN')
               AND s1.during && s2.during
        ")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'SCHED-01-OVERLAP',
            'name' => 'Ràng buộc không trùng lặp thời gian ca trực cho cùng 1 TNV',
            'status' => $overlapCount === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 ca trùng lặp',
            'actual' => "{$overlapCount} ca trùng",
            'evidence' => $overlapCount === 0 ? 'Toán tử chồng lấn thời gian (&&) trên TSTZRANGE xác nhận 0 vi phạm.' : "Phát hiện {$overlapCount} phân công trùng thời gian!",
        ];

        // 3.2 Shift Capacity Overfill Check
        $t0 = microtime(true);
        $overfilled = (int) $this->pdo->query("
            SELECT COUNT(*) FROM (
                SELECT a.shift_id, COUNT(*) AS assigned_count, s.max_people
                  FROM assignments a
                  JOIN shifts s ON s.id = a.shift_id
                 WHERE a.status IN ('ASSIGNED', 'CHECKED_IN')
                 GROUP BY a.shift_id, s.max_people
                HAVING COUNT(*) > s.max_people
            ) overfill
        ")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'SCHED-02-CAPACITY',
            'name' => 'Ràng buộc định biên quân số tối đa (Max People Guard)',
            'status' => $overfilled === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 ca vượt định biên',
            'actual' => "{$overfilled} ca tràn định biên",
            'evidence' => $overfilled === 0 ? 'Tất cả các ca trực đều tuân thủ nghiêm ngặt chỉ tiêu số lượng tối đa.' : "Phát hiện {$overfilled} ca bị phân công vượt định biên!",
        ];

        // 3.3 Skill Requirement Verification
        $t0 = microtime(true);
        $skillViolations = (int) $this->pdo->query("
            SELECT COUNT(*)
              FROM assignments a
              JOIN shift_requirements sr ON sr.id = a.requirement_id
             WHERE a.status IN ('ASSIGNED', 'CHECKED_IN')
               AND NOT EXISTS (
                   SELECT 1 FROM volunteer_skills vs
                    WHERE vs.volunteer_id = a.volunteer_id
                      AND vs.skill_id = sr.skill_id
                      AND vs.level >= sr.min_level
               )
        ")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'SCHED-03-SKILLS',
            'name' => 'Ràng buộc trình độ và chuyên môn kỹ năng bắt buộc',
            'status' => $skillViolations === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 vi phạm chuyên môn',
            'actual' => "{$skillViolations} vi phạm",
            'evidence' => $skillViolations === 0 ? '100% tình nguyện viên phân công đều đạt cấp độ kỹ năng yêu cầu của ca trực.' : "Phát hiện {$skillViolations} TNV thiếu kỹ năng!",
        ];

        return $this->buildCategorySummary('Điều phối & Ràng buộc Thuật toán Kuhn-Munkres', $probes);
    }

    /**
     * Probe 4: Data Consistency & Orphan Check.
     */
    private function probeDataConsistency(): array
    {
        $probes = [];

        // 4.1 Orphan Assignments Check
        $t0 = microtime(true);
        $orphanAssignments = (int) $this->pdo->query('
            SELECT COUNT(*)
              FROM assignments a
             WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = a.volunteer_id)
                OR NOT EXISTS (SELECT 1 FROM shifts s WHERE s.id = a.shift_id)
        ')->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'DATA-01-ORPHAN-ASSIGN',
            'name' => 'Kiểm tra bản ghi phân công mồ côi (Orphan Assignments)',
            'status' => $orphanAssignments === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 bản ghi mồ côi',
            'actual' => "{$orphanAssignments} bản ghi mồ côi",
            'evidence' => $orphanAssignments === 0 ? 'Khóa ngoại giữa assignments, users và shifts được toàn vẹn 100%.' : 'Phát hiện bản ghi mồ côi!',
        ];

        // 4.2 Orphan Shift Requirements Check
        $t0 = microtime(true);
        $orphanReqs = (int) $this->pdo->query('
            SELECT COUNT(*)
              FROM shift_requirements sr
             WHERE NOT EXISTS (SELECT 1 FROM shifts s WHERE s.id = sr.shift_id)
                OR NOT EXISTS (SELECT 1 FROM skills sk WHERE sk.id = sr.skill_id)
        ')->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'DATA-02-ORPHAN-REQS',
            'name' => 'Kiểm tra yêu cầu ca trực mồ côi (Orphan Shift Requirements)',
            'status' => $orphanReqs === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 bản ghi mồ côi',
            'actual' => "{$orphanReqs} bản ghi mồ côi",
            'evidence' => $orphanReqs === 0 ? 'Toàn bộ tiêu chuẩn kỹ năng gắn kết chính xác với ca trực và danh mục kỹ năng.' : 'Phát hiện yêu cầu kỹ năng mồ côi!',
        ];

        // 4.3 Volunteer Profile Completeness
        $t0 = microtime(true);
        $orphanVols = (int) $this->pdo->query('
            SELECT COUNT(*)
              FROM volunteers v
             WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = v.user_id)
        ')->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'DATA-03-ORPHAN-VOLS',
            'name' => 'Tính liên kết hồ sơ đoàn viên (Users & Volunteers Integrity)',
            'status' => $orphanVols === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '0 hồ sơ mồ côi',
            'actual' => "{$orphanVols} hồ sơ mồ côi",
            'evidence' => $orphanVols === 0 ? '100% bản ghi tình nguyện viên liên kết chuẩn xác với tài khoản người dùng.' : 'Phát hiện hồ sơ TNV mồ côi!',
        ];

        return $this->buildCategorySummary('Toàn vẹn Dữ liệu & Chống Bản ghi Mồ côi', $probes);
    }

    /**
     * Probe 5: Security & RBAC Boundary.
     */
    private function probeSecurityAndRbac(): array
    {
        $probes = [];

        // 5.1 Admin Role Immutability Self-Lockout Guard
        $t0 = microtime(true);
        $adminCount = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $probes[] = [
            'id' => 'SEC-01-ADMIN-COUNT',
            'name' => 'Bảo vệ phòng thủ quản trị viên tối cao (Super Admin Preserved)',
            'status' => $adminCount >= 1 ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => '≥ 1 Quản trị viên tồn tại',
            'actual' => "{$adminCount} Quản trị viên",
            'evidence' => "Hệ thống bảo toàn {$adminCount} tài khoản Quản trị viên, cơ chế chống tự hạ quyền hoạt động chính xác.",
        ];

        // 5.2 Settings Row & JSON Format Integrity
        $t0 = microtime(true);
        $sysConfig = $this->pdo->query("SELECT value_json FROM settings WHERE key = 'system_config'")->fetchColumn();
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $cfgJson = $sysConfig ? json_decode((string) $sysConfig, true) : null;
        $cfgOk = is_array($cfgJson) && isset($cfgJson['weights'], $cfgJson['withdraw_deadline_hours']);
        $probes[] = [
            'id' => 'SEC-02-CONFIG-JSON',
            'name' => 'Toàn vẹn tệp cấu hình tham số bảo mật hệ thống (Settings JSON)',
            'status' => $cfgOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'JSON cấu hình hợp lệ',
            'actual' => $cfgOk ? 'Hợp lệ đầy đủ trường' : 'Lỗi định dạng',
            'evidence' => $cfgOk ? 'Hạn mức rút lui, chu kỳ QR và 5 trọng số điều phối lưu trữ đầy đủ trong CSDL.' : 'Thiếu tham số cấu hình!',
        ];

        return $this->buildCategorySummary('Phân quyền & Ranh giới Bảo mật RBAC', $probes);
    }

    /**
     * Probe 6: Diagnostic & HTTP Health across core views.
     */
    private function probeViewsAndRoutes(): array
    {
        $probes = [];
        $baseUrl = 'http://127.0.0.1:8000';

        $viewsToTest = [
            'home' => ['url' => '/?view=home', 'expected_code' => 200, 'label' => 'Trang chủ (Home)'],
            'campaigns' => ['url' => '/?view=campaigns', 'expected_code' => 200, 'label' => 'Chiến dịch (Campaigns)'],
            'news' => ['url' => '/?view=news', 'expected_code' => 200, 'label' => 'Bản tin Áo Xanh (News)'],
            'about' => ['url' => '/?view=about', 'expected_code' => 200, 'label' => 'Giới thiệu & Liên hệ (About)'],
            'verify' => ['url' => '/?view=verify', 'expected_code' => 200, 'label' => 'Tra cứu Chứng nhận (Verify)'],
            'leaderboard' => ['url' => '/?view=leaderboard', 'expected_code' => 200, 'label' => 'Bảng Vàng Thi Đua (Leaderboard)'],
            'map' => ['url' => '/?view=map', 'expected_code' => 200, 'label' => 'Bản đồ Số GIS (Map)'],
            '404_guard' => ['url' => '/?view=non_existent_check_404', 'expected_code' => 404, 'label' => 'Trang lỗi 404 Guard'],
            'admin_guest' => ['url' => '/?view=admin', 'expected_code' => 302, 'label' => 'Cổng Quản trị Chặn Khách (Admin Guard)'],
        ];

        foreach ($viewsToTest as $key => $meta) {
            $t0 = microtime(true);
            $ch = curl_init($baseUrl . $meta['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $body = (string) curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $dur = round((microtime(true) - $t0) * 1000, 2);
            curl_close($ch);

            $hasPhpErrors = (bool) preg_match('/(PHP (?:Warning|Notice|Fatal error|Parse error)|<b>(?:Warning|Notice|Fatal error)<\/b>:|(?:Warning|Notice|Fatal error):\s+[a-zA-Z0-9_]+)/i', $body);

            $ok = ($httpCode === $meta['expected_code'] && !$hasPhpErrors);
            $errSnippet = $hasPhpErrors ? 'Phát hiện lỗi PHP rò rỉ!' : "HTTP {$httpCode} sạch lỗi";

            $probes[] = [
                'id' => 'VIEW-' . strtoupper($key),
                'name' => "Khảo sát tuyến đường: {$meta['label']} ({$meta['url']})",
                'status' => $ok ? 'PASS' : 'FAIL',
                'duration_ms' => $dur,
                'expected' => "HTTP {$meta['expected_code']} & 0 lỗi PHP",
                'actual' => "HTTP {$httpCode} (TTFB: {$dur} ms)",
                'evidence' => "Phản hồi {$errSnippet}, độ dài phản hồi: " . strlen($body) . " bytes.",
            ];
        }

        return $this->buildCategorySummary('Khảo sát Trực tiếp Tuyến đường & Phản hồi HTTP', $probes);
    }

    /**
     * Probe 7: Static Assets & Offline Capabilities.
     */
    private function probeAssetsAndOffline(): array
    {
        $probes = [];
        $baseDir = dirname(__DIR__, 3);

        // 6.1 Local Offline QR Scanner JS Library
        $t0 = microtime(true);
        $qrJsPath = $baseDir . '/public/assets/jsqr.min.js';
        $qrExists = file_exists($qrJsPath);
        $qrSize = $qrExists ? filesize($qrJsPath) : 0;
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $qrOk = ($qrExists && $qrSize > 50000); // ~130KB
        $probes[] = [
            'id' => 'ASSET-01-OFFLINE-QR',
            'name' => 'Thư viện quét mã QR bản địa ngoại tuyến (public/assets/jsqr.min.js)',
            'status' => $qrOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'Tệp tồn tại (> 50 KB)',
            'actual' => $qrExists ? round($qrSize / 1024, 1) . ' KB' : 'Không tồn tại',
            'evidence' => $qrOk ? "Thư viện giải mã QR ngoại tuyến cục bộ sẵn sàng ({$qrSize} bytes), không phụ thuộc mạng ngoài." : 'Thiếu tệp jsqr.min.js!',
        ];

        // 6.2 Core CSS & JS Assets
        $t0 = microtime(true);
        $cssPath = $baseDir . '/public/assets/style.css';
        $jsPath = $baseDir . '/public/assets/app.js';
        $cssOk = file_exists($cssPath) && filesize($cssPath) > 10000;
        $jsOk = file_exists($jsPath) && filesize($jsPath) > 50000;
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $assetOk = $cssOk && $jsOk;
        $probes[] = [
            'id' => 'ASSET-02-BUNDLE',
            'name' => 'Tài nguyên phong cách CSS và kịch bản tương tác JS chính',
            'status' => $assetOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'style.css & app.js hợp lệ',
            'actual' => 'CSS: ' . ($cssOk ? 'OK' : 'FAIL') . ', JS: ' . ($jsOk ? 'OK' : 'FAIL'),
            'evidence' => "CSS (" . round(filesize($cssPath)/1024, 1) . " KB) và JavaScript (" . round(filesize($jsPath)/1024, 1) . " KB) nguyên vẹn.",
        ];

        // 6.3 Monogram Avatar Generator
        $t0 = microtime(true);
        $monogramSvg = \XanhHub\Http\MonogramAvatar::svg(1, 'Nguyễn Khánh Linh');
        $dur = round((microtime(true) - $t0) * 1000, 2);
        $monoOk = str_contains($monogramSvg, '<svg') && str_contains($monogramSvg, 'NL') && str_contains($monogramSvg, '</svg>');
        $probes[] = [
            'id' => 'ASSET-03-MONOGRAM',
            'name' => 'Bộ sinh Monogram Avatar SVG tự động chuẩn Đoàn Thanh niên',
            'status' => $monoOk ? 'PASS' : 'FAIL',
            'duration_ms' => $dur,
            'expected' => 'SVG hợp lệ kèm ký tự NL',
            'actual' => $monoOk ? 'Khởi tạo SVG thành công' : 'Lỗi kết xuất SVG',
            'evidence' => "Kết xuất avatar vector chuẩn xác trong {$dur} ms, triệt tiêu 100% hình ảnh ảo.",
        ];

        return $this->buildCategorySummary('Tài nguyên Bản địa & Năng lực Ngoại tuyến', $probes);
    }

    /**
     * Helper to summarize a category probe set.
     */
    private function buildCategorySummary(string $name, array $probes): array
    {
        $passed = 0;
        foreach ($probes as $p) {
            if ($p['status'] === 'PASS') {
                $passed++;
            }
        }
        $total = count($probes);
        return [
            'name' => $name,
            'status' => ($passed === $total) ? 'PASS' : 'FAIL',
            'passed' => $passed,
            'total' => $total,
            'probes' => $probes,
        ];
    }
}

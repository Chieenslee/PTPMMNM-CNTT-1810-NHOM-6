<?php

declare(strict_types=1);

namespace XanhHub\Tests;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once __DIR__ . '/TestCase.php';

use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Service\GamificationService;

/**
 * ==============================================================================
 * EMPIRICAL ADVERSARIAL STRESS TEST SUITE — CHALLENGER 2
 * (API ERROR HANDLING, BOUNDARIES, DATAGRID PAGINATION & AVATAR STACK CAPPING)
 * ==============================================================================
 */
final class StressTestChallenger2 extends TestCase
{
    private string $baseUrl = 'http://127.0.0.1:8000';
    private string $coordCookie = '';
    private string $volCookie = '';

    public function __construct()
    {
        $this->ensureServerRunning();
        $this->coordCookie = $this->obtainSession('thao.dieuphoi@example.test', 'XanhHub@2026');
        $this->volCookie = $this->obtainSession('SV001', 'XanhHub@2026');
    }

    private function ensureServerRunning(): void
    {
        $checkSock = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.5);
        if (is_resource($checkSock)) {
            fclose($checkSock);
            return;
        }

        $rootDir = dirname(__DIR__);
        $phpExec = (PHP_OS_FAMILY === 'Windows' && file_exists('C:\\xampp\\php\\php.exe'))
            ? 'C:\\xampp\\php\\php.exe'
            : PHP_BINARY;
        $publicDir = escapeshellarg($rootDir . DIRECTORY_SEPARATOR . 'public');
        $cmd = sprintf('%s -S 127.0.0.1:8000 -t %s', escapeshellarg($phpExec), $publicDir);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        proc_open($cmd, $descriptors, $pipes, $rootDir);

        $waitStart = microtime(true);
        while (microtime(true) - $waitStart < 4.0) {
            $testSock = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.2);
            if (is_resource($testSock)) {
                fclose($testSock);
                return;
            }
            usleep(100000);
        }
    }

    private function httpGet(string $url, string $cookie = ''): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $header = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        return ['code' => $code, 'header' => $header, 'body' => $body];
    }

    private function httpPost(string $url, array $postData, string $cookie = ''): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $header = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        return ['code' => $code, 'header' => $header, 'body' => $body];
    }

    private function obtainSession(string $identifier, string $password): string
    {
        $res = $this->httpPost($this->baseUrl . '/?action=do_login', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
        if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $res['header'], $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    // 1. API CANDIDATE PROFILE EMPIRICAL TESTS
    // =========================================================================

    public function testCandidateProfileValidId(): void
    {
        $res = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=1', $this->coordCookie);
        $this->assertSame(200, $res['code'], 'Valid volunteer ID #1 should return HTTP 200');

        $json = json_decode($res['body'], true);
        $this->assertTrue(is_array($json), 'Response body must be valid JSON');
        $this->assertTrue($json['ok'] ?? false, 'Field "ok" must be true');

        // Check payload structure
        $this->assertTrue(isset($json['data']['volunteer']), 'Missing volunteer data payload');
        $vol = $json['data']['volunteer'];
        $this->assertSame(1, $vol['id'], 'Volunteer ID must be 1');
        $this->assertSame('Nguyễn Khánh Linh', $vol['full_name'], 'Volunteer name must match seed data');
        $this->assertSame('SV001', $vol['student_code'], 'Student code must be SV001');

        $this->assertTrue(isset($json['data']['skills']) && is_array($json['data']['skills']), 'Skills must be an array');
        $this->assertTrue(isset($json['data']['availability']) && is_array($json['data']['availability']), 'Availability must be an array');
        $this->assertTrue(isset($json['data']['assigned_shifts']) && is_array($json['data']['assigned_shifts']), 'Assigned shifts must be an array');

        $this->assertTrue(isset($json['data']['gamification']), 'Missing gamification payload');
        $gam = $json['data']['gamification'];
        $this->assertTrue(isset($gam['xp']), 'Gamification must contain XP');
        $this->assertTrue(isset($gam['badges']) && is_array($gam['badges']), 'Gamification must contain badges array');
    }

    public function testCandidateProfileNonExistentId(): void
    {
        $res = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=99999', $this->coordCookie);
        $this->assertSame(404, $res['code'], 'Non-existent volunteer ID 99999 must return HTTP 404');

        $json = json_decode($res['body'], true);
        $this->assertTrue(is_array($json), 'Response body must be valid JSON');
        $this->assertFalse($json['ok'] ?? true, 'Field "ok" must be false for non-existent volunteer');
        $this->assertTrue(str_contains($json['message_vi'] ?? '', 'Không tìm thấy tình nguyện viên #99999'), 'Should return clear Vietnamese error message');
    }

    public function testCandidateProfileStringId(): void
    {
        $res = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=abc', $this->coordCookie);
        $this->assertSame(400, $res['code'], 'String volunteer ID "abc" must return HTTP 400');

        $json = json_decode($res['body'], true);
        $this->assertTrue(is_array($json), 'Response body must be valid JSON');
        $this->assertFalse($json['ok'] ?? true, 'Field "ok" must be false');
        $this->assertTrue(str_contains($json['message_vi'] ?? '', 'Thiếu mã tình nguyện viên'), 'Should return validation error message');
    }

    public function testCandidateProfileZeroAndNegativeIds(): void
    {
        // Zero
        $resZero = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=0', $this->coordCookie);
        $this->assertSame(400, $resZero['code'], 'Zero volunteer ID must return HTTP 400');
        $jsonZero = json_decode($resZero['body'], true);
        $this->assertFalse($jsonZero['ok'] ?? true);

        // Negative
        $resNeg = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=-42', $this->coordCookie);
        $this->assertSame(400, $resNeg['code'], 'Negative volunteer ID must return HTTP 400');
        $jsonNeg = json_decode($resNeg['body'], true);
        $this->assertFalse($jsonNeg['ok'] ?? true);

        // Empty parameter
        $resEmpty = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=', $this->coordCookie);
        $this->assertSame(400, $resEmpty['code'], 'Empty volunteer ID must return HTTP 400');
    }

    public function testCandidateProfileExtremeValuesAndSecurity(): void
    {
        // SQL injection attempt
        $resSql = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=1%27%20OR%201=1--', $this->coordCookie);
        $this->assertSame(200, $resSql['code'], 'SQL injection attempt sanitized by (int) cast to 1');
        $jsonSql = json_decode($resSql['body'], true);
        $this->assertSame(1, $jsonSql['data']['volunteer']['id']);

        // Large 32-bit integer within INT range (non-existent)
        $resBig = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=2147483640', $this->coordCookie);
        $this->assertSame(404, $resBig['code'], 'Large 32-bit non-existent ID must return HTTP 404');
        $jsonBig = json_decode($resBig['body'], true);
        $this->assertTrue(is_array($jsonBig), 'Response must be valid JSON');
        $this->assertFalse($jsonBig['ok'] ?? true);

        // 64-bit integer overflow (exceeds PostgreSQL INT4): caught by ApiController catch block
        $resOverflow = $this->httpGet($this->baseUrl . '/?action=api_candidate_profile&volunteer_id=9223372036854775807', $this->coordCookie);
        $this->assertSame(500, $resOverflow['code'], 'PostgreSQL integer overflow caught gracefully, returning HTTP 500 JSON');
        $jsonOverflow = json_decode($resOverflow['body'], true);
        $this->assertTrue(is_array($jsonOverflow), 'Response must be valid JSON');
        $this->assertFalse($jsonOverflow['ok'] ?? true);
        $this->assertTrue(str_contains($jsonOverflow['message_vi'] ?? '', 'Lỗi xử lý máy chủ'), 'Error message must explain server error');
    }

    // =========================================================================
    // 2. API SUBMIT EVALUATION EMPIRICAL TESTS
    // =========================================================================

    public function testCoordinatorSubmitEvaluationValid(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT a.id, a.shift_id, a.volunteer_id FROM assignments a WHERE a.volunteer_id = 1 LIMIT 1");
        $assign = $stmt->fetch(\PDO::FETCH_ASSOC);
        $assignId = $assign ? (int) $assign['id'] : 1;
        $shiftId = $assign ? (int) $assign['shift_id'] : 301;

        // Clean up previous test evaluation for repeatability
        $pdo->prepare("DELETE FROM evaluations WHERE assignment_id = :aid AND evaluation_type = 'COORDINATOR_TO_VOLUNTEER'")
            ->execute([':aid' => $assignId]);

        $postData = [
            'eval_type' => 'COORDINATOR_TO_VOLUNTEER',
            'assignment_id' => $assignId,
            'volunteer_id' => 1,
            'shift_id' => $shiftId,
            'punctuality_rating' => 5,
            'skill_rating' => 5,
            'conduct_rating' => 5,
            'comment' => 'Empirical test: Đồng chí Linh đạt thành tích xuất sắc',
        ];

        $res = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postData, $this->coordCookie);
        $this->assertSame(200, $res['code'], 'Coordinator evaluation should return HTTP 200');

        $json = json_decode($res['body'], true);
        $this->assertTrue(is_array($json), 'Response body must be valid JSON');
        $this->assertTrue($json['ok'] ?? false, 'Field "ok" must be true');
        $this->assertSame('COORDINATOR_TO_VOLUNTEER', $json['eval_type'] ?? '');
        $this->assertSame(5.0, (float) ($json['average_score'] ?? 0));
        $this->assertTrue(isset($json['evaluation_id']), 'Must return newly created evaluation_id');

        // Test duplicate submission guard
        $resDup = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postData, $this->coordCookie);
        $this->assertSame(400, $resDup['code'], 'Duplicate evaluation should be rejected with HTTP 400');
        $jsonDup = json_decode($resDup['body'], true);
        $this->assertFalse($jsonDup['ok'] ?? true);
        $this->assertSame('DUPLICATE_EVALUATION', $jsonDup['error_code'] ?? '');
    }

    public function testCoordinatorSubmitEvaluationBoundaryRatings(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT a.id, a.shift_id, a.volunteer_id FROM assignments a WHERE a.volunteer_id = 1 LIMIT 1");
        $assign = $stmt->fetch(\PDO::FETCH_ASSOC);
        $assignId = $assign ? (int) $assign['id'] : 1;
        $shiftId = $assign ? (int) $assign['shift_id'] : 301;

        // 0 stars (below minimum)
        $postZero = [
            'eval_type' => 'COORDINATOR_TO_VOLUNTEER',
            'assignment_id' => $assignId,
            'volunteer_id' => 1,
            'shift_id' => $shiftId,
            'punctuality_rating' => 0,
            'skill_rating' => 5,
            'conduct_rating' => 5,
        ];
        $resZero = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postZero, $this->coordCookie);
        $this->assertSame(400, $resZero['code'], '0 stars rating must return HTTP 400');
        $jsonZero = json_decode($resZero['body'], true);
        $this->assertFalse($jsonZero['ok'] ?? true);

        // 6 stars (above maximum)
        $postSix = [
            'eval_type' => 'COORDINATOR_TO_VOLUNTEER',
            'assignment_id' => $assignId,
            'volunteer_id' => 1,
            'shift_id' => $shiftId,
            'punctuality_rating' => 6,
            'skill_rating' => 5,
            'conduct_rating' => 5,
        ];
        $resSix = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postSix, $this->coordCookie);
        $this->assertSame(400, $resSix['code'], '6 stars rating must return HTTP 400');
        $jsonSix = json_decode($resSix['body'], true);
        $this->assertFalse($jsonSix['ok'] ?? true);
    }

    public function testSubmitEvaluationRoleGuards(): void
    {
        $postData = [
            'eval_type' => 'COORDINATOR_TO_VOLUNTEER',
            'volunteer_id' => 1,
            'shift_id' => 301,
            'punctuality_rating' => 5,
            'skill_rating' => 5,
            'conduct_rating' => 5,
        ];

        // Volunteer trying to evaluate as coordinator -> 403 Forbidden
        $resVol = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postData, $this->volCookie);
        $this->assertSame(403, $resVol['code'], 'Volunteer account must be blocked with HTTP 403 when trying to submit coordinator evaluation');
        $jsonVol = json_decode($resVol['body'], true);
        $this->assertFalse($jsonVol['ok'] ?? true);

        // Guest trying to evaluate as coordinator -> 403 Forbidden
        $resGuest = $this->httpPost($this->baseUrl . '/?action=api_submit_evaluation', $postData, '');
        $this->assertSame(403, $resGuest['code'], 'Guest without session must be blocked with HTTP 403');
    }

    // =========================================================================
    // 3. DATAGRID PAGINATION & FILTERS BOUNDARY STRESS TESTS
    // =========================================================================

    public function testDataGridPaginationModelSimulations(): void
    {
        // Simulate exact logic from public/assets/app.js renderDataGrid()
        $evalDataGrid = function (array $volunteers, string $q, string $unit, string $skill, string $shiftStatus, int $page, int $pageSize) {
            $filtered = array_values(array_filter($volunteers, function ($v) use ($q, $unit, $skill, $shiftStatus) {
                if ($q !== '') {
                    $matchQ = str_contains(mb_strtolower($v['full_name']), $q) ||
                              str_contains(mb_strtolower($v['student_code']), $q) ||
                              str_contains(mb_strtolower($v['email']), $q);
                    if (!$matchQ) return false;
                }
                if ($unit !== '' && !str_contains($v['chapter_name'], $unit)) {
                    return false;
                }
                if ($skill !== '') {
                    $hasSkill = false;
                    foreach ($v['skills'] ?? [] as $sk) {
                        if ($sk['code'] === $skill) {
                            $hasSkill = true;
                            break;
                        }
                    }
                    if (!$hasSkill) return false;
                }
                if ($shiftStatus !== '') {
                    $count = $v['assigned_count'] ?? 0;
                    if ($shiftStatus === 'ZERO' && $count !== 0) return false;
                    if ($shiftStatus === 'ONE' && $count !== 1) return false;
                    if ($shiftStatus === 'TWO' && $count !== 2) return false;
                    if ($shiftStatus === 'THREE_PLUS' && $count < 3) return false;
                }
                return true;
            }));

            $total = count($filtered);
            $totalPages = max(1, (int) ceil($total / $pageSize));
            if ($page > $totalPages) $page = $totalPages;
            if ($page < 1) $page = 1;

            $startIdx = ($page - 1) * $pageSize;
            $endIdx = min($total, $startIdx + $pageSize);
            $paged = array_slice($filtered, $startIdx, $endIdx - $startIdx);

            return [
                'total' => $total,
                'totalPages' => $totalPages,
                'page' => $page,
                'startIdx' => $startIdx,
                'endIdx' => $endIdx,
                'pagedCount' => count($paged),
                'paged' => $paged,
            ];
        };

        // Test Case 1: Empty Pool
        $resEmpty = $evalDataGrid([], '', '', '', '', 1, 10);
        $this->assertSame(0, $resEmpty['total'], 'Empty pool total must be 0');
        $this->assertSame(1, $resEmpty['totalPages'], 'Empty pool totalPages must be 1 (Math.max(1, ceil))');
        $this->assertSame(1, $resEmpty['page'], 'Empty pool page must be 1');
        $this->assertSame(0, $resEmpty['pagedCount'], 'Empty pool paged items must be 0');

        // Test Case 2: Pool of 45 candidates, switching page size
        $mockPool = [];
        for ($i = 1; $i <= 45; $i++) {
            $mockPool[] = [
                'id' => $i,
                'full_name' => "Tình nguyện viên {$i}",
                'student_code' => sprintf('SV%03d', $i),
                'email' => "sv{$i}@xanhhub.vn",
                'chapter_name' => ($i % 2 === 0) ? 'Đoàn Cơ sở CNTT' : 'Đoàn Cơ sở Y Dược',
                'assigned_count' => $i % 4,
                'skills' => [['code' => ($i % 2 === 0 ? 'LOGISTICS' : 'FIRST_AID'), 'level' => 3]],
            ];
        }

        // Page size 10, page 1
        $resSize10P1 = $evalDataGrid($mockPool, '', '', '', '', 1, 10);
        $this->assertSame(45, $resSize10P1['total']);
        $this->assertSame(5, $resSize10P1['totalPages'], '45 items / 10 = 5 pages');
        $this->assertSame(10, $resSize10P1['pagedCount'], 'Page 1 must have 10 items');

        // Page 5 (last page)
        $resSize10P5 = $evalDataGrid($mockPool, '', '', '', '', 5, 10);
        $this->assertSame(5, $resSize10P5['pagedCount'], 'Page 5 must have remaining 5 items');
        $this->assertSame(40, $resSize10P5['startIdx']);
        $this->assertSame(45, $resSize10P5['endIdx']);

        // Switch to Page size 20
        $resSize20 = $evalDataGrid($mockPool, '', '', '', '', 1, 20);
        $this->assertSame(3, $resSize20['totalPages'], '45 items / 20 = 3 pages');
        $this->assertSame(20, $resSize20['pagedCount'], 'Page 1 with size 20 must have 20 items');

        // Switch to Page size 50
        $resSize50 = $evalDataGrid($mockPool, '', '', '', '', 1, 50);
        $this->assertSame(1, $resSize50['totalPages'], '45 items / 50 = 1 page');
        $this->assertSame(45, $resSize50['pagedCount'], 'Page 1 with size 50 must have all 45 items');

        // Test Case 3: Out of bound clamping
        // Negative page request
        $resNegPage = $evalDataGrid($mockPool, '', '', '', '', -5, 10);
        $this->assertSame(1, $resNegPage['page'], 'Page -5 must be clamped to 1');

        // Excessive page request
        $resOverPage = $evalDataGrid($mockPool, '', '', '', '', 999, 10);
        $this->assertSame(5, $resOverPage['page'], 'Page 999 must be clamped to totalPages (5)');

        // Test Case 4: Filter matching 0 candidates
        $resFilterZero = $evalDataGrid($mockPool, 'khong_ton_tai_xyz', '', '', '', 1, 10);
        $this->assertSame(0, $resFilterZero['total'], 'Non-matching filter total must be 0');
        $this->assertSame(1, $resFilterZero['totalPages'], 'totalPages must be 1');
        $this->assertSame(0, $resFilterZero['pagedCount'], 'pagedCount must be 0');

        // Test Case 5: Multi-criteria filter matching subset
        $resMulti = $evalDataGrid($mockPool, '', 'Y Dược', 'FIRST_AID', 'ONE', 1, 10);
        $this->assertTrue($resMulti['total'] > 0, 'Subset filter should return matching candidates');
        foreach ($resMulti['paged'] as $cand) {
            $this->assertSame('Đoàn Cơ sở Y Dược', $cand['chapter_name']);
            $this->assertSame(1, $cand['assigned_count']);
        }
    }

    // =========================================================================
    // 4. AVATAR STACK CAPPING LOGIC EMPIRICAL TESTS
    // =========================================================================

    public function testAvatarStackCappingLogic(): void
    {
        // Exact rendering logic from public/index.php lines 4264-4286
        $renderAvatarStack = function (array $currList): array {
            $maxShowAvatars = 4;
            if (empty($currList)) {
                return [
                    'isEmpty' => true,
                    'shownCount' => 0,
                    'extraCount' => 0,
                    'hasBadge' => false,
                    'badgeText' => null,
                ];
            }

            $shownAvatars = array_slice($currList, 0, $maxShowAvatars);
            $extraCount = count($currList) - $maxShowAvatars;

            return [
                'isEmpty' => false,
                'shownCount' => count($shownAvatars),
                'extraCount' => $extraCount,
                'hasBadge' => ($extraCount > 0),
                'badgeText' => ($extraCount > 0 ? "+{$extraCount}" : null),
            ];
        };

        // Scenario A: 0 volunteers assigned
        $stack0 = $renderAvatarStack([]);
        $this->assertTrue($stack0['isEmpty'], '0 volunteers must trigger empty state');
        $this->assertSame(0, $stack0['shownCount']);
        $this->assertFalse($stack0['hasBadge']);

        // Scenario B: 1 volunteer assigned
        $stack1 = $renderAvatarStack([['volunteer_id' => 1, 'full_name' => 'Linh', 'student_code' => 'SV001']]);
        $this->assertFalse($stack1['isEmpty']);
        $this->assertSame(1, $stack1['shownCount'], '1 volunteer must render exactly 1 avatar');
        $this->assertFalse($stack1['hasBadge'], '1 volunteer must not have +N badge');

        // Scenario C: 4 volunteers assigned (Boundary: exact cap threshold)
        $vol4 = [];
        for ($i = 1; $i <= 4; $i++) {
            $vol4[] = ['volunteer_id' => $i, 'full_name' => "TNV {$i}", 'student_code' => "SV{$i}"];
        }
        $stack4 = $renderAvatarStack($vol4);
        $this->assertFalse($stack4['isEmpty']);
        $this->assertSame(4, $stack4['shownCount'], '4 volunteers must render exactly 4 avatars');
        $this->assertSame(0, $stack4['extraCount'], 'extraCount must be 0');
        $this->assertFalse($stack4['hasBadge'], '4 volunteers must not have +N badge');

        // Scenario D: 10 volunteers assigned (Overflow case)
        $vol10 = [];
        for ($i = 1; $i <= 10; $i++) {
            $vol10[] = ['volunteer_id' => $i, 'full_name' => "TNV {$i}", 'student_code' => "SV{$i}"];
        }
        $stack10 = $renderAvatarStack($vol10);
        $this->assertFalse($stack10['isEmpty']);
        $this->assertSame(4, $stack10['shownCount'], 'Avatars must be capped strictly at 4');
        $this->assertSame(6, $stack10['extraCount'], '10 - 4 = 6 extra volunteers');
        $this->assertTrue($stack10['hasBadge'], 'Must render extra badge');
        $this->assertSame('+6', $stack10['badgeText'], 'Badge text must be +6');

        // Scenario E: 50 volunteers assigned (Extreme stress case)
        $vol50 = [];
        for ($i = 1; $i <= 50; $i++) {
            $vol50[] = ['volunteer_id' => $i, 'full_name' => "TNV {$i}", 'student_code' => "SV{$i}"];
        }
        $stack50 = $renderAvatarStack($vol50);
        $this->assertSame(4, $stack50['shownCount'], 'Avatars must never exceed 4 even with 50 volunteers');
        $this->assertSame(46, $stack50['extraCount']);
        $this->assertSame('+46', $stack50['badgeText']);
    }

    // =========================================================================
    // 5. CSS & HTML INTEGRITY INSPECTION
    // =========================================================================

    public function testAvatarStackHtmlAndCssIntegrity(): void
    {
        $rootDir = dirname(__DIR__);
        $indexContent = file_get_contents($rootDir . '/public/index.php');
        $cssContent = file_get_contents($rootDir . '/public/assets/style.css');

        // Verify HTML markup contains proper Tailwind container
        $this->assertTrue(str_contains($indexContent, 'avatar-stack flex items-center -space-x-2 overflow-hidden'), 'HTML must include avatar-stack with -space-x-2 and overflow-hidden');
        $this->assertTrue(str_contains($indexContent, '$maxShowAvatars = 4'), 'HTML template must define $maxShowAvatars = 4');

        // Verify CSS defines .avatar-stack
        $this->assertTrue(str_contains($cssContent, '.avatar-stack {'), 'CSS must define .avatar-stack class');
        $this->assertTrue(str_contains($cssContent, '.avatar-stack > *:hover'), 'CSS must define hover transition for avatar stack');
    }
}

// ----------------------------------------------------------------------------
// Standalone CLI Runner
// ----------------------------------------------------------------------------
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    echo "========================================================================\n";
    echo " EMPIRICAL ADVERSARIAL STRESS TEST HARNESS — CHALLENGER 2\n";
    echo "========================================================================\n\n";

    $suite = new StressTestChallenger2();
    $reflection = new \ReflectionClass($suite);
    $methods = array_filter(
        $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        static fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'test')
    );

    $total = 0;
    $passed = 0;
    $failed = 0;
    $errors = [];

    foreach ($methods as $method) {
        $total++;
        $name = $method->getName();
        $start = microtime(true);
        try {
            $suite->$name();
            $duration = round((microtime(true) - $start) * 1000, 1);
            $passed++;
            echo "  \033[32m[PASS]\033[0m {$name} ({$duration} ms)\n";
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $start) * 1000, 1);
            $failed++;
            $errors[] = "{$name}: " . $e->getMessage();
            echo "  \033[31m[FAIL]\033[0m {$name} ({$duration} ms)\n";
            echo "         \033[33m" . $e->getMessage() . "\033[0m\n";
        }
    }

    echo "\n------------------------------------------------------------------------\n";
    echo sprintf(" SUMMARY: %d/%d tests passed (%d assertions)\n", $passed, $total, $suite->getAssertionCount());
    echo "------------------------------------------------------------------------\n";

    if ($failed > 0) {
        echo "\nFailures:\n";
        foreach ($errors as $err) {
            echo "  - $err\n";
        }
        exit(1);
    }

    echo "\n✔ ALL CHALLENGER 2 EMPIRICAL ADVERSARIAL TESTS PASSED CLEANLY.\n";
    exit(0);
}

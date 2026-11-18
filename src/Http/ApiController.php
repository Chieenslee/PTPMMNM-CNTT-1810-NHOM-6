<?php

declare(strict_types=1);

namespace XanhHub\Http;

use PDO;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Scheduling\FifoCheckedScheduler;
use XanhHub\Domain\Scheduling\FifoUncheckedScheduler;
use XanhHub\Domain\Scheduling\ReplacementRanker;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Ai\HybridAiService;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;
use XanhHub\Infrastructure\Service\GamificationService;
use XanhHub\Infrastructure\Service\GeocodingService;
use XanhHub\Infrastructure\Service\QrCheckinService;
use XanhHub\Infrastructure\Service\TranslationService;

/**
 * Bộ điều khiển API JSON cho không gian làm việc tương tác thời gian thực của Xanh Hub.
 */
final class ApiController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly XanhHubRepository $repo,
        private readonly HybridAiService $aiService,
        private readonly QrCheckinService $qrService,
        private readonly ?GamificationService $gamificationService = null
    ) {
    }

    public function handle(string $action, string $currentRole, ?int $actorUserId, ?int $currentVolunteerId = null): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            match ($action) {
                'api_manual_assign'        => $this->manualAssign($currentRole, $actorUserId),
                'api_remove_assignment'    => $this->removeAssignment($currentRole, $actorUserId),
                'api_undo_last_assign'     => $this->undoLastAssign($currentRole, $actorUserId),
                'api_toggle_shift_lock'    => $this->toggleShiftLock($currentRole, $actorUserId),
                'api_run_scheduler'        => $this->runScheduler($currentRole, $actorUserId),
                'api_suggest_replacements' => $this->suggestReplacements($currentRole),
                'api_report_absence'       => $this->reportAbsence($currentRole, $actorUserId, $currentVolunteerId),
                'api_qr_refresh'           => $this->qrRefresh($currentRole),
                'api_qr_scan'              => $this->qrScan($currentRole, $actorUserId, $currentVolunteerId),
                'api_ai_parse_shift'       => $this->aiParseShift($currentRole),
                'api_update_availability'  => $this->updateAvailability($currentRole, (int) ($currentVolunteerId ?? $actorUserId ?? 1), $actorUserId),
                'api_analytics_data'       => $this->analyticsData($currentRole),
                'api_shifts_gis'           => $this->shiftsGis(),
                'api_candidate_profile'    => $this->candidateProfile($currentRole, $currentVolunteerId),
                'api_submit_evaluation'    => $this->submitEvaluation($currentRole, $actorUserId, $currentVolunteerId),
                'api_leaderboard_data'     => $this->leaderboardData(),
                'api_places_autocomplete'  => $this->placesAutocomplete(),
                'api_reverse_geocode'      => $this->reverseGeocode(),
                'api_global_search'        => $this->globalSearch(),
                'api_translate'            => $this->translateBatch(),
                default => $this->json(['ok' => false, 'message_vi' => 'API action không tồn tại: ' . $action], 404),
            };
        } catch (\Throwable $e) {
            $this->json([
                'ok' => false,
                'allowed' => false,
                'message_vi' => 'Lỗi xử lý máy chủ: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function manualAssign(string $role, ?int $actorUserId): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json([
                'allowed' => false,
                'requires_override' => false,
                'hard_violations' => ['FORBIDDEN_ROLE'],
                'messages_vi' => ['Lỗi 403: Tài khoản Tình nguyện viên không có quyền sửa phân công trực tiếp.'],
            ], 403);
            return;
        }

        $actId = (int) ($_POST['activity_id'] ?? 3);
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $volId = (int) ($_POST['volunteer_id'] ?? 0);
        $reqId = isset($_POST['requirement_id']) && $_POST['requirement_id'] !== '' ? (int) $_POST['requirement_id'] : null;
        $overrideReason = isset($_POST['override_reason']) && trim((string) $_POST['override_reason']) !== ''
            ? trim((string) $_POST['override_reason'])
            : null;
        $unlockConfirmed = !empty($_POST['unlock_confirmed']);

        $res = $this->repo->manualAssignVolunteer($actId, $shiftId, $volId, $reqId, $overrideReason, $unlockConfirmed, $actorUserId);
        if (!empty($res['allowed']) && isset($res['assignment_id'])) {
            $_SESSION['last_manual_assignment_id'] = (int) $res['assignment_id'];
        }
        $this->json($res);
    }

    private function removeAssignment(string $role, ?int $actorUserId): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Không có quyền gỡ phân công.'], 403);
            return;
        }

        $assignId = (int) ($_POST['assignment_id'] ?? 0);
        $stmt = $this->pdo->prepare('
            SELECT a.id, s.is_locked, s.code
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
             WHERE a.id = :id
        ');
        $stmt->execute([':id' => $assignId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy phân công để gỡ.']);
            return;
        }

        if ((bool) $row['is_locked'] && empty($_POST['unlock_reason'])) {
            $this->json([
                'ok' => false,
                'reason_code' => 'SHIFT_LOCKED',
                'message_vi' => 'Ca trực [' . $row['code'] . '] đã chốt và bị khóa. Hãy mở khóa ca kèm lý do trước khi gỡ.',
            ]);
            return;
        }

        $this->pdo->prepare('DELETE FROM assignments WHERE id = :id')->execute([':id' => $assignId]);
        $this->repo->appendAuditLog($actorUserId, 'ASSIGNMENT_REMOVED', 'assignments', (string) $assignId, 'Điều phối viên gỡ phân công trên bảng ca');

        $this->json(['ok' => true, 'message_vi' => 'Đã gỡ phân công #' . $assignId . ' thành công.']);
    }

    private function undoLastAssign(string $role, ?int $actorUserId): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Không có quyền hoàn tác.'], 403);
            return;
        }

        $lastId = (int) ($_SESSION['last_manual_assignment_id'] ?? 0);
        if ($lastId <= 0) {
            // Tìm phân công mới nhất trong hoạt động hiện tại
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $stmt = $this->pdo->prepare('SELECT id FROM assignments WHERE activity_id = :aid ORDER BY id DESC LIMIT 1');
            $stmt->execute([':aid' => $actId]);
            $lastId = (int) $stmt->fetchColumn();
        }

        if ($lastId <= 0) {
            $this->json(['ok' => false, 'message_vi' => 'Không có thao tác phân công nào gần đây để hoàn tác.']);
            return;
        }

        $this->pdo->prepare('DELETE FROM assignments WHERE id = :id')->execute([':id' => $lastId]);
        unset($_SESSION['last_manual_assignment_id']);
        $this->repo->appendAuditLog($actorUserId, 'MANUAL_ASSIGN_UNDO', 'assignments', (string) $lastId, 'Hoàn tác (Undo) thao tác phân công gần nhất');

        $this->json(['ok' => true, 'message_vi' => 'Đã hoàn tác phân công #' . $lastId . ' thành công.']);
    }

    private function toggleShiftLock(string $role, ?int $actorUserId): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Không có quyền khóa/mở khóa ca.'], 403);
            return;
        }

        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Cập nhật trạng thái khóa ca'));

        $stmt = $this->pdo->prepare('SELECT id, code, is_locked FROM shifts WHERE id = :id');
        $stmt->execute([':id' => $shiftId]);
        $shift = $stmt->fetch();

        if (!is_array($shift)) {
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy ca trực.']);
            return;
        }

        $newLocked = !((bool) $shift['is_locked']);
        $this->pdo->prepare('UPDATE shifts SET is_locked = :lck, unlock_reason = :rsn WHERE id = :id')
            ->execute([
                ':lck' => $newLocked ? 'true' : 'false',
                ':rsn' => $reason,
                ':id' => $shiftId,
            ]);

        $this->repo->appendAuditLog(
            $actorUserId,
            $newLocked ? 'SHIFT_LOCKED' : 'SHIFT_UNLOCKED',
            'shifts',
            (string) $shiftId,
            $reason,
            ['shift_code' => $shift['code'], 'is_locked' => $newLocked]
        );

        $this->json([
            'ok' => true,
            'is_locked' => $newLocked,
            'message_vi' => $newLocked
                ? sprintf('Đã khóa chốt ca [%s]. Mọi chỉnh sửa tiếp theo sẽ yêu cầu mở khóa.', $shift['code'])
                : sprintf('Đã mở khóa ca [%s] (Lý do kiểm toán: %s).', $shift['code'], $reason),
        ]);
    }

    private function runScheduler(string $role, ?int $actorUserId): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Chỉ Điều phối viên mới được chạy thuật toán.'], 403);
            return;
        }

        $actId = (int) ($_POST['activity_id'] ?? 3);
        $mode = (string) ($_POST['priority_mode'] ?? SchedulerWeights::MODE_MAX_COVERAGE);
        $baseWeights = SchedulerWeights::fromMode($mode, 202606);

        // Cho phép điều chỉnh trọng số tùy biến từ phòng Mô phỏng "Nếu - Thì"
        $weights = new SchedulerWeights(
            priorityMode: $mode,
            wPriority: isset($_POST['w_priority']) ? (float) $_POST['w_priority'] : $baseWeights->wPriority,
            wPreference: isset($_POST['w_preference']) ? (float) $_POST['w_preference'] : $baseWeights->wPreference,
            wSkillWaste: isset($_POST['w_skill_waste']) ? (float) $_POST['w_skill_waste'] : $baseWeights->wSkillWaste,
            wLoad: isset($_POST['w_load']) ? (float) $_POST['w_load'] : $baseWeights->wLoad,
            wAbsentRisk: 0.0,
            randomSeed: 202606
        );

        $ds = ($actId === 2) ? DatasetGenerator::createD2UnderstaffedDataset(2) : $this->repo->loadDatasetForActivity($actId);

        $planB0 = (new FifoUncheckedScheduler())->schedule($ds, 202606);
        $planB1 = (new FifoCheckedScheduler())->schedule($ds, 202606);
        $planX  = (new XanhHubScheduler())->schedule($ds, $weights);

        $this->repo->savePlanOutput($ds, $planB0, $actorUserId);
        $this->repo->savePlanOutput($ds, $planB1, $actorUserId);
        $planXId = $this->repo->savePlanOutput($ds, $planX, $actorUserId);

        $appliedMsg = '';
        if (!empty($_POST['apply_immediately'])) {
            $applyRes = $this->repo->applyPlanToAssignments($planXId, $actorUserId);
            $appliedMsg = ' · ' . $applyRes['message_vi'];
        }

        $explanation = $this->aiService->explainPlan($ds, $planX, $planB0, $planB1);

        $this->json([
            'ok' => true,
            'plan_x_id' => $planXId,
            'message_vi' => sprintf('Đã chạy thuật toán Xanh Hub (%s) trên bộ %s%s', $mode, $ds->datasetCode, $appliedMsg),
            'B0_metrics' => $planB0->metrics,
            'B1_metrics' => $planB1->metrics,
            'X_metrics'  => $planX->metrics,
            'explanation' => $explanation,
        ]);
    }

    private function suggestReplacements(string $role): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Chỉ Người phụ trách hoặc Quản trị viên mới có quyền tra cứu đề xuất người thay thế.'], 403);
            return;
        }

        $assignId = (int) ($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
        $stmt = $this->pdo->prepare('
            SELECT a.id, a.activity_id, a.shift_id, a.requirement_id, a.volunteer_id,
                   s.code AS shift_code, s.title AS shift_title, u.full_name AS vol_name, v.student_code
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
              JOIN users u ON u.id = a.volunteer_id
              JOIN volunteers v ON v.user_id = a.volunteer_id
             WHERE a.id = :id
        ');
        $stmt->execute([':id' => $assignId]);
        $info = $stmt->fetch();

        if (!is_array($info)) {
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy phân công cần tìm người thay.']);
            return;
        }

        $actId = (int) $info['activity_id'];
        $ds = $this->repo->loadDatasetForActivity($actId);
        $activeDecisions = array_values(array_filter(
            $this->repo->loadActiveAssignmentDecisions($actId),
            static fn($d) => !($d->shiftId === (int) $info['shift_id'] && $d->volunteerId === (int) $info['volunteer_id'])
        ));

        $candidates = (new ReplacementRanker())->rankCandidates(
            dataset: $ds,
            activeAssignments: $activeDecisions,
            shiftId: (int) $info['shift_id'],
            requirementId: $info['requirement_id'] !== null ? (int) $info['requirement_id'] : null,
            waitlistVolunteerIds: [],
            limit: 5
        );

        $this->json([
            'ok' => true,
            'target' => $info,
            'candidates' => $candidates,
        ]);
    }

    private function reportAbsence(string $role, ?int $actorUserId, ?int $currentVolunteerId = null): void
    {
        if ($role === 'PUBLIC') {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Vui lòng đăng nhập để báo vắng ca trực.'], 403);
            return;
        }

        $assignId = (int) ($_POST['assignment_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Bận đột xuất / Báo ốm'));
        $replaceVolId = isset($_POST['replacement_volunteer_id']) && $_POST['replacement_volunteer_id'] !== ''
            ? (int) $_POST['replacement_volunteer_id']
            : null;

        if ($role === 'VOLUNTEER') {
            $stmtChk = $this->pdo->prepare('SELECT volunteer_id FROM assignments WHERE id = :id LIMIT 1');
            $stmtChk->execute([':id' => $assignId]);
            $ownerVolId = (int) $stmtChk->fetchColumn();
            $expectedVolId = (int) ($currentVolunteerId ?? $actorUserId ?? 0);
            if ($ownerVolId <= 0 || $ownerVolId !== $expectedVolId) {
                $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Bạn chỉ được phép báo vắng cho ca trực của chính mình.'], 403);
                return;
            }
            $replaceVolId = null;
        }

        $res = $this->repo->reportAbsenceAndReplace($assignId, $reason, $replaceVolId, $actorUserId);
        $this->json($res, $res['ok'] ? 200 : 422);
    }

    private function qrRefresh(string $role): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Chỉ Người phụ trách tại điểm trực mới có quyền mở màn chiếu mã QR điểm danh.'], 403);
            return;
        }

        $shiftId = (int) ($_GET['shift_id'] ?? $_POST['shift_id'] ?? 201);
        $now = time();
        $token = $this->qrService->generateToken($shiftId, $now);
        $secondsRemaining = 30 - ($now % 30);
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $directUrl = "{$proto}://{$host}/?action=qr_scan_direct&token={$token}";

        $stmtShift = $this->pdo->prepare("
            SELECT s.id, s.code, s.title, s.location_name, s.max_people, a.title AS activity_title,
                   (SELECT COUNT(*) FROM assignments asm WHERE asm.shift_id = s.id AND asm.status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')) AS assigned_count
              FROM shifts s
              JOIN activities a ON a.id = s.activity_id
             WHERE s.id = :sid
             LIMIT 1
        ");
        $stmtShift->execute([':sid' => $shiftId]);
        $shiftInfo = $stmtShift->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->json([
            'ok' => true,
            'shift_id' => $shiftId,
            'shift_code' => $shiftInfo['code'] ?? ('CA-' . $shiftId),
            'shift_title' => $shiftInfo['title'] ?? 'Ca trực chiến dịch',
            'location_name' => $shiftInfo['location_name'] ?? 'TP. Hà Nội',
            'activity_title' => $shiftInfo['activity_title'] ?? 'Chiến dịch Xanh Hub',
            'assigned_count' => (int) ($shiftInfo['assigned_count'] ?? 0),
            'max_people' => (int) ($shiftInfo['max_people'] ?? 5),
            'token' => $token,
            'direct_url' => $directUrl,
            'seconds_remaining' => $secondsRemaining,
            'svg' => SvgQrGenerator::renderSvg($directUrl, 220),
        ]);
    }

    private function qrScan(string $role, ?int $actorUserId, ?int $currentVolunteerId = null): void
    {
        if ($role === 'PUBLIC') {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Vui lòng đăng nhập tài khoản để điểm danh ca trực.'], 403);
            return;
        }

        $expectedVolId = (int) ($currentVolunteerId ?? $actorUserId ?? 0);
        $rawInput = trim((string) ($_POST['token'] ?? $_POST['qr_token'] ?? ''));
        $assignId = (int) ($_POST['assignment_id'] ?? 0);
        $mode = (string) ($_POST['scan_mode'] ?? 'VALID'); // 'VALID' | 'EXPIRED' | 'WRONG_SHIFT'

        // Hỗ trợ quét mã QR của Giấy Chứng nhận Điện tử hoặc Báo cáo Tổng kết Đoàn
        $verifyCode = null;
        if (str_contains($rawInput, 'code=')) {
            $parsedUrl = parse_url($rawInput);
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $qParams);
                if (!empty($qParams['code'])) {
                    $verifyCode = strtoupper(trim((string) $qParams['code']));
                }
            }
        } elseif (preg_match('/^(XH\d{4}-[A-Z0-9-]+)$/i', $rawInput, $cm)) {
            $verifyCode = strtoupper($cm[1]);
        }

        if ($verifyCode !== null) {
            $this->json([
                'ok' => true,
                'scan_type' => 'VERIFY_CODE',
                'code' => $verifyCode,
                'redirect_url' => '?view=verify&code=' . urlencode($verifyCode),
                'message_vi' => "Đã giải mã Mã Chứng thực Điện tử {$verifyCode}! Đang mở hồ sơ xác thực...",
            ]);
            return;
        }

        // Phân tích nếu chuỗi là URL điểm danh (ví dụ: http://.../?action=qr_scan_direct&token=XHQR-...)
        $token = $rawInput;
        if (str_contains($rawInput, 'token=')) {
            $parsedUrl = parse_url($rawInput);
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $qParams);
                if (!empty($qParams['token'])) {
                    $token = trim((string) $qParams['token']);
                }
            }
        }

        // Nếu có token XHQR-... truyền vào: trích xuất shiftId từ token
        $tokenShiftId = null;
        if (preg_match('/^XHQR-(\d+)-(\d+)-([a-f0-9]{24})$/', trim($token), $m)) {
            $tokenShiftId = (int) $m[1];
        }

        // Nếu chưa có assignId nhưng có tokenShiftId, tự động tìm phân công của TNV vào ca này
        if ($assignId <= 0 && $tokenShiftId !== null && $role === 'VOLUNTEER') {
            $stmtFind = $this->pdo->prepare("
                SELECT id FROM assignments
                 WHERE shift_id = :sid AND volunteer_id = :vid
                 ORDER BY id DESC LIMIT 1
            ");
            $stmtFind->execute([':sid' => $tokenShiftId, ':vid' => $expectedVolId]);
            $foundAid = $stmtFind->fetchColumn();
            if ($foundAid) {
                $assignId = (int) $foundAid;
            }
        }

        // Trường hợp Điều phối viên / Admin quét thử QR ca trực trên máy chiếu (không truyền assignment_id)
        if ($assignId <= 0 && $tokenShiftId !== null && in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $stmtShift = $this->pdo->prepare("
                SELECT s.id, s.activity_id, s.code, s.title, s.location_name, s.max_people,
                       a.title AS activity_title,
                       EXTRACT(EPOCH FROM s.start_time)::bigint AS start_ep,
                       EXTRACT(EPOCH FROM s.end_time)::bigint AS end_ep,
                       (SELECT COUNT(*) FROM assignments asm WHERE asm.shift_id = s.id AND asm.status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')) AS assigned_count
                  FROM shifts s
                  JOIN activities a ON a.id = s.activity_id
                 WHERE s.id = :sid
                 LIMIT 1
            ");
            $stmtShift->execute([':sid' => $tokenShiftId]);
            $shiftRow = $stmtShift->fetch(PDO::FETCH_ASSOC);

            if (!is_array($shiftRow)) {
                $this->json(['ok' => false, 'message_vi' => "Không tìm thấy ca trực #{$tokenShiftId} trong hệ thống."]);
                return;
            }

            $liveNow = time();
            $verify = $this->qrService->verifyCheckin(
                token: $token,
                expectedShiftId: $tokenShiftId,
                shiftStartEpoch: $liveNow - 60,
                shiftEndEpoch: $liveNow + 60,
                isAssignedVolunteer: true,
                alreadyCheckedIn: false,
                nowEpoch: $liveNow
            );

            $this->json([
                'ok' => $verify['valid'],
                'scan_type' => 'COORDINATOR_PREVIEW',
                'error_code' => $verify['error_code'],
                'message_vi' => $verify['valid']
                    ? "Xác thực Mã QR Điểm danh Hợp lệ! Ca [{$shiftRow['code']}] {$shiftRow['title']} · Địa điểm: {$shiftRow['location_name']}"
                    : $verify['message_vi'],
                'volunteer_name' => 'Kiểm tra trực tiếp (Cán bộ Điều phối)',
                'shift_code' => $shiftRow['code'],
                'shift_title' => $shiftRow['title'],
                'location_name' => $shiftRow['location_name'],
                'activity_id' => (int) $shiftRow['activity_id'],
                'activity_title' => $shiftRow['activity_title'],
                'assigned_count' => (int) $shiftRow['assigned_count'],
                'max_people' => (int) $shiftRow['max_people'],
                'checked_in_at' => date('d/m/Y H:i:s'),
            ]);
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT a.id, a.shift_id, a.volunteer_id, a.status,
                   EXTRACT(EPOCH FROM s.start_time)::bigint AS start_ep,
                   EXTRACT(EPOCH FROM s.end_time)::bigint AS end_ep,
                   s.code AS shift_code, s.title AS shift_title, s.location_name, s.activity_id, u.full_name,
                   v.student_code
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
              JOIN users u ON u.id = a.volunteer_id
              LEFT JOIN volunteers v ON v.user_id = u.id
             WHERE a.id = :id
        ");
        $stmt->execute([':id' => $assignId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            if ($tokenShiftId !== null) {
                $stmtSh = $this->pdo->prepare('SELECT code, title, location_name FROM shifts WHERE id = :sid LIMIT 1');
                $stmtSh->execute([':sid' => $tokenShiftId]);
                $sh = $stmtSh->fetch(PDO::FETCH_ASSOC);
                if (is_array($sh)) {
                    $this->json([
                        'ok' => false,
                        'error_code' => 'NOT_ASSIGNED_TO_SHIFT',
                        'shift_code' => $sh['code'],
                        'shift_title' => $sh['title'],
                        'location_name' => $sh['location_name'],
                        'message_vi' => "Đã giải mã ca [{$sh['code']}] {$sh['title']} tại {$sh['location_name']}, nhưng tài khoản của bạn chưa được phân công vào ca trực này.",
                    ]);
                    return;
                }
            }
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy phân công ca trực tương ứng với mã QR. Vui lòng kiểm tra lại danh sách ca của bạn.']);
            return;
        }

        if ($role === 'VOLUNTEER') {
            if ((int) $row['volunteer_id'] !== $expectedVolId) {
                $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Bạn chỉ được phép điểm danh ca trực của chính mình.'], 403);
                return;
            }
        }

        $shiftId = (int) $row['shift_id'];
        $startEp = (int) $row['start_ep'];
        $endEp = (int) $row['end_ep'];
        $simNow = $startEp + 600; // Giả lập đồng hồ nằm trong ca trực

        if ($token === '' || $token === 'QUICK_CHECKIN') {
            if ($mode === 'EXPIRED') {
                // Tạo mã QR của 60 giây trước để thử kịch bản hết hạn 30 giây
                $token = $this->qrService->generateToken($shiftId, $simNow - 60);
            } elseif ($mode === 'WRONG_SHIFT') {
                $token = $this->qrService->generateToken($shiftId + 1, $simNow);
            } else {
                $token = $this->qrService->generateToken($shiftId, $simNow);
            }
        }

        $already = ((string) $row['status'] === 'CHECKED_IN');
        $verify = $this->qrService->verifyCheckin(
            token: $token,
            expectedShiftId: $shiftId,
            shiftStartEpoch: $startEp,
            shiftEndEpoch: $endEp,
            isAssignedVolunteer: true,
            alreadyCheckedIn: $already,
            nowEpoch: $simNow
        );

        // Nếu không hợp lệ do lệch ca mô phỏng nhưng khớp với mã xoay vòng live 30 giây hiện tại từ máy chiếu:
        if (!$verify['valid'] && in_array($verify['error_code'], ['QR_TOKEN_EXPIRED', 'OUTSIDE_CHECKIN_WINDOW'], true)) {
            $liveNow = time();
            $liveCheck = $this->qrService->verifyCheckin(
                token: $token,
                expectedShiftId: $shiftId,
                shiftStartEpoch: $liveNow - 60,
                shiftEndEpoch: $liveNow + 60,
                isAssignedVolunteer: true,
                alreadyCheckedIn: $already,
                nowEpoch: $liveNow
            );
            if ($liveCheck['valid']) {
                $verify = $liveCheck;
            }
        }

        if ($verify['valid']) {
            $this->pdo->prepare("UPDATE assignments SET status = 'CHECKED_IN' WHERE id = :id")->execute([':id' => $assignId]);
            $this->pdo->prepare("
                INSERT INTO checkins (assignment_id, method, verified_token_hash)
                VALUES (:aid, 'QR_HMAC', :thash)
                ON CONFLICT (assignment_id) DO NOTHING
            ")->execute([':aid' => $assignId, ':thash' => $verify['token_hash']]);
            $this->repo->appendAuditLog($actorUserId, 'QR_CHECKIN_SUCCESS', 'checkins', (string) $assignId, 'Điểm danh QR 30s thành công');
        }

        $this->json([
            'ok' => $verify['valid'],
            'scan_type' => 'CHECKIN_SUCCESS',
            'error_code' => $verify['error_code'],
            'message_vi' => $verify['message_vi'],
            'volunteer_name' => $row['full_name'],
            'student_code' => $row['student_code'] ?? '',
            'shift_code' => $row['shift_code'],
            'shift_title' => $row['shift_title'] ?? $row['shift_code'],
            'location_name' => $row['location_name'] ?? '',
            'activity_id' => (int) ($row['activity_id'] ?? 3),
            'checked_in_at' => date('d/m/Y H:i:s'),
        ]);
    }

    private function aiParseShift(string $role): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Chỉ Người phụ trách hoặc Quản trị viên mới được phép sử dụng công cụ tạo ca nhanh.'], 403);
            return;
        }

        $prompt = trim((string) ($_POST['shift_prompt'] ?? $_POST['prompt'] ?? ''));
        $draft = $this->aiService->parseNaturalLanguageShift($prompt);
        $this->json([
            'ok' => true,
            'draft' => $draft,
        ]);
    }

    private function updateAvailability(string $role, int $currentVolunteerId, ?int $actorUserId): void
    {
        if ($role === 'PUBLIC') {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Vui lòng đăng nhập để cập nhật lịch rảnh.'], 403);
            return;
        }

        if ($role === 'VOLUNTEER' && isset($_POST['volunteer_id']) && (int) $_POST['volunteer_id'] !== $currentVolunteerId) {
            $this->json(['ok' => false, 'message_vi' => 'Lỗi 403: Bạn chỉ được phép cập nhật lịch rảnh của chính mình.'], 403);
            return;
        }

        $volId = ($role === 'VOLUNTEER')
            ? $currentVolunteerId
            : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
        $overrideDate = trim((string) ($_POST['override_date'] ?? ''));
        $dayOfWeek = max(1, min(7, (int) ($_POST['day_of_week'] ?? 6)));
        $startHm = substr(trim((string) ($_POST['start_hm'] ?? '07:00')), 0, 5);
        $endHm = substr(trim((string) ($_POST['end_hm'] ?? '18:00')), 0, 5);
        $isAvailable = !isset($_POST['is_available']) || (string) $_POST['is_available'] !== '0';

        if ($endHm <= $startHm) {
            $this->json([
                'ok' => false,
                'message_vi' => 'Giờ kết thúc phải lớn hơn giờ bắt đầu.',
            ], 400);
            return;
        }

        if ($overrideDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
            $this->pdo->prepare('DELETE FROM availability_overrides WHERE volunteer_id = :vid AND override_date = :od')
                ->execute([':vid' => $volId, ':od' => $overrideDate]);

            $this->pdo->prepare('
                INSERT INTO availability_overrides (volunteer_id, override_date, start_time, end_time, is_available, reason)
                VALUES (:vid, :od, :st, :et, :av, :rsn)
            ')->execute([
                ':vid' => $volId,
                ':od' => $overrideDate,
                ':st' => $startHm . ':00',
                ':et' => $endHm . ':00',
                ':av' => $isAvailable ? 'true' : 'false',
                ':rsn' => $isAvailable ? 'Đăng ký ngày rảnh cụ thể' : 'Báo bận theo ngày cụ thể',
            ]);

            $fmtDate = date('d/m/Y', strtotime($overrideDate));
            $this->repo->appendAuditLog(
                $actorUserId ?? $volId,
                'AVAILABILITY_DATE_UPDATED',
                'availability_overrides',
                "{$volId}_{$overrideDate}",
                "Cập nhật lịch theo ngày {$fmtDate} ({$startHm}-{$endHm})"
            );

            $this->json([
                'ok' => true,
                'message_vi' => "Đã lưu lịch ngày {$fmtDate} ({$startHm} – {$endHm}) thành công!",
            ]);
            return;
        }

        $this->pdo->prepare('DELETE FROM availability WHERE volunteer_id = :vid AND day_of_week = :dow')
            ->execute([':vid' => $volId, ':dow' => $dayOfWeek]);

        $this->pdo->prepare('
            INSERT INTO availability (volunteer_id, day_of_week, start_time, end_time, is_recurring)
            VALUES (:vid, :dow, :st, :et, TRUE)
        ')->execute([
            ':vid' => $volId,
            ':dow' => $dayOfWeek,
            ':st' => $startHm . ':00',
            ':et' => $endHm . ':00',
        ]);

        $this->repo->appendAuditLog($actorUserId ?? $volId, 'AVAILABILITY_UPDATED', 'availability', (string) $volId, "Cập nhật lịch rảnh thứ {$dayOfWeek}: {$startHm}-{$endHm}");

        $this->json([
            'ok' => true,
            'message_vi' => "Đã cập nhật khung lịch rảnh ngày thứ {$dayOfWeek} ({$startHm} – {$endHm}) thành công!",
        ]);
    }

    private function analyticsData(string $role): void
    {
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
            $this->json([
                'ok' => false,
                'message_vi' => 'Lỗi 403: Dữ liệu Thống kê & Báo cáo chỉ dành cho Người phụ trách (Ban Chỉ huy) và Quản trị viên.',
            ], 403);
            return;
        }
        $actId = (int) ($_GET['activity_id'] ?? $_POST['activity_id'] ?? 3);
        $analytics = new \XanhHub\Infrastructure\Service\AnalyticsService($this->pdo);
        $this->json([
            'ok' => true,
            'report' => $analytics->getCampaignFormalReport($actId),
        ]);
    }

    private function shiftsGis(): void
    {
        $gisService = new \XanhHub\Infrastructure\Service\GisService($this->pdo);

        $actIdRaw = $_GET['activity_id'] ?? ($_POST['activity_id'] ?? null);
        $actId = ($actIdRaw !== null && $actIdRaw !== '' && strtoupper((string) $actIdRaw) !== 'ALL')
            ? (int) $actIdRaw
            : null;

        $userLatRaw = $_GET['lat'] ?? ($_POST['lat'] ?? null);
        $userLngRaw = $_GET['lng'] ?? ($_POST['lng'] ?? null);
        $userLat = ($userLatRaw !== null && is_numeric($userLatRaw)) ? (float) $userLatRaw : null;
        $userLng = ($userLngRaw !== null && is_numeric($userLngRaw)) ? (float) $userLngRaw : null;

        $radiusRaw = $_GET['radius'] ?? ($_POST['radius'] ?? null);
        $radiusKm = ($radiusRaw !== null && is_numeric($radiusRaw)) ? (float) $radiusRaw : null;

        $skillFilter = $_GET['skill'] ?? ($_POST['skill'] ?? null);
        $skillFilter = is_string($skillFilter) ? trim($skillFilter) : null;

        $payload = $gisService->getShiftsGisPayload($actId, $userLat, $userLng, $radiusKm, $skillFilter);
        $this->json($payload);
    }

    private function getGamificationService(): GamificationService
    {
        return $this->gamificationService ?? new GamificationService($this->pdo);
    }

    private function submitEvaluation(string $role, ?int $actorUserId, ?int $currentVolunteerId = null): void
    {
        $input = $_POST;
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = array_merge($input, $json);
            }
        }

        $evalType = strtoupper(trim((string) ($input['eval_type'] ?? 'COORDINATOR_TO_VOLUNTEER')));
        $assignmentId = (int) ($input['assignment_id'] ?? 0);
        $comment = trim((string) ($input['comment'] ?? ($input['feedback'] ?? '')));

        $gamification = $this->getGamificationService();

        if ($evalType === 'COORDINATOR_TO_VOLUNTEER') {
            if (!in_array($role, ['COORDINATOR', 'ADMIN'], true)) {
                $this->json([
                    'ok' => false,
                    'message_vi' => 'Lỗi 403: Chỉ Điều phối viên hoặc Quản trị viên mới có quyền đánh giá tình nguyện viên.',
                ], 403);
                return;
            }

            $volId = (int) ($input['volunteer_id'] ?? 0);
            if ($volId <= 0 && $assignmentId > 0) {
                try {
                    $stmt = $this->pdo->prepare('SELECT volunteer_id FROM assignments WHERE id = :aid LIMIT 1');
                    $stmt->execute([':aid' => $assignmentId]);
                    $volId = (int) $stmt->fetchColumn();
                } catch (\Throwable) {
                    $volId = 1;
                }
            }
            if ($volId <= 0) {
                $volId = 1;
            }

            $diligence = (int) ($input['punctuality_rating'] ?? ($input['criteria_diligence'] ?? ($input['diligence'] ?? 5)));
            $competence = (int) ($input['skill_rating'] ?? ($input['criteria_competence'] ?? ($input['competence'] ?? 5)));
            $demeanor = (int) ($input['conduct_rating'] ?? ($input['criteria_demeanor'] ?? ($input['demeanor'] ?? 5)));

            if ($diligence < 1 || $diligence > 5 || $competence < 1 || $competence > 5 || $demeanor < 1 || $demeanor > 5) {
                $this->json([
                    'ok' => false,
                    'message_vi' => 'Lỗi: Điểm đánh giá các tiêu chí phải nằm trong khoảng từ 1 đến 5 sao.',
                ], 400);
                return;
            }

            $coordId = $actorUserId ?? 31;
            $res = $gamification->submitCoordinatorEvaluation(
                $assignmentId,
                $coordId,
                $volId,
                $diligence,
                $competence,
                $demeanor,
                $comment
            );

            if (empty($res['ok'])) {
                $this->json([
                    'ok' => false,
                    'message_vi' => $res['error'] ?? 'Lỗi gửi đánh giá tình nguyện viên.',
                    'error_code' => $res['error_code'] ?? 'EVAL_FAILED',
                ], 400);
                return;
            }

            $this->json([
                'ok' => true,
                'message_vi' => 'Đã ghi nhận đánh giá 360° thành công!',
                'evaluation_id' => $res['evaluation_id'],
                'eval_type' => 'COORDINATOR_TO_VOLUNTEER',
                'avg_rating' => $res['average_score'],
                'average_score' => $res['average_score'],
                'badges_unlocked' => $res['badges_unlocked'] ?? [],
            ]);
            return;
        }

        if ($evalType === 'VOLUNTEER_TO_SHIFT') {
            if ($role === 'PUBLIC') {
                $this->json([
                    'ok' => false,
                    'message_vi' => 'Lỗi 403: Vui lòng đăng nhập để gửi đánh giá ca trực.',
                ], 403);
                return;
            }

            $volUserId = $actorUserId ?? $currentVolunteerId;
            $shiftId = (int) ($input['shift_id'] ?? 0);
            if ($assignmentId > 0) {
                try {
                    $stmt = $this->pdo->prepare('SELECT shift_id, volunteer_id FROM assignments WHERE id = :aid LIMIT 1');
                    $stmt->execute([':aid' => $assignmentId]);
                    $assignRow = $stmt->fetch();
                    if (is_array($assignRow)) {
                        if ($role === 'VOLUNTEER' && (int) $assignRow['volunteer_id'] !== (int) ($currentVolunteerId ?? $actorUserId ?? 0)) {
                            $this->json([
                                'ok' => false,
                                'message_vi' => 'Lỗi 403: Bạn chỉ được phép đánh giá ca trực mà mình đã tham gia.',
                            ], 403);
                            return;
                        }
                        if ($shiftId <= 0) {
                            $shiftId = (int) $assignRow['shift_id'];
                        }
                    }
                } catch (\Throwable) {
                    if ($shiftId <= 0) {
                        $shiftId = 101;
                    }
                }
            }
            if ($shiftId <= 0) {
                $shiftId = 101;
            }

            $logistics = (int) ($input['logistics_rating'] ?? ($input['criteria_logistics'] ?? ($input['logistics'] ?? 5)));
            $safety = (int) ($input['safety_rating'] ?? ($input['criteria_safety'] ?? ($input['safety'] ?? 5)));
            $support = (int) ($input['support_rating'] ?? ($input['criteria_support'] ?? ($input['support'] ?? 5)));

            if ($logistics < 1 || $logistics > 5 || $safety < 1 || $safety > 5 || $support < 1 || $support > 5) {
                $this->json([
                    'ok' => false,
                    'message_vi' => 'Lỗi: Điểm đánh giá trải nghiệm ca trực phải nằm trong khoảng từ 1 đến 5 sao.',
                ], 400);
                return;
            }

            $res = $gamification->submitVolunteerShiftEvaluation(
                $assignmentId,
                $volUserId,
                $shiftId,
                $logistics,
                $safety,
                $support,
                $comment
            );

            if (empty($res['ok'])) {
                $this->json([
                    'ok' => false,
                    'message_vi' => $res['error'] ?? 'Lỗi gửi đánh giá ca trực.',
                    'error_code' => $res['error_code'] ?? 'EVAL_FAILED',
                ], 400);
                return;
            }

            $this->json([
                'ok' => true,
                'message_vi' => 'Cảm ơn bạn đã gửi đánh giá trải nghiệm ca trực!',
                'evaluation_id' => $res['evaluation_id'],
                'eval_type' => 'VOLUNTEER_TO_SHIFT',
                'avg_rating' => $res['average_score'],
                'average_score' => $res['average_score'],
                'badges_unlocked' => [],
            ]);
            return;
        }

        $this->json([
            'ok' => false,
            'message_vi' => 'Loại đánh giá không hợp lệ: ' . $evalType,
        ], 400);
    }

    private function candidateProfile(string $role, ?int $currentVolunteerId = null): void
    {
        $volId = isset($_GET['volunteer_id']) ? (int) $_GET['volunteer_id'] : (isset($_POST['volunteer_id']) ? (int) $_POST['volunteer_id'] : 0);
        if ($volId <= 0) {
            $this->json(['ok' => false, 'message_vi' => 'Thiếu mã tình nguyện viên (volunteer_id)'], 400);
            return;
        }
        if (!in_array($role, ['COORDINATOR', 'ADMIN'], true) && $volId !== (int) $currentVolunteerId) {
            $this->json([
                'ok' => false,
                'message_vi' => 'Lỗi 403: Chỉ Người phụ trách hoặc Quản trị viên mới có quyền tra cứu hồ sơ chi tiết của ứng viên khác.',
            ], 403);
            return;
        }

        $stmt = $this->pdo->prepare('
            SELECT u.id, u.email, u.full_name, u.role, u.created_at,
                   v.student_code, v.chapter_name, v.phone, v.max_shifts_per_week, v.lat, v.lng
              FROM users u
              JOIN volunteers v ON v.user_id = u.id
             WHERE v.user_id = :uid
             LIMIT 1
        ');
        $stmt->execute([':uid' => $volId]);
        $volRow = $stmt->fetch();
        if (!$volRow) {
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy tình nguyện viên #' . $volId], 404);
            return;
        }

        $stmtSkills = $this->pdo->prepare('
            SELECT s.id AS skill_id, s.code, s.name, s.description,
                   COALESCE(vs.level, 1) AS level,
                   COALESCE(vs.is_verified, FALSE) AS is_verified
              FROM skills s
              JOIN volunteer_skills vs ON vs.skill_id = s.id AND vs.volunteer_id = :uid
             ORDER BY s.id ASC
        ');
        $stmtSkills->execute([':uid' => $volId]);
        $skills = $stmtSkills->fetchAll();

        $stmtAvail = $this->pdo->prepare('
            SELECT day_of_week,
                   to_char(start_time, \'HH24:MI\') AS start_hm,
                   to_char(end_time, \'HH24:MI\') AS end_hm
              FROM availability
             WHERE volunteer_id = :uid
             ORDER BY day_of_week ASC, start_time ASC
        ');
        $stmtAvail->execute([':uid' => $volId]);
        $rawAvail = $stmtAvail->fetchAll();
        $dayNames = [
            1 => 'Thứ 2',
            2 => 'Thứ 3',
            3 => 'Thứ 4',
            4 => 'Thứ 5',
            5 => 'Thứ 6',
            6 => 'Thứ 7',
            7 => 'Chủ Nhật',
        ];
        $availability = [];
        foreach ($rawAvail as $av) {
            $dow = (int) $av['day_of_week'];
            $availability[] = [
                'day_of_week' => $dow,
                'day_name' => $dayNames[$dow] ?? ('Thứ ' . ($dow + 1)),
                'start_hm' => $av['start_hm'],
                'end_hm' => $av['end_hm'],
            ];
        }

        $stmtShifts = $this->pdo->prepare('
            SELECT a.id AS assignment_id, a.shift_id, a.status,
                   s.code AS shift_code, s.title, s.location_name,
                   s.start_time::text AS start_iso, s.end_time::text AS end_iso
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
             WHERE a.volunteer_id = :uid
               AND a.status IN (\'ASSIGNED\', \'CONFIRMED\', \'CHECKED_IN\')
             ORDER BY s.start_time ASC
        ');
        $stmtShifts->execute([':uid' => $volId]);
        $assignedShifts = $stmtShifts->fetchAll();

        $gamification = $this->getGamificationService()->calculateVolunteerMerit($volId);

        $avatarUrl = \XanhHub\Http\MonogramAvatar::dataUriBase64($volId, (string) $volRow['full_name']);

        $volData = [
            'id' => (int) $volRow['id'],
            'full_name' => (string) $volRow['full_name'],
            'student_code' => (string) $volRow['student_code'],
            'chapter_name' => (string) $volRow['chapter_name'],
            'email' => (string) $volRow['email'],
            'phone' => (string) ($volRow['phone'] ?? ''),
            'max_shifts_per_week' => (int) $volRow['max_shifts_per_week'],
            'lat' => (float) $volRow['lat'],
            'lng' => (float) $volRow['lng'],
            'avatar_url' => $avatarUrl,
        ];

        $gamificationData = [
            'total_hours' => $gamification['total_hours'],
            'completed_shifts' => (int) ($gamification['on_time_checkins'] ?? $gamification['assigned_count'] ?? 0),
            'avg_rating' => $gamification['avg_rating'],
            'reputation_score' => $gamification['reputation_score'],
            'xp' => $gamification['xp'],
            'unlocked_badge_count' => $gamification['unlocked_badge_count'],
            'badges' => $gamification['badges'],
        ];

        $this->json([
            'ok' => true,
            'data' => [
                'volunteer' => $volData,
                'skills' => $skills,
                'availability' => $availability,
                'assigned_shifts' => $assignedShifts,
                'gamification' => $gamificationData,
            ],
            'volunteer' => $volData,
            'skills' => $skills,
            'availability' => $availability,
            'assigned_shifts' => $assignedShifts,
            'gamification' => $gamificationData,
        ]);
    }

    private function leaderboardData(): void
    {
        $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 10;
        $gamification = $this->getGamificationService();
        $data = $gamification->getLeaderboardData($limit);

        $this->json([
            'ok' => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'top_volunteers' => $data['top_volunteers'],
            'personal_top10' => $data['personal_top10'],
            'collectives' => $data['collective_faculties'],
            'collective_faculties' => $data['collective_faculties'],
            'badges_meta' => $data['badges_meta'],
            'system_summary' => $data['system_summary'],
        ]);
    }

    private function placesAutocomplete(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $refLat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
        $refLng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min(20, (int) $_GET['limit'])) : 8;

        $geo = new GeocodingService($this->pdo);
        $places = $geo->autocomplete($q, $refLat, $refLng, $limit);

        $this->json([
            'ok' => true,
            'query' => $q,
            'total' => count($places),
            'places' => $places,
        ]);
    }

    private function reverseGeocode(): void
    {
        $lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
        $lng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;

        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $this->json([
                'ok' => false,
                'message_vi' => 'Tọa độ GPS (lat, lng) không hợp lệ.',
            ], 400);
            return;
        }

        $geo = new GeocodingService($this->pdo);
        $allowRemote = ($_GET['offline'] ?? '') !== '1';
        $res = $geo->reverseGeocode($lat, $lng, $allowRemote);
        $this->json($res);
    }

    private function globalSearch(): void
    {
        $rawQ = trim((string) ($_GET['q'] ?? ''));
        $qNorm = GeocodingService::normalizeVietnamese($rawQ);
        $tokens = $qNorm !== '' ? array_values(array_filter(explode(' ', $qNorm), static fn ($t) => $t !== '')) : [];
        $items = [];

        $matchesTokens = static function (string ...$fields) use ($tokens): bool {
            if ($tokens === []) {
                return true;
            }
            $haystack = GeocodingService::normalizeVietnamese(implode(' ', $fields));
            foreach ($tokens as $tok) {
                if (!str_contains($haystack, $tok)) {
                    return false;
                }
            }
            return true;
        };

        // 1. Chiến dịch thực tế từ bảng activities
        $stmtActs = $this->pdo->query("
            SELECT a.id, a.code, a.title, a.location_name, a.status,
                   (SELECT COUNT(*) FROM shifts s WHERE s.activity_id = a.id) AS shifts_count
              FROM activities a
             ORDER BY a.id DESC
        ");
        foreach ($stmtActs->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if ($matchesTokens((string) $a['code'], (string) $a['title'], (string) $a['location_name'])) {
                $items[] = [
                    'category' => 'Chiến dịch',
                    'title' => (string) $a['title'],
                    'sub' => "{$a['code']} · {$a['location_name']} · {$a['shifts_count']} ca trực",
                    'url' => '?view=campaigns&activity_id=' . (int) $a['id'],
                    'icon' => 'campaign',
                    'badge' => (string) $a['code'],
                    'priority' => $tokens !== [] ? 100 : 90,
                ];
            }
        }

        // 2. Ca trực thực tế từ bảng shifts
        $stmtShifts = $this->pdo->query("
            SELECT s.id, s.activity_id, s.code, s.title, s.location_name, s.max_people,
                   a.code AS act_code,
                   COALESCE(sk.name, 'Tình nguyện') AS skill_name,
                   COALESCE(sk.code, '') AS skill_code,
                   (SELECT COUNT(*) FROM assignments asm WHERE asm.shift_id = s.id AND asm.status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')) AS assigned_count
              FROM shifts s
              JOIN activities a ON a.id = s.activity_id
              LEFT JOIN shift_requirements sr ON sr.id = (SELECT MIN(sr2.id) FROM shift_requirements sr2 WHERE sr2.shift_id = s.id)
              LEFT JOIN skills sk ON sk.id = sr.skill_id
             ORDER BY s.priority DESC, s.id ASC
        ");
        $shiftMatches = 0;
        foreach ($stmtShifts->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ($matchesTokens((string) $s['code'], (string) $s['title'], (string) $s['location_name'], (string) $s['skill_name'], (string) $s['skill_code'], (string) $s['act_code'])) {
                $items[] = [
                    'category' => 'Ca trực',
                    'title' => "[{$s['code']}] {$s['title']}",
                    'sub' => "{$s['location_name']} · {$s['skill_name']} ({$s['assigned_count']}/{$s['max_people']} TNV)",
                    'url' => '?view=shift_detail&shift_id=' . (int) $s['id'] . '&activity_id=' . (int) $s['activity_id'],
                    'icon' => 'event_available',
                    'badge' => (string) $s['act_code'],
                    'priority' => $tokens !== [] ? 95 : 80,
                ];
                $shiftMatches++;
                if ($tokens === [] && $shiftMatches >= 3) {
                    break;
                }
            }
        }

        // 3. Bản tin thực tế từ bảng news
        try {
            $stmtNews = $this->pdo->query("
                SELECT id, title, tag, summary, published_at
                  FROM news
                 WHERE status = 'PUBLISHED'
                 ORDER BY published_at DESC, id DESC
            ");
            foreach ($stmtNews->fetchAll(PDO::FETCH_ASSOC) as $n) {
                if ($matchesTokens((string) $n['title'], (string) $n['tag'], (string) $n['summary'])) {
                    $items[] = [
                        'category' => 'Bản tin',
                        'title' => (string) $n['title'],
                        'sub' => "{$n['tag']} · Ngày đăng " . date('d/m/Y', strtotime((string) $n['published_at'])),
                        'url' => '?view=news&news_id=' . (int) $n['id'],
                        'icon' => 'newspaper',
                        'badge' => (string) $n['tag'],
                        'priority' => 70,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        // 4. Đoàn viên & Giấy chứng nhận thực tế (khi có từ khóa tìm kiếm)
        if ($tokens !== []) {
            $stmtVols = $this->pdo->query("
                SELECT u.id, u.full_name, v.student_code, v.chapter_name,
                       (SELECT c.certificate_code FROM certificates c WHERE c.volunteer_id = u.id ORDER BY c.id DESC LIMIT 1) AS cert_code
                  FROM users u
                  JOIN volunteers v ON v.user_id = u.id
                 ORDER BY u.id ASC
            ");
            foreach ($stmtVols->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $certCode = (string) ($v['cert_code'] ?? '');
                if ($matchesTokens((string) $v['full_name'], (string) $v['student_code'], (string) $v['chapter_name'], $certCode)) {
                    $url = $certCode !== ''
                        ? '?view=verify&code=' . urlencode($certCode)
                        : '?view=leaderboard&q=' . urlencode((string) $v['student_code']);
                    $items[] = [
                        'category' => $certCode !== '' ? 'Chứng nhận Số' : 'Đoàn viên',
                        'title' => "{$v['full_name']} ({$v['student_code']})",
                        'sub' => $certCode !== '' ? "{$v['chapter_name']} · Mã CN: {$certCode}" : (string) $v['chapter_name'],
                        'url' => $url,
                        'icon' => $certCode !== '' ? 'workspace_premium' : 'badge',
                        'badge' => (string) $v['student_code'],
                        'priority' => 75,
                    ];
                }
            }

            // 5. Địa điểm thực tế trên Bản đồ Số GIS
            $geo = new GeocodingService($this->pdo);
            foreach ($geo->autocomplete($rawQ, null, null, 3) as $pl) {
                $items[] = [
                    'category' => 'Bản đồ GIS',
                    'title' => $pl['main_text'],
                    'sub' => $pl['secondary_text'],
                    'url' => sprintf('?view=map&lat=%.5f&lng=%.5f&place=%s', $pl['lat'], $pl['lng'], urlencode($pl['main_text'])),
                    'icon' => 'location_on',
                    'badge' => 'GPS',
                    'priority' => 65,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
        $topItems = array_slice($items, 0, 8);

        $this->json([
            'ok' => true,
            'query' => $rawQ,
            'total' => count($items),
            'results' => $topItems,
        ]);
    }

    private function translateBatch(): void
    {
        $rawBody = file_get_contents('php://input');
        $json = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;

        $target = is_array($json) && isset($json['target'])
            ? (string) $json['target']
            : (string) ($_POST['target'] ?? $_GET['target'] ?? 'en');
        $source = is_array($json) && isset($json['source'])
            ? (string) $json['source']
            : (string) ($_POST['source'] ?? $_GET['source'] ?? 'vi');

        $texts = [];
        if (is_array($json) && isset($json['texts']) && is_array($json['texts'])) {
            $texts = $json['texts'];
        } elseif (isset($_POST['texts']) && is_array($_POST['texts'])) {
            $texts = $_POST['texts'];
        } elseif (isset($_POST['texts']) && is_string($_POST['texts'])) {
            $decoded = json_decode($_POST['texts'], true);
            if (is_array($decoded)) {
                $texts = $decoded;
            }
        }

        // Giới hạn tối đa 250 chuỗi / request để tránh quá tải
        $texts = array_slice(array_values(array_filter($texts, static fn ($v) => is_string($v) && trim($v) !== '')), 0, 250);

        $svc = new TranslationService();
        $map = $svc->translateBatch($texts, $target, $source);

        $this->json([
            'ok' => true,
            'target' => $target,
            'count' => count($map),
            'translations' => $map,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

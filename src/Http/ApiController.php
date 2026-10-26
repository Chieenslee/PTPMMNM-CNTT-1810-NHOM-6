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
use XanhHub\Infrastructure\Service\QrCheckinService;

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
                'api_suggest_replacements' => $this->suggestReplacements(),
                'api_report_absence'       => $this->reportAbsence($actorUserId),
                'api_qr_refresh'           => $this->qrRefresh(),
                'api_qr_scan'              => $this->qrScan($actorUserId),
                'api_ai_parse_shift'       => $this->aiParseShift(),
                'api_update_availability'  => $this->updateAvailability((int) ($currentVolunteerId ?? $actorUserId ?? 1), $actorUserId),
                'api_analytics_data'       => $this->analyticsData(),
                'api_shifts_gis'           => $this->shiftsGis(),
                'api_candidate_profile'    => $this->candidateProfile(),
                'api_submit_evaluation'    => $this->submitEvaluation($currentRole, $actorUserId, $currentVolunteerId),
                'api_leaderboard_data'     => $this->leaderboardData(),
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

    private function suggestReplacements(): void
    {
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

    private function reportAbsence(?int $actorUserId): void
    {
        $assignId = (int) ($_POST['assignment_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Bận đột xuất / Báo ốm'));
        $replaceVolId = isset($_POST['replacement_volunteer_id']) && $_POST['replacement_volunteer_id'] !== ''
            ? (int) $_POST['replacement_volunteer_id']
            : null;

        $res = $this->repo->reportAbsenceAndReplace($assignId, $reason, $replaceVolId, $actorUserId);
        $this->json($res, $res['ok'] ? 200 : 422);
    }

    private function qrRefresh(): void
    {
        $shiftId = (int) ($_GET['shift_id'] ?? $_POST['shift_id'] ?? 201);
        $now = time();
        $token = $this->qrService->generateToken($shiftId, $now);
        $secondsRemaining = 30 - ($now % 30);

        $this->json([
            'ok' => true,
            'shift_id' => $shiftId,
            'token' => $token,
            'seconds_remaining' => $secondsRemaining,
            'svg' => SvgQrGenerator::renderSvg($token, 190),
        ]);
    }

    private function qrScan(?int $actorUserId): void
    {
        $assignId = (int) ($_POST['assignment_id'] ?? 0);
        $mode = (string) ($_POST['scan_mode'] ?? 'VALID'); // 'VALID' | 'EXPIRED' | 'WRONG_SHIFT'

        $stmt = $this->pdo->prepare("
            SELECT a.id, a.shift_id, a.volunteer_id, a.status,
                   EXTRACT(EPOCH FROM s.start_time)::bigint AS start_ep,
                   EXTRACT(EPOCH FROM s.end_time)::bigint AS end_ep,
                   s.code AS shift_code, u.full_name
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
              JOIN users u ON u.id = a.volunteer_id
             WHERE a.id = :id
        ");
        $stmt->execute([':id' => $assignId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            $this->json(['ok' => false, 'message_vi' => 'Không tìm thấy phân công để quét QR.']);
            return;
        }

        $shiftId = (int) $row['shift_id'];
        $startEp = (int) $row['start_ep'];
        $endEp = (int) $row['end_ep'];
        $simNow = $startEp + 600; // Giả lập đồng hồ nằm trong ca trực

        if ($mode === 'EXPIRED') {
            // Tạo mã QR của 60 giây trước để thử kịch bản hết hạn 30 giây
            $token = $this->qrService->generateToken($shiftId, $simNow - 60);
        } elseif ($mode === 'WRONG_SHIFT') {
            $token = $this->qrService->generateToken($shiftId + 1, $simNow);
        } else {
            $token = $this->qrService->generateToken($shiftId, $simNow);
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

        if ($verify['valid']) {
            $this->pdo->prepare("UPDATE assignments SET status = 'CHECKED_IN' WHERE id = :id")->execute([':id' => $assignId]);
            $this->pdo->prepare("
                INSERT INTO checkins (assignment_id, method, verified_token_hash)
                VALUES (:aid, 'QR_HMAC', :thash)
                ON CONFLICT (assignment_id) DO NOTHING
            ")->execute([':aid' => $assignId, ':thash' => $verify['token_hash']]);
            $this->repo->appendAuditLog($actorUserId, 'QR_CHECKIN_SUCCESS', 'checkins', (string) $assignId, 'Điểm danh QR HMAC 30s thành công');
        }

        $this->json([
            'ok' => $verify['valid'],
            'error_code' => $verify['error_code'],
            'message_vi' => $verify['message_vi'],
            'volunteer_name' => $row['full_name'],
            'shift_code' => $row['shift_code'],
        ]);
    }

    private function aiParseShift(): void
    {
        $prompt = (string) ($_POST['shift_prompt'] ?? '');
        $draft = $this->aiService->parseNaturalLanguageShift($prompt);
        $this->json([
            'ok' => true,
            'draft' => $draft,
        ]);
    }

    private function updateAvailability(int $currentVolunteerId, ?int $actorUserId): void
    {
        $volId = (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
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

    private function analyticsData(): void
    {
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
            $volUserId = $actorUserId ?? $currentVolunteerId;
            $shiftId = (int) ($input['shift_id'] ?? 0);
            if ($shiftId <= 0 && $assignmentId > 0) {
                try {
                    $stmt = $this->pdo->prepare('SELECT shift_id FROM assignments WHERE id = :aid LIMIT 1');
                    $stmt->execute([':aid' => $assignmentId]);
                    $shiftId = (int) $stmt->fetchColumn();
                } catch (\Throwable) {
                    $shiftId = 101;
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

    private function candidateProfile(): void
    {
        $volId = isset($_GET['volunteer_id']) ? (int) $_GET['volunteer_id'] : (isset($_POST['volunteer_id']) ? (int) $_POST['volunteer_id'] : 0);
        if ($volId <= 0) {
            $this->json(['ok' => false, 'message_vi' => 'Thiếu mã tình nguyện viên (volunteer_id)'], 400);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

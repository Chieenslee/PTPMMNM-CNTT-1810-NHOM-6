<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Persistence;

use PDO;
use XanhHub\Domain\Model\AssignmentDecision;
use XanhHub\Domain\Model\AvailabilitySlot;
use XanhHub\Domain\Model\PlanOutput;
use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\RegistrationSpec;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\VolunteerSpec;
use XanhHub\Domain\Scheduling\ConstraintGuard;

/**
 * Lớp Repository truy cập dữ liệu PostgreSQL của Xanh Hub.
 * Mọi câu lệnh SQL đều tham số hóa qua PDO, dùng giao dịch và hàm append_audit_log().
 */
final class XanhHubRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * Tải toàn bộ dữ liệu đầu vào của một hoạt động từ PostgreSQL thành đối tượng ScheduleDataset.
     */
    public function loadDatasetForActivity(int $activityId): ScheduleDataset
    {
        $stmtAct = $this->pdo->prepare('SELECT id, code FROM activities WHERE id = :id');
        $stmtAct->execute([':id' => $activityId]);
        $actRow = $stmtAct->fetch();
        $datasetCode = is_array($actRow) ? (string) $actRow['code'] : 'CUSTOM';

        // 1. Tải danh sách ca và yêu cầu kỹ năng
        $stmtShifts = $this->pdo->prepare('
            SELECT id, code, title,
                   EXTRACT(EPOCH FROM start_time)::bigint AS start_epoch,
                   EXTRACT(EPOCH FROM end_time)::bigint AS end_epoch,
                   start_time::text AS start_iso,
                   end_time::text AS end_iso,
                   min_people, max_people, priority, is_locked, lat, lng, location_name
              FROM shifts
             WHERE activity_id = :aid
             ORDER BY id ASC
        ');
        $stmtShifts->execute([':aid' => $activityId]);
        $shiftRows = $stmtShifts->fetchAll();

        $stmtReqs = $this->pdo->prepare('
            SELECT sr.id, sr.shift_id, sr.skill_id, s.code AS skill_code, s.name AS skill_name,
                   sr.min_level, sr.required_count, sr.require_verified
              FROM shift_requirements sr
              JOIN skills s ON s.id = sr.skill_id
              JOIN shifts sh ON sh.id = sr.shift_id
             WHERE sh.activity_id = :aid
             ORDER BY sr.id ASC
        ');
        $stmtReqs->execute([':aid' => $activityId]);
        $reqRows = $stmtReqs->fetchAll();

        $reqsByShift = [];
        foreach ($reqRows as $r) {
            $sid = (int) $r['shift_id'];
            $reqsByShift[$sid][] = new SkillRequirementSpec(
                id: (int) $r['id'],
                shiftId: $sid,
                skillId: (int) $r['skill_id'],
                skillCode: (string) $r['skill_code'],
                skillName: (string) $r['skill_name'],
                minLevel: (int) $r['min_level'],
                requiredCount: (int) $r['required_count'],
                requireVerified: (bool) $r['require_verified']
            );
        }

        $shifts = [];
        foreach ($shiftRows as $s) {
            $sid = (int) $s['id'];
            $shifts[] = new ShiftSpec(
                id: $sid,
                code: (string) $s['code'],
                title: (string) $s['title'],
                startEpoch: (int) $s['start_epoch'],
                endEpoch: (int) $s['end_epoch'],
                startTimeIso: (string) $s['start_iso'],
                endTimeIso: (string) $s['end_iso'],
                minPeople: (int) $s['min_people'],
                maxPeople: (int) $s['max_people'],
                priority: (int) $s['priority'],
                requirements: $reqsByShift[$sid] ?? [],
                isLocked: (bool) $s['is_locked'],
                lat: (float) $s['lat'],
                lng: (float) $s['lng'],
                locationName: (string) $s['location_name']
            );
        }

        // 2. Tải danh sách tình nguyện viên, kỹ năng và lịch rảnh
        $volRows = $this->pdo->query('
            SELECT v.user_id AS id, v.student_code, u.full_name, v.chapter_name,
                   v.max_shifts_per_week, v.lat, v.lng
              FROM volunteers v
              JOIN users u ON u.id = v.user_id
             ORDER BY v.user_id ASC
        ')->fetchAll();

        $vSkillRows = $this->pdo->query('
            SELECT volunteer_id, skill_id, level, is_verified
              FROM volunteer_skills
        ')->fetchAll();
        $skillsByVol = [];
        foreach ($vSkillRows as $vs) {
            $skillsByVol[(int) $vs['volunteer_id']][(int) $vs['skill_id']] = [
                'level' => (int) $vs['level'],
                'is_verified' => (bool) $vs['is_verified'],
            ];
        }

        $availRows = $this->pdo->query('
            SELECT volunteer_id, day_of_week, start_time::text AS start_hm, end_time::text AS end_hm
              FROM availability
        ')->fetchAll();
        $availByVol = [];
        foreach ($availRows as $av) {
            $availByVol[(int) $av['volunteer_id']][] = AvailabilitySlot::fromHm(
                (int) $av['day_of_week'],
                (string) $av['start_hm'],
                (string) $av['end_hm']
            );
        }

        $overrideRows = $this->pdo->query('
            SELECT volunteer_id, override_date::text AS odate, start_time::text AS start_hm,
                   end_time::text AS end_hm, is_available
              FROM availability_overrides
        ')->fetchAll();
        foreach ($overrideRows as $ov) {
            $availByVol[(int) $ov['volunteer_id']][] = AvailabilitySlot::fromHm(
                1,
                (string) $ov['start_hm'],
                (string) $ov['end_hm'],
                (string) $ov['odate'],
                (bool) $ov['is_available']
            );
        }

        $volunteers = [];
        foreach ($volRows as $v) {
            $vid = (int) $v['id'];
            $volunteers[] = new VolunteerSpec(
                id: $vid,
                studentCode: (string) $v['student_code'],
                fullName: (string) $v['full_name'],
                chapterName: (string) $v['chapter_name'],
                maxShiftsPerWeek: (int) $v['max_shifts_per_week'],
                skills: $skillsByVol[$vid] ?? [],
                availabilitySlots: $availByVol[$vid] ?? [],
                lat: (float) $v['lat'],
                lng: (float) $v['lng']
            );
        }

        // 3. Tải danh sách đăng ký
        $stmtRegs = $this->pdo->prepare('
            SELECT id, shift_id, volunteer_id, preference_rank, status,
                   EXTRACT(EPOCH FROM registered_at)::bigint AS reg_epoch,
                   registered_at::text AS reg_iso
              FROM registrations
             WHERE activity_id = :aid
             ORDER BY id ASC
        ');
        $stmtRegs->execute([':aid' => $activityId]);
        $regRows = $stmtRegs->fetchAll();

        $registrations = [];
        foreach ($regRows as $r) {
            $registrations[] = new RegistrationSpec(
                id: (int) $r['id'],
                shiftId: (int) $r['shift_id'],
                volunteerId: (int) $r['volunteer_id'],
                preferenceRank: (int) $r['preference_rank'],
                registeredAtEpoch: (int) $r['reg_epoch'],
                registeredAtIso: (string) $r['reg_iso'],
                status: (string) $r['status']
            );
        }

        // 4. Tải các phân công đã CONFIRMED hoặc thuộc ca đã LOCKED để giữ cố định khi chạy lại
        $stmtFixed = $this->pdo->prepare('
            SELECT a.shift_id, a.requirement_id, a.volunteer_id
              FROM assignments a
              JOIN shifts s ON s.id = a.shift_id
             WHERE a.activity_id = :aid
               AND (a.status IN (\'CONFIRMED\', \'CHECKED_IN\') OR s.is_locked = TRUE)
             ORDER BY a.id ASC
        ');
        $stmtFixed->execute([':aid' => $activityId]);
        $fixedRows = $stmtFixed->fetchAll();

        $fixedAssignments = [];
        foreach ($fixedRows as $fr) {
            $fixedAssignments[] = new AssignmentDecision(
                shiftId: (int) $fr['shift_id'],
                requirementId: $fr['requirement_id'] !== null ? (int) $fr['requirement_id'] : null,
                volunteerId: (int) $fr['volunteer_id'],
                score: 0.0,
                isLockedFixed: true
            );
        }

        return new ScheduleDataset(
            activityId: $activityId,
            datasetCode: $datasetCode,
            shifts: $shifts,
            volunteers: $volunteers,
            registrations: $registrations,
            fixedAssignments: $fixedAssignments
        );
    }

    /**
     * Lưu ảnh chụp dữ liệu đầu vào (dataset_snapshots) và kết quả một phương án (plans, plan_items, unassigned).
     */
    public function savePlanOutput(ScheduleDataset $dataset, PlanOutput $plan, ?int $actorUserId = null): int
    {
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $hash = $plan->snapshotHash;
            $stmtFindSnap = $this->pdo->prepare('
                SELECT id FROM dataset_snapshots
                 WHERE activity_id = :aid AND sha256_hash = :hash
                 LIMIT 1
            ');
            $stmtFindSnap->execute([
                ':aid' => $dataset->activityId,
                ':hash' => $hash,
            ]);
            $snapRow = $stmtFindSnap->fetch();

            if (is_array($snapRow)) {
                $snapshotId = (int) $snapRow['id'];
            } else {
                $stmtInsSnap = $this->pdo->prepare('
                    INSERT INTO dataset_snapshots (activity_id, dataset_code, sha256_hash, payload_json)
                    VALUES (:aid, :code, :hash, :payload::jsonb)
                    RETURNING id
                ');
                $stmtInsSnap->execute([
                    ':aid' => $dataset->activityId,
                    ':code' => $dataset->datasetCode,
                    ':hash' => $hash,
                    ':payload' => json_encode($dataset->toCanonicalArray(), JSON_UNESCAPED_UNICODE),
                ]);
                $snapshotId = (int) $stmtInsSnap->fetchColumn();
            }

            $stmtPlan = $this->pdo->prepare('
                INSERT INTO plans (activity_id, snapshot_id, algorithm_code, priority_mode, config_json, random_seed, metrics_json, status, created_by)
                VALUES (:aid, :sid, :algo, :mode, :cfg::jsonb, :seed, :metrics::jsonb, \'PROPOSED\', :uid)
                RETURNING id
            ');
            $stmtPlan->execute([
                ':aid' => $dataset->activityId,
                ':sid' => $snapshotId,
                ':algo' => $plan->algorithmCode,
                ':mode' => $plan->priorityMode,
                ':cfg' => json_encode($plan->config, JSON_UNESCAPED_UNICODE),
                ':seed' => (int) ($plan->config['random_seed'] ?? 202606),
                ':metrics' => json_encode($plan->metrics, JSON_UNESCAPED_UNICODE),
                ':uid' => $actorUserId,
            ]);
            $planId = (int) $stmtPlan->fetchColumn();

            $stmtItem = $this->pdo->prepare('
                INSERT INTO plan_items (plan_id, shift_id, requirement_id, volunteer_id, score, has_violation, violation_codes_json)
                VALUES (:pid, :shid, :reqid, :vid, :score, :has_v, :vcodes::jsonb)
            ');
            foreach ($plan->decisions as $d) {
                $stmtItem->execute([
                    ':pid' => $planId,
                    ':shid' => $d->shiftId,
                    ':reqid' => $d->requirementId,
                    ':vid' => $d->volunteerId,
                    ':score' => $d->score,
                    ':has_v' => $d->hasViolation() ? 'true' : 'false',
                    ':vcodes' => json_encode($d->violationCodes, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $stmtUn = $this->pdo->prepare('
                INSERT INTO unassigned (plan_id, item_type, shift_id, requirement_id, volunteer_id, missing_count, reason_code, explanation_text)
                VALUES (:pid, :itype, :shid, :reqid, :vid, :mcnt, :rcode, :etext)
            ');
            foreach ($plan->unassigned as $u) {
                $stmtUn->execute([
                    ':pid' => $planId,
                    ':itype' => $u->itemType,
                    ':shid' => $u->shiftId,
                    ':reqid' => $u->requirementId,
                    ':vid' => $u->volunteerId,
                    ':mcnt' => $u->missingCount,
                    ':rcode' => $u->reasonCode,
                    ':etext' => $u->explanationText,
                ]);
            }

            $this->appendAuditLog(
                actorUserId: $actorUserId,
                action: 'PLAN_GENERATED',
                entityType: 'plans',
                entityId: (string) $planId,
                reason: sprintf('Chạy thuật toán %s (%s) trên snapshot %s', $plan->algorithmCode, $plan->priorityMode, substr($hash, 0, 12)),
                payload: [
                    'algorithm_code' => $plan->algorithmCode,
                    'priority_mode' => $plan->priorityMode,
                    'snapshot_hash' => $hash,
                ]
            );

            if ($ownTx) {
                $this->pdo->commit();
            }

            return $planId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Áp dụng một phương án đề xuất sang bảng phân công chính thức (assignments).
     * Kiểm tra mã băm dữ liệu đầu vào (SNAPSHOT_STALE) trước khi áp dụng.
     *
     * @return array{ok: bool, reason_code: ?string, message_vi: string, applied_count: int}
     */
    public function applyPlanToAssignments(int $planId, ?int $actorUserId = null): array
    {
        $stmtPlan = $this->pdo->prepare('
            SELECT p.id, p.activity_id, p.algorithm_code, p.priority_mode, ds.sha256_hash
              FROM plans p
              JOIN dataset_snapshots ds ON ds.id = p.snapshot_id
             WHERE p.id = :pid
        ');
        $stmtPlan->execute([':pid' => $planId]);
        $planRow = $stmtPlan->fetch();

        if (!is_array($planRow)) {
            return [
                'ok' => false,
                'reason_code' => ReasonCode::SNAPSHOT_STALE,
                'message_vi' => 'Phương án phân công không tồn tại.',
                'applied_count' => 0,
            ];
        }

        $activityId = (int) $planRow['activity_id'];
        $currentDataset = $this->loadDatasetForActivity($activityId);
        $currentHash = $currentDataset->computeSnapshotHash();

        if ($currentHash !== (string) $planRow['sha256_hash']) {
            return [
                'ok' => false,
                'reason_code' => ReasonCode::SNAPSHOT_STALE,
                'message_vi' => ReasonCode::messageVi(ReasonCode::SNAPSHOT_STALE),
                'applied_count' => 0,
            ];
        }

        $stmtItems = $this->pdo->prepare('
            SELECT shift_id, requirement_id, volunteer_id, has_violation
              FROM plan_items
             WHERE plan_id = :pid
             ORDER BY id ASC
        ');
        $stmtItems->execute([':pid' => $planId]);
        $items = $stmtItems->fetchAll();

        foreach ($items as $item) {
            if ((bool) $item['has_violation']) {
                return [
                    'ok' => false,
                    'reason_code' => ReasonCode::TIME_CONFLICT,
                    'message_vi' => 'Không thể áp dụng phương án có chứa vi phạm ràng buộc cứng (như phương án B0).',
                    'applied_count' => 0,
                ];
            }
        }

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }

        try {
            // Xóa các phân công tạo bởi thuật toán (plan_id IS NOT NULL) chưa xác nhận/chưa khóa của hoạt động này trước khi chép phương án mới, bảo tồn phân công thủ công của Điều phối viên
            $stmtDeleteUnconfirmed = $this->pdo->prepare('
                DELETE FROM assignments a
                 USING shifts s
                 WHERE a.shift_id = s.id
                   AND a.activity_id = :aid
                   AND a.status = \'ASSIGNED\'
                   AND a.plan_id IS NOT NULL
                   AND s.is_locked = FALSE
            ');
            $stmtDeleteUnconfirmed->execute([':aid' => $activityId]);

            $stmtCheckExisting = $this->pdo->prepare('
                SELECT 1 FROM assignments
                 WHERE shift_id = :shid AND volunteer_id = :vid
                   AND status IN (\'ASSIGNED\', \'CONFIRMED\', \'AT_RISK\', \'CHECKED_IN\')
                 LIMIT 1
            ');

            $stmtInsert = $this->pdo->prepare('
                INSERT INTO assignments (activity_id, plan_id, shift_id, requirement_id, volunteer_id, during, status, assigned_by)
                SELECT :aid, :pid, s.id, :reqid, :vid, s.during, \'ASSIGNED\', :uid
                  FROM shifts s
                 WHERE s.id = :shid
            ');

            $appliedCount = 0;
            foreach ($items as $item) {
                $shid = (int) $item['shift_id'];
                $vid = (int) $item['volunteer_id'];
                $reqId = $item['requirement_id'] !== null ? (int) $item['requirement_id'] : null;

                $stmtCheckExisting->execute([':shid' => $shid, ':vid' => $vid]);
                if ($stmtCheckExisting->fetchColumn() !== false) {
                    continue;
                }

                $stmtInsert->execute([
                    ':aid' => $activityId,
                    ':pid' => $planId,
                    ':reqid' => $reqId,
                    ':vid' => $vid,
                    ':uid' => $actorUserId,
                    ':shid' => $shid,
                ]);
                $appliedCount++;
            }

            // Đánh dấu các phương án khác của cùng hoạt động là SUPERSEDED và phương án này là APPLIED
            $this->pdo->prepare('UPDATE plans SET status = \'SUPERSEDED\' WHERE activity_id = :aid AND status = \'APPLIED\'')
                ->execute([':aid' => $activityId]);
            $this->pdo->prepare('UPDATE plans SET status = \'APPLIED\' WHERE id = :pid')
                ->execute([':pid' => $planId]);

            $this->appendAuditLog(
                actorUserId: $actorUserId,
                action: 'PLAN_APPLIED',
                entityType: 'plans',
                entityId: (string) $planId,
                reason: 'Điều phối viên xác nhận áp dụng phương án phân công',
                payload: ['applied_count' => $appliedCount]
            );

            if ($ownTx) {
                $this->pdo->commit();
            }

            return [
                'ok' => true,
                'reason_code' => null,
                'message_vi' => sprintf('Đã áp dụng phương án thành công (%d phân công mới).', $appliedCount),
                'applied_count' => $appliedCount,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'ok' => false,
                'reason_code' => ReasonCode::TIME_CONFLICT,
                'message_vi' => 'Lỗi ràng buộc cơ sở dữ liệu khi áp dụng phương án: ' . $e->getMessage(),
                'applied_count' => 0,
            ];
        }
    }

    /**
     * Sửa phân công bằng tay của Điều phối viên (CN4):
     * Chạy ConstraintGuard ở máy chủ + kiểm tra khóa dòng ở PostgreSQL + ghi nhật ký kiểm toán.
     *
     * @return array<string, mixed>
     */
    public function manualAssignVolunteer(
        int $activityId,
        int $shiftId,
        int $volunteerId,
        ?int $requirementId = null,
        ?string $overrideReason = null,
        bool $unlockConfirmed = false,
        ?int $actorUserId = null
    ): array {
        $dataset = $this->loadDatasetForActivity($activityId);
        $currentAssignments = $this->loadActiveAssignmentDecisions($activityId);

        $guard = new ConstraintGuard();
        $validation = $guard->validateAssignment(
            dataset: $dataset,
            currentAssignments: $currentAssignments,
            shiftId: $shiftId,
            volunteerId: $volunteerId,
            requirementId: $requirementId,
            isManualEdit: true,
            overrideReason: $overrideReason,
            unlockConfirmed: $unlockConfirmed
        );

        if (!$validation['allowed']) {
            return $validation;
        }

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmtIns = $this->pdo->prepare('
                INSERT INTO assignments (activity_id, shift_id, requirement_id, volunteer_id, during, status, override_reason, assigned_by)
                SELECT :aid, s.id, :reqid, :vid, s.during, \'ASSIGNED\', :oreason, :uid
                  FROM shifts s
                 WHERE s.id = :shid
                RETURNING id
            ');
            $stmtIns->execute([
                ':aid' => $activityId,
                ':reqid' => $validation['resolved_requirement_id'],
                ':vid' => $volunteerId,
                ':oreason' => $overrideReason,
                ':uid' => $actorUserId,
                ':shid' => $shiftId,
            ]);
            $newId = (int) $stmtIns->fetchColumn();

            $this->appendAuditLog(
                actorUserId: $actorUserId,
                action: 'MANUAL_ASSIGN',
                entityType: 'assignments',
                entityId: (string) $newId,
                reason: $overrideReason ?? 'Điều phối viên gán tay trên bảng ca',
                payload: [
                    'shift_id' => $shiftId,
                    'volunteer_id' => $volunteerId,
                    'requirement_id' => $validation['resolved_requirement_id'],
                    'soft_warnings' => $validation['soft_warnings'],
                ]
            );

            if ($ownTx) {
                $this->pdo->commit();
            }

            $validation['assignment_id'] = $newId;
            return $validation;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $msg = $e->getMessage();
            $code = str_contains($msg, 'CAPACITY_FULL') ? ReasonCode::CAPACITY_FULL : ReasonCode::TIME_CONFLICT;
            return [
                'allowed' => false,
                'requires_override' => false,
                'hard_violations' => [$code],
                'soft_warnings' => [],
                'all_codes' => [$code],
                'messages_vi' => [ReasonCode::messageVi($code)],
                'resolved_requirement_id' => $requirementId,
            ];
        }
    }

    /**
     * Báo vắng và thay người (CN5):
     * Đánh dấu phân công cũ là ABSENT_REPORTED, tạo bản ghi absence_reports, và nếu truyền $replacementVolunteerId
     * thì kiểm tra qua ConstraintGuard rồi gán người thay thế.
     *
     * @return array<string, mixed>
     */
    public function reportAbsenceAndReplace(
        int $assignmentId,
        string $reason,
        ?int $replacementVolunteerId = null,
        ?int $actorUserId = null
    ): array {
        $stmtFind = $this->pdo->prepare('
            SELECT id, activity_id, shift_id, requirement_id, volunteer_id, status
              FROM assignments
             WHERE id = :id
        ');
        $stmtFind->execute([':id' => $assignmentId]);
        $row = $stmtFind->fetch();

        if (!is_array($row)) {
            return ['ok' => false, 'message_vi' => 'Không tìm thấy phân công cần báo vắng.'];
        }

        $activityId = (int) $row['activity_id'];
        $shiftId = (int) $row['shift_id'];
        $reqId = $row['requirement_id'] !== null ? (int) $row['requirement_id'] : null;
        $absentVolId = (int) $row['volunteer_id'];

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Đổi trạng thái phân công sang ABSENT_REPORTED (nhờ đó giải phóng chỗ trên ca và khung giờ)
            $this->pdo->prepare('UPDATE assignments SET status = \'ABSENT_REPORTED\' WHERE id = :id')
                ->execute([':id' => $assignmentId]);

            $stmtRep = $this->pdo->prepare('
                INSERT INTO absence_reports (assignment_id, volunteer_id, reason, status)
                VALUES (:aid, :vid, :reason, \'OPEN\')
                RETURNING id
            ');
            $stmtRep->execute([
                ':aid' => $assignmentId,
                ':vid' => $absentVolId,
                ':reason' => $reason,
            ]);
            $reportId = (int) $stmtRep->fetchColumn();

            $this->appendAuditLog(
                actorUserId: $actorUserId ?? $absentVolId,
                action: 'ABSENCE_REPORTED',
                entityType: 'absence_reports',
                entityId: (string) $reportId,
                reason: $reason,
                payload: ['assignment_id' => $assignmentId, 'shift_id' => $shiftId, 'volunteer_id' => $absentVolId]
            );

            $replacementResult = null;
            if ($replacementVolunteerId !== null) {
                $assignRes = $this->manualAssignVolunteer(
                    activityId: $activityId,
                    shiftId: $shiftId,
                    volunteerId: $replacementVolunteerId,
                    requirementId: $reqId,
                    overrideReason: 'Thay thế tình nguyện viên báo vắng (Report #' . $reportId . ')',
                    unlockConfirmed: true,
                    actorUserId: $actorUserId
                );

                if (!$assignRes['allowed']) {
                    if ($ownTx && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    return [
                        'ok' => false,
                        'reason_codes' => $assignRes['all_codes'],
                        'message_vi' => 'Người thay thế không thỏa mãn điều kiện: ' . implode('; ', $assignRes['messages_vi']),
                    ];
                }

                $newAssignId = (int) $assignRes['assignment_id'];
                $this->pdo->prepare('
                    UPDATE absence_reports
                       SET replacement_assignment_id = :raid, status = \'REPLACED\'
                     WHERE id = :rid
                ')->execute([':raid' => $newAssignId, ':rid' => $reportId]);

                $this->pdo->prepare('UPDATE assignments SET status = \'REPLACED\' WHERE id = :id')
                    ->execute([':id' => $assignmentId]);

                $replacementResult = $assignRes;
            }

            if ($ownTx) {
                $this->pdo->commit();
            }

            return [
                'ok' => true,
                'report_id' => $reportId,
                'shift_id' => $shiftId,
                'requirement_id' => $reqId,
                'replacement' => $replacementResult,
                'message_vi' => $replacementVolunteerId !== null
                    ? 'Đã ghi nhận báo vắng và phân công người thay thế hợp lệ.'
                    : 'Đã ghi nhận báo vắng và mở vị trí trống để chọn người thay thế.',
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<AssignmentDecision>
     */
    public function loadActiveAssignmentDecisions(int $activityId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT shift_id, requirement_id, volunteer_id, status
              FROM assignments
             WHERE activity_id = :aid
               AND status IN (\'ASSIGNED\', \'CONFIRMED\', \'AT_RISK\', \'CHECKED_IN\')
             ORDER BY id ASC
        ');
        $stmt->execute([':aid' => $activityId]);
        $rows = $stmt->fetchAll();

        $list = [];
        foreach ($rows as $r) {
            $list[] = new AssignmentDecision(
                shiftId: (int) $r['shift_id'],
                requirementId: $r['requirement_id'] !== null ? (int) $r['requirement_id'] : null,
                volunteerId: (int) $r['volunteer_id'],
                score: 0.0,
                isLockedFixed: in_array((string) $r['status'], ['CONFIRMED', 'CHECKED_IN'], true)
            );
        }
        return $list;
    }

    /**
     * Ghi nhật ký kiểm toán chuỗi mã băm bất biến qua hàm PostgreSQL append_audit_log().
     *
     * @param array<string, mixed> $payload
     */
    public function appendAuditLog(
        ?int $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        string $reason,
        array $payload = []
    ): int {
        $stmt = $this->pdo->prepare('
            SELECT append_audit_log(:uid, :act, :etype, :eid, :reason, :payload::jsonb)
        ');
        $stmt->execute([
            ':uid' => $actorUserId,
            ':act' => $action,
            ':etype' => $entityType,
            ':eid' => $entityId,
            ':reason' => $reason,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Kiểm tra tính toàn vẹn của toàn bộ chuỗi mã băm trong bảng audit_log.
     */
    public function verifyAuditLogChain(): bool
    {
        $rows = $this->pdo->query('SELECT id, prev_hash, row_hash FROM audit_log ORDER BY id ASC')->fetchAll();
        $expectedPrev = str_repeat('0', 64);

        foreach ($rows as $r) {
            if ((string) $r['prev_hash'] !== $expectedPrev) {
                return false;
            }
            $expectedPrev = (string) $r['row_hash'];
        }
        return true;
    }
}

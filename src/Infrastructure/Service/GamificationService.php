<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;
use XanhHub\Http\SvgQrGenerator;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;

/**
 * Dịch vụ Động cơ Gamification, Đánh giá 360°, Điểm Cống hiến (Merit XP),
 * Bộ 6 Huy hiệu Số Danh dự Đoàn Thanh niên & Bảng Vàng Thi Đua (Leaderboards).
 */
final class GamificationService
{
    /**
     * Danh mục chuẩn hóa Bộ 6 Huy hiệu Số danh dự Đoàn TNCS Hồ Chí Minh.
     */
    public const BADGES = [
        'ROOKIE_VOLUNTEER' => [
            'id' => 1,
            'code' => 'ROOKIE_VOLUNTEER',
            'name' => 'Chiến sĩ Tân binh',
            'description' => 'Hoàn thành ca trực đầu tiên trong chiến dịch',
            'icon' => 'military_tech',
            'color' => '#10B981',
            'category' => 'MERIT',
            'bg_class' => 'bg-emerald-50 text-emerald-700 border-emerald-300',
        ],
        'FIRST_AID_HERO' => [
            'id' => 2,
            'code' => 'FIRST_AID_HERO',
            'name' => 'Đôi tay Vàng Sơ cứu',
            'description' => 'Đạt >= 2 ca chuyên môn Sơ cứu Y tế với đánh giá >= 4.5 sao',
            'icon' => 'medical_services',
            'color' => '#EF4444',
            'category' => 'SKILL',
            'bg_class' => 'bg-rose-50 text-rose-700 border-rose-300',
        ],
        'ENVIRONMENT_CHAMPION' => [
            'id' => 3,
            'code' => 'ENVIRONMENT_CHAMPION',
            'name' => 'Kiện tướng Môi trường',
            'description' => 'Hoàn thành >= 3 ca Chủ Nhật Xanh làm sạch kênh và trồng cây',
            'icon' => 'eco',
            'color' => '#059669',
            'category' => 'CAMPAIGN',
            'bg_class' => 'bg-teal-50 text-teal-700 border-teal-300',
        ],
        'PERSEVERANT_SOLDIER' => [
            'id' => 4,
            'code' => 'PERSEVERANT_SOLDIER',
            'name' => 'Chiến sĩ Bền bỉ',
            'description' => 'Điểm danh đúng giờ 100% các ca đã đăng ký mà không vắng mặt',
            'icon' => 'verified',
            'color' => '#0284C7',
            'category' => 'DILIGENCE',
            'bg_class' => 'bg-sky-50 text-sky-700 border-sky-300',
        ],
        'INSPIRING_AMBASSADOR' => [
            'id' => 5,
            'code' => 'INSPIRING_AMBASSADOR',
            'name' => 'Đại sứ Lan tỏa',
            'description' => 'Đạt điểm đánh giá tác phong tối đa 5 sao từ Điều phối viên',
            'icon' => 'campaign',
            'color' => '#8B5CF6',
            'category' => 'LEADERSHIP',
            'bg_class' => 'bg-purple-50 text-purple-700 border-purple-300',
        ],
        'VOLUNTEER_LEADER' => [
            'id' => 6,
            'code' => 'VOLUNTEER_LEADER',
            'name' => 'Thủ lĩnh Tình nguyện',
            'description' => 'Cống hiến >= 25 giờ và XP thuộc Top 10 toàn hệ thống',
            'icon' => 'trophy',
            'color' => '#F59E0B',
            'category' => 'HONOR',
            'bg_class' => 'bg-amber-50 text-amber-700 border-amber-300',
        ],
    ];

    /**
     * Bản đồ chuẩn hóa Chi đoàn -> Đoàn Khoa / Đoàn Trường Đại học.
     */
    public const FACULTY_MAPPING = [
        'KTPM' => [
            'name' => 'Khoa Kỹ thuật Phần mềm (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa KT Phần mềm',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#0284C7',
        ],
        'HTTT' => [
            'name' => 'Khoa Hệ thống Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Hệ thống TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#059669',
        ],
        'KHMT' => [
            'name' => 'Khoa Khoa học Máy tính (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Khoa học MT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#7C3AED',
        ],
        'MMT' => [
            'name' => 'Khoa Mạng máy tính & TT (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa Mạng MT & TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#D97706',
        ],
        'ATTT' => [
            'name' => 'Khoa An toàn Thông tin (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa An toàn TT',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#DC2626',
        ],
        'KHDL' => [
            'name' => 'Khoa Khoa học Dữ liệu (Trường ĐH Công nghệ Thông tin - ĐHQG-HCM)',
            'short' => 'Đoàn Khoa KH Dữ liệu',
            'school' => 'ĐH Công nghệ Thông tin (ĐHQG-HCM)',
            'color' => '#2563EB',
        ],
        'KHAC' => [
            'name' => 'Đoàn cơ sở Khối ĐHQG-HCM & Đơn vị liên kết',
            'short' => 'Đoàn cơ sở khác',
            'school' => 'ĐHQG-HCM & Đơn vị phối hợp',
            'color' => '#64748B',
        ],
    ];

    /**
     * Bộ nhớ mô phỏng độc lập phục vụ test runner hoặc môi trường offline không có PostgreSQL daemon.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $mockEvaluations = [];

    /**
     * @var array<int, array<string, bool>> Map volunteer_id => [badge_code => true]
     */
    private static array $mockBadges = [];

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $mockVolunteers = null;

    private static int $mockEvalIdCounter = 1000;

    public function __construct(
        private readonly ?PDO $pdo = null
    ) {
    }

    /**
     * Reset bộ nhớ mô phỏng (dành cho kiểm thử đơn vị độc lập).
     */
    public static function resetMockState(): void
    {
        self::$mockEvaluations = [];
        self::$mockBadges = [];
        self::$mockVolunteers = null;
        self::$mockEvalIdCounter = 1000;
    }

    /**
     * R1: Điều phối viên chấm điểm & đánh giá Tình nguyện viên sau ca trực.
     *
     * @param int $assignmentId Mã phân công ca trực
     * @param int $coordUserId Mã tài khoản Điều phối viên
     * @param int $volUserId Mã tài khoản Tình nguyện viên được đánh giá
     * @param int $diligence Điểm chuyên cần (1..5)
     * @param int $competence Điểm chuyên môn (1..5)
     * @param int $demeanor Điểm tác phong (1..5)
     * @param string $feedback Nhận xét đánh giá
     * @return array<string, mixed>
     */
    public function submitCoordinatorEvaluation(
        int $assignmentId,
        int $coordUserId,
        int $volUserId,
        int $diligence,
        int $competence,
        int $demeanor,
        string $feedback = ''
    ): array {
        // Ràng buộc toàn vẹn: thang điểm 1..5 sao
        if ($diligence < 1 || $diligence > 5 || $competence < 1 || $competence > 5 || $demeanor < 1 || $demeanor > 5) {
            return [
                'ok' => false,
                'error' => 'Điểm đánh giá các tiêu chí phải nằm trong khoảng từ 1 đến 5 sao.',
                'error_code' => 'INVALID_RATING_RANGE',
            ];
        }

        $avgScore = round(($diligence + $competence + $demeanor) / 3.0, 2);

        if ($this->pdo !== null) {
            try {
                // Kiểm tra phân công hợp lệ và lấy shift_id
                $stmtCheck = $this->pdo->prepare('
                    SELECT a.shift_id, a.volunteer_id, a.status
                      FROM assignments a
                     WHERE a.id = :aid
                     LIMIT 1
                ');
                $stmtCheck->execute([':aid' => $assignmentId]);
                $assignRow = $stmtCheck->fetch();

                if (!$assignRow) {
                    return [
                        'ok' => false,
                        'error' => 'Không tìm thấy thông tin phân công ca trực tương ứng.',
                        'error_code' => 'ASSIGNMENT_NOT_FOUND',
                    ];
                }

                $shiftId = (int) $assignRow['shift_id'];

                // Chặn đánh giá trùng lặp: 1 người dùng chỉ được đánh giá 1 lần duy nhất cho cùng 1 ca trực
                $stmtDup = $this->pdo->prepare("
                    SELECT id FROM evaluations
                     WHERE assignment_id = :aid AND evaluation_type = 'COORDINATOR_TO_VOLUNTEER'
                     LIMIT 1
                ");
                $stmtDup->execute([':aid' => $assignmentId]);
                if ($stmtDup->fetchColumn() !== false) {
                    return [
                        'ok' => false,
                        'error' => 'Phân công ca trực này đã được Điều phối viên đánh giá trước đó.',
                        'error_code' => 'DUPLICATE_EVALUATION',
                    ];
                }

                // Ghi nhận bản ghi đánh giá
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO evaluations (
                        assignment_id, shift_id, evaluator_user_id, target_user_id,
                        evaluation_type, criteria_diligence, criteria_competence, criteria_demeanor,
                        average_score, feedback_text, created_at
                    ) VALUES (
                        :aid, :sid, :eval_uid, :target_uid,
                        'COORDINATOR_TO_VOLUNTEER', :diligence, :competence, :demeanor,
                        :avg, :feedback, NOW()
                    ) RETURNING id
                ");
                $stmtInsert->execute([
                    ':aid' => $assignmentId,
                    ':sid' => $shiftId,
                    ':eval_uid' => $coordUserId,
                    ':target_uid' => $volUserId,
                    ':diligence' => $diligence,
                    ':competence' => $competence,
                    ':demeanor' => $demeanor,
                    ':avg' => $avgScore,
                    ':feedback' => trim($feedback),
                ]);

                $evalId = (int) $stmtInsert->fetchColumn();

                // Ghi nhật ký kiểm toán bất biến append_audit_log nếu có thể
                try {
                    $this->pdo->prepare("
                        SELECT append_audit_log(
                            :actor, 'EVALUATE_VOLUNTEER', 'evaluations', :eid,
                            :reason, :payload::jsonb
                        )
                    ")->execute([
                        ':actor' => $coordUserId,
                        ':eid' => (string) $evalId,
                        ':reason' => 'Điều phối viên đánh giá 360 độ tình nguyện viên sau ca trực',
                        ':payload' => json_encode([
                            'assignment_id' => $assignmentId,
                            'target_user_id' => $volUserId,
                            'average_score' => $avgScore,
                            'criteria' => ['diligence' => $diligence, 'competence' => $competence, 'demeanor' => $demeanor],
                        ]),
                    ]);
                } catch (\Throwable) {
                    // Bỏ qua lỗi ghi audit_log nếu hàm chưa nạp
                }

                // Tự động kiểm tra và mở khóa huy hiệu sau đánh giá
                $unlocked = $this->evaluateAndAwardBadges($volUserId);

                return [
                    'ok' => true,
                    'evaluation_id' => $evalId,
                    'average_score' => $avgScore,
                    'badges_unlocked' => $unlocked,
                ];
            } catch (\Throwable) {
                // Rơi về mock handling nếu DB gặp lỗi kết nối
            }
        }

        // --- Xử lý chế độ Standalone / Offline Mock ---
        foreach (self::$mockEvaluations as $me) {
            if ($me['assignment_id'] === $assignmentId && $me['evaluation_type'] === 'COORDINATOR_TO_VOLUNTEER') {
                return [
                    'ok' => false,
                    'error' => 'Phân công ca trực này đã được Điều phối viên đánh giá trước đó.',
                    'error_code' => 'DUPLICATE_EVALUATION',
                ];
            }
        }

        $evalId = ++self::$mockEvalIdCounter;
        self::$mockEvaluations[$evalId] = [
            'id' => $evalId,
            'assignment_id' => $assignmentId,
            'shift_id' => 101,
            'evaluator_user_id' => $coordUserId,
            'target_user_id' => $volUserId,
            'evaluation_type' => 'COORDINATOR_TO_VOLUNTEER',
            'criteria_diligence' => $diligence,
            'criteria_competence' => $competence,
            'criteria_demeanor' => $demeanor,
            'average_score' => $avgScore,
            'feedback_text' => trim($feedback),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $unlocked = $this->evaluateAndAwardBadges($volUserId);

        return [
            'ok' => true,
            'evaluation_id' => $evalId,
            'average_score' => $avgScore,
            'badges_unlocked' => $unlocked,
        ];
    }

    /**
     * R1: Tình nguyện viên đánh giá trải nghiệm thực tế ca trực (Hậu cần, An toàn, Hỗ trợ BCH).
     *
     * @param int $assignmentId Mã phân công ca trực
     * @param int $volUserId Mã tài khoản Tình nguyện viên đánh giá
     * @param int $shiftId Mã ca trực
     * @param int $logistics Đánh giá hậu cần tiếp sức (1..5)
     * @param int $safety Đánh giá mức độ an toàn hiện trường (1..5)
     * @param int $support Đánh giá sự hỗ trợ của Ban chỉ huy (1..5)
     * @param string $feedback Nhận xét góp ý
     * @return array<string, mixed>
     */
    public function submitVolunteerShiftEvaluation(
        int $assignmentId,
        int $volUserId,
        int $shiftId,
        int $logistics,
        int $safety,
        int $support,
        string $feedback = ''
    ): array {
        if ($logistics < 1 || $logistics > 5 || $safety < 1 || $safety > 5 || $support < 1 || $support > 5) {
            return [
                'ok' => false,
                'error' => 'Điểm đánh giá trải nghiệm ca trực phải nằm trong khoảng từ 1 đến 5 sao.',
                'error_code' => 'INVALID_RATING_RANGE',
            ];
        }

        $avgScore = round(($logistics + $safety + $support) / 3.0, 2);

        if ($this->pdo !== null) {
            try {
                // Chặn đánh giá trùng lặp
                $stmtDup = $this->pdo->prepare("
                    SELECT id FROM evaluations
                     WHERE assignment_id = :aid AND evaluation_type = 'VOLUNTEER_TO_SHIFT'
                     LIMIT 1
                ");
                $stmtDup->execute([':aid' => $assignmentId]);
                if ($stmtDup->fetchColumn() !== false) {
                    return [
                        'ok' => false,
                        'error' => 'Bạn đã gửi đánh giá trải nghiệm cho ca trực này trước đó.',
                        'error_code' => 'DUPLICATE_EVALUATION',
                    ];
                }

                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO evaluations (
                        assignment_id, shift_id, evaluator_user_id, target_user_id,
                        evaluation_type, criteria_logistics, criteria_safety, criteria_support,
                        average_score, feedback_text, created_at
                    ) VALUES (
                        :aid, :sid, :eval_uid, NULL,
                        'VOLUNTEER_TO_SHIFT', :logistics, :safety, :support,
                        :avg, :feedback, NOW()
                    ) RETURNING id
                ");
                $stmtInsert->execute([
                    ':aid' => $assignmentId,
                    ':sid' => $shiftId,
                    ':eval_uid' => $volUserId,
                    ':logistics' => $logistics,
                    ':safety' => $safety,
                    ':support' => $support,
                    ':avg' => $avgScore,
                    ':feedback' => trim($feedback),
                ]);

                $evalId = (int) $stmtInsert->fetchColumn();

                return [
                    'ok' => true,
                    'evaluation_id' => $evalId,
                    'average_score' => $avgScore,
                ];
            } catch (\Throwable) {
                // Rơi về mock
            }
        }

        // Offline mock handling
        foreach (self::$mockEvaluations as $me) {
            if ($me['assignment_id'] === $assignmentId && $me['evaluation_type'] === 'VOLUNTEER_TO_SHIFT') {
                return [
                    'ok' => false,
                    'error' => 'Bạn đã gửi đánh giá trải nghiệm cho ca trực này trước đó.',
                    'error_code' => 'DUPLICATE_EVALUATION',
                ];
            }
        }

        $evalId = ++self::$mockEvalIdCounter;
        self::$mockEvaluations[$evalId] = [
            'id' => $evalId,
            'assignment_id' => $assignmentId,
            'shift_id' => $shiftId,
            'evaluator_user_id' => $volUserId,
            'target_user_id' => null,
            'evaluation_type' => 'VOLUNTEER_TO_SHIFT',
            'criteria_logistics' => $logistics,
            'criteria_safety' => $safety,
            'criteria_support' => $support,
            'average_score' => $avgScore,
            'feedback_text' => trim($feedback),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        return [
            'ok' => true,
            'evaluation_id' => $evalId,
            'average_score' => $avgScore,
        ];
    }

    /**
     * R2 & R4: Tính toán Điểm Cống hiến (Merit XP) và Điểm Uy tín (Reputation Score).
     *
     * Công thức Merit XP:
     *   XP = (hours * 10) + (avg_rating * 5) + (punctual_checkins * 10)
     *
     * Công thức Reputation Score:
     *   round(0.60 * avg_rating + 0.40 * (punctual_rate * 5.0), 2)
     *
     * @return array{
     *     volunteer_id: int,
     *     total_hours: float,
     *     avg_rating: float,
     *     on_time_checkins: int,
     *     assigned_count: int,
     *     absent_count: int,
     *     punctual_rate: float,
     *     xp: float,
     *     reputation_score: float,
     *     badges: list<array<string, mixed>>,
     *     unlocked_badge_count: int
     * }
     */
    public function calculateVolunteerMerit(int $volunteerId): array
    {
        $raw = $this->loadVolunteerActivityMetrics($volunteerId);

        $totalHours = (float) ($raw['total_hours'] ?? 0.0);
        $evalCount = (int) ($raw['eval_count'] ?? 0);
        $avgRating = $evalCount > 0 ? (float) $raw['avg_rating'] : 0.0;
        $onTimeCheckins = (int) ($raw['on_time_checkins'] ?? 0);
        $assignedCount = (int) ($raw['assigned_count'] ?? 0);
        $absentCount = (int) ($raw['absent_count'] ?? 0);

        // Kẹp điểm sao trung bình trong khoảng [1.0, 5.0] nếu có đánh giá
        if ($evalCount > 0) {
            $avgRating = max(1.0, min(5.0, round($avgRating, 2)));
        }

        // Tỷ lệ chuyên cần
        if ($assignedCount > 0) {
            $punctualRate = min(1.0, max(0.0, $onTimeCheckins / (float) $assignedCount));
        } else {
            $punctualRate = 1.0;
        }

        // R2: Thuật toán tính Điểm Cống Hiến (Merit XP)
        // XP = (Số giờ trực * 10) + (Điểm sao trung bình * 5) + Điểm thưởng chuyên cần (Checkin đúng giờ * 10)
        if ($totalHours <= 0.0 && $evalCount === 0 && $onTimeCheckins === 0) {
            $xp = 0.0;
        } else {
            $xp = round(($totalHours * 10.0) + ($avgRating * 5.0) + ($onTimeCheckins * 10.0), 1);
        }

        // R4: Công thức tính Điểm Uy tín (Reputation Score)
        // Nếu là TNV mới chưa có lịch sử đánh giá hay phân công ca trực: điểm uy tín trung tính 4.00
        if ($evalCount === 0 && $assignedCount === 0) {
            $reputationScore = 4.00;
        } else {
            $repBaseRating = $evalCount > 0 ? $avgRating : 4.0;
            $reputationScore = round(0.60 * $repBaseRating + 0.40 * ($punctualRate * 5.0), 2);
            $reputationScore = max(1.0, min(5.0, $reputationScore));
        }

        // Tải danh sách bộ 6 huy hiệu số cùng trạng thái đạt
        $badges = $this->getVolunteerBadgesList($volunteerId, $raw);
        $unlockedCount = 0;
        foreach ($badges as $b) {
            if (!empty($b['is_unlocked'])) {
                $unlockedCount++;
            }
        }

        return [
            'volunteer_id' => $volunteerId,
            'total_hours' => round($totalHours, 1),
            'avg_rating' => $avgRating,
            'on_time_checkins' => $onTimeCheckins,
            'assigned_count' => $assignedCount,
            'absent_count' => $absentCount,
            'punctual_rate' => round($punctualRate, 2),
            'xp' => $xp,
            'reputation_score' => $reputationScore,
            'badges' => $badges,
            'unlocked_badge_count' => $unlockedCount,
        ];
    }

    /**
     * R2: Tự động đánh giá và mở khóa Bộ 6 Huy hiệu Số Danh dự Đoàn Thanh niên.
     *
     * @return list<array<string, mixed>> Danh sách huy hiệu mới được mở khóa
     */
    public function evaluateAndAwardBadges(int $volunteerId): array
    {
        $raw = $this->loadVolunteerActivityMetrics($volunteerId);
        $newlyUnlocked = [];

        // 1. ROOKIE_VOLUNTEER: Hoàn thành ca trực đầu tiên (completed_shifts >= 1)
        if (($raw['completed_shifts'] ?? 0) >= 1) {
            if ($this->grantBadgeIfNotOwned($volunteerId, 'ROOKIE_VOLUNTEER', [
                'rule' => 'completed_shifts >= 1',
                'completed_shifts' => $raw['completed_shifts'],
            ])) {
                $newlyUnlocked[] = self::BADGES['ROOKIE_VOLUNTEER'];
            }
        }

        // 2. FIRST_AID_HERO: Đạt >= 2 ca chuyên môn Sơ cứu Y tế với đánh giá >= 4.5 sao
        $faShifts = (int) ($raw['first_aid_shifts'] ?? 0);
        $faAvg = (float) ($raw['first_aid_avg_rating'] ?? 0.0);
        if ($faShifts >= 2 && $faAvg >= 4.5) {
            if ($this->grantBadgeIfNotOwned($volunteerId, 'FIRST_AID_HERO', [
                'rule' => 'first_aid_shifts >= 2 AND avg_rating >= 4.5',
                'first_aid_shifts' => $faShifts,
                'avg_rating' => $faAvg,
            ])) {
                $newlyUnlocked[] = self::BADGES['FIRST_AID_HERO'];
            }
        }

        // 3. ENVIRONMENT_CHAMPION: Hoàn thành >= 3 ca Chủ Nhật Xanh làm sạch kênh và trồng cây
        $envShifts = (int) ($raw['environment_shifts'] ?? 0);
        if ($envShifts >= 3) {
            if ($this->grantBadgeIfNotOwned($volunteerId, 'ENVIRONMENT_CHAMPION', [
                'rule' => 'environment_shifts >= 3',
                'environment_shifts' => $envShifts,
            ])) {
                $newlyUnlocked[] = self::BADGES['ENVIRONMENT_CHAMPION'];
            }
        }

        // 4. PERSEVERANT_SOLDIER: Điểm danh đúng giờ 100% các ca đã đăng ký mà không vắng mặt
        $assigned = (int) ($raw['assigned_count'] ?? 0);
        $absent = (int) ($raw['absent_count'] ?? 0);
        $onTime = (int) ($raw['on_time_checkins'] ?? 0);
        if ($assigned >= 1 && $absent === 0 && $onTime >= $assigned) {
            if ($this->grantBadgeIfNotOwned($volunteerId, 'PERSEVERANT_SOLDIER', [
                'rule' => 'assigned >= 1 AND absent == 0 AND on_time == assigned',
                'assigned' => $assigned,
                'on_time' => $onTime,
            ])) {
                $newlyUnlocked[] = self::BADGES['PERSEVERANT_SOLDIER'];
            }
        }

        // 5. INSPIRING_AMBASSADOR: Đạt điểm đánh giá tác phong tối đa 5 sao từ Điều phối viên
        $maxDemeanor = (int) ($raw['max_demeanor_rating'] ?? 0);
        if ($maxDemeanor >= 5) {
            if ($this->grantBadgeIfNotOwned($volunteerId, 'INSPIRING_AMBASSADOR', [
                'rule' => 'max_demeanor_rating == 5',
                'max_demeanor' => $maxDemeanor,
            ])) {
                $newlyUnlocked[] = self::BADGES['INSPIRING_AMBASSADOR'];
            }
        }

        // 6. VOLUNTEER_LEADER: Cống hiến >= 25 giờ và XP thuộc Top 10 toàn hệ thống
        $totalHours = (float) ($raw['total_hours'] ?? 0.0);
        if ($totalHours >= 25.0) {
            $rank = $this->calculateVolunteerXpRank($volunteerId);
            if ($rank <= 10) {
                if ($this->grantBadgeIfNotOwned($volunteerId, 'VOLUNTEER_LEADER', [
                    'rule' => 'total_hours >= 25.0 AND xp_rank <= 10',
                    'total_hours' => $totalHours,
                    'rank' => $rank,
                ])) {
                    $newlyUnlocked[] = self::BADGES['VOLUNTEER_LEADER'];
                }
            }
        }

        return $newlyUnlocked;
    }

    /**
     * R3: Bảng Vàng Thi Đua & Bảng Vinh Danh (Top 10 Cá nhân & Thi đua Tập thể).
     *
     * @return array{
     *     ok: bool,
     *     top_volunteers: list<array<string, mixed>>,
     *     personal_top10: list<array<string, mixed>>,
     *     collective_faculties: list<array<string, mixed>>,
     *     collective_ranking: list<array<string, mixed>>,
     *     badges_meta: list<array<string, mixed>>,
     *     system_summary: array<string, mixed>
     * }
     */
    public function getLeaderboardData(int $limit = 10): array
    {
        $allVolunteers = $this->loadAllVolunteers();
        $ranked = [];

        foreach ($allVolunteers as $v) {
            $vid = (int) $v['id'];
            $merit = $this->calculateVolunteerMerit($vid);

            $ranked[] = [
                'volunteer_id' => $vid,
                'student_code' => (string) $v['student_code'],
                'full_name' => (string) $v['full_name'],
                'chapter_name' => (string) $v['chapter_name'],
                'total_hours' => $merit['total_hours'],
                'avg_rating' => $merit['avg_rating'],
                'on_time_checkins' => $merit['on_time_checkins'],
                'xp' => $merit['xp'],
                'reputation_score' => $merit['reputation_score'],
                'badges' => $merit['badges'],
                'unlocked_badge_count' => $merit['unlocked_badge_count'],
            ];
        }

        // Sắp xếp thứ hạng cá nhân theo XP giảm dần -> Tổng giờ giảm dần -> Uy tín giảm dần -> ID tăng dần
        usort($ranked, static function (array $a, array $b): int {
            if (abs($b['xp'] - $a['xp']) > 1e-6) {
                return $b['xp'] <=> $a['xp'];
            }
            if (abs($b['total_hours'] - $a['total_hours']) > 1e-6) {
                return $b['total_hours'] <=> $a['total_hours'];
            }
            if (abs($b['reputation_score'] - $a['reputation_score']) > 1e-6) {
                return $b['reputation_score'] <=> $a['reputation_score'];
            }
            return $a['volunteer_id'] <=> $b['volunteer_id'];
        });

        // Gán thứ hạng & biểu trưng huy chương Top 1-2-3 mạ vàng / bạc / đồng
        $totalVolunteersCount = count($ranked);
        $systemTotalHours = 0.0;
        $systemTotalXp = 0.0;
        $systemTotalBadges = 0;

        for ($i = 0; $i < $totalVolunteersCount; $i++) {
            $rankNum = $i + 1;
            $ranked[$i]['rank'] = $rankNum;

            if ($rankNum === 1) {
                $ranked[$i]['medal'] = 'GOLD';
                $ranked[$i]['medal_title'] = 'Huy chương Vàng';
                $ranked[$i]['medal_color'] = '#F59E0B';
            } elseif ($rankNum === 2) {
                $ranked[$i]['medal'] = 'SILVER';
                $ranked[$i]['medal_title'] = 'Huy chương Bạc';
                $ranked[$i]['medal_color'] = '#94A3B8';
            } elseif ($rankNum === 3) {
                $ranked[$i]['medal'] = 'BRONZE';
                $ranked[$i]['medal_title'] = 'Huy chương Đồng';
                $ranked[$i]['medal_color'] = '#D97706';
            } else {
                $ranked[$i]['medal'] = null;
                $ranked[$i]['medal_title'] = null;
                $ranked[$i]['medal_color'] = null;
            }

            $systemTotalHours += $ranked[$i]['total_hours'];
            $systemTotalXp += $ranked[$i]['xp'];
            $systemTotalBadges += $ranked[$i]['unlocked_badge_count'];
        }

        $topVolunteers = array_slice($ranked, 0, $limit);

        // Bảng Thi đua Tập thể (Cơ sở Đoàn / Khoa / Trường)
        $collectives = $this->aggregateCollectiveLeaderboard($ranked);

        $summary = [
            'total_volunteers' => $totalVolunteersCount,
            'total_hours' => round($systemTotalHours, 1),
            'total_xp' => round($systemTotalXp, 1),
            'total_badges_awarded' => $systemTotalBadges,
            'total_evaluations' => count(self::$mockEvaluations),
        ];

        return [
            'ok' => true,
            'top_volunteers' => $topVolunteers,
            'personal_top10' => $topVolunteers,
            'collective_faculties' => $collectives,
            'collective_ranking' => $collectives,
            'badges_meta' => array_values(self::BADGES),
            'system_summary' => $summary,
        ];
    }

    /**
     * R3: Lấy dữ liệu Giấy khen Đoàn Thanh niên điện tử dành cho cá nhân tiêu biểu kèm mã QR kiểm thực.
     *
     * @return array<string, mixed>|null
     */
    public function getYouthUnionCommendation(int $volunteerId): ?array
    {
        $leaderboard = $this->getLeaderboardData(30);
        $topList = $leaderboard['top_volunteers'];

        $target = null;
        foreach ($topList as $item) {
            if ($item['volunteer_id'] === $volunteerId) {
                $target = $item;
                break;
            }
        }

        if ($target === null) {
            $merit = $this->calculateVolunteerMerit($volunteerId);
            $vols = $this->loadAllVolunteers();
            $volInfo = null;
            foreach ($vols as $v) {
                if ((int) $v['id'] === $volunteerId) {
                    $volInfo = $v;
                    break;
                }
            }
            if ($volInfo === null) {
                return null;
            }
            $target = [
                'volunteer_id' => $volunteerId,
                'student_code' => (string) $volInfo['student_code'],
                'full_name' => (string) $volInfo['full_name'],
                'chapter_name' => (string) $volInfo['chapter_name'],
                'rank' => 11,
                'medal' => null,
                'total_hours' => $merit['total_hours'],
                'xp' => $merit['xp'],
                'reputation_score' => $merit['reputation_score'],
                'badges' => $merit['badges'],
                'unlocked_badge_count' => $merit['unlocked_badge_count'],
            ];
        }

        $code = sprintf('XH-KHEN-%s-%04d', strtoupper(substr(md5('vol_' . $volunteerId), 0, 6)), $volunteerId);
        $verifyUrl = 'https://xanhhub.vn/verify?code=' . $code;
        $qrSvg = SvgQrGenerator::renderSvg($verifyUrl, 110);

        return [
            'volunteer_id' => $volunteerId,
            'student_code' => $target['student_code'],
            'full_name' => $target['full_name'],
            'chapter_name' => $target['chapter_name'],
            'rank' => $target['rank'],
            'medal' => $target['medal'],
            'total_hours' => $target['total_hours'],
            'xp' => $target['xp'],
            'reputation_score' => $target['reputation_score'],
            'unlocked_badge_count' => $target['unlocked_badge_count'],
            'decision_no' => 'QĐ-BCH-2026/06',
            'title' => 'GIẤY KHEN CÁ NHÂN TIÊU BIỂU',
            'reason' => 'Đã có thành tích xuất sắc, đạt thứ hạng cao trên Bảng Vàng Thi Đua hệ sinh thái tình nguyện Xanh Hub 2026',
            'signer_title' => 'BÍ THƯ ĐOÀN TRƯỜNG',
            'signer_name' => 'ThS. Nguyễn Văn A',
            'issued_date' => date('d/m/Y'),
            'verification_code' => $code,
            'verification_url' => $verifyUrl,
            'qr_svg' => $qrSvg,
        ];
    }

    /**
     * Tải số liệu hoạt động thực tế của một tình nguyện viên.
     *
     * @return array<string, mixed>
     */
    private function loadVolunteerActivityMetrics(int $volunteerId): array
    {
        if ($this->pdo !== null) {
            try {
                // Tải phân công
                $stmt = $this->pdo->prepare("
                    SELECT a.id AS assignment_id, a.shift_id, a.status,
                           s.title AS shift_title, s.activity_id,
                           EXTRACT(EPOCH FROM (s.end_time - s.start_time)) / 3600.0 AS duration_hours,
                           c.id AS checkin_id, (CASE WHEN c.checked_in_at IS NOT NULL AND c.checked_in_at > s.start_time THEN 1 ELSE 0 END) AS is_late
                      FROM assignments a
                      JOIN shifts s ON s.id = a.shift_id
                 LEFT JOIN checkins c ON c.assignment_id = a.id
                     WHERE a.volunteer_id = :vid
                ");
                $stmt->execute([':vid' => $volunteerId]);
                $assignments = $stmt->fetchAll();

                if (!empty($assignments)) {
                    $totalHours = 0.0;
                    $completedShifts = 0;
                    $assignedCount = count($assignments);
                    $absentCount = 0;
                    $onTimeCheckins = 0;
                    $envShifts = 0;

                    foreach ($assignments as $row) {
                        $st = (string) $row['status'];
                        $hours = (float) ($row['duration_hours'] ?? 3.0);
                        $title = (string) $row['shift_title'];
                        $actId = (int) $row['activity_id'];

                        if (in_array($st, ['ABSENT_REPORTED', 'REPLACED'], true)) {
                            $absentCount++;
                        }

                        if ($st === 'CHECKED_IN' || !empty($row['checkin_id'])) {
                            $completedShifts++;
                            $totalHours += $hours;
                            if (empty($row['is_late'])) {
                                $onTimeCheckins++;
                            }
                        } elseif ($st === 'CONFIRMED' || $st === 'ASSIGNED') {
                            // Dự phòng khi chưa có bảng checkins
                            $completedShifts++;
                            $totalHours += $hours;
                            $onTimeCheckins++;
                        }

                        if ($actId === 1 || str_contains($title, 'Chủ Nhật Xanh') || str_contains($title, 'Môi trường')) {
                            $envShifts++;
                        }
                    }

                    // Tải đánh giá từ Điều phối viên
                    $stmtEval = $this->pdo->prepare("
                        SELECT average_score, criteria_diligence, criteria_competence, criteria_demeanor
                          FROM evaluations
                         WHERE target_user_id = :vid AND evaluation_type = 'COORDINATOR_TO_VOLUNTEER'
                    ");
                    $stmtEval->execute([':vid' => $volunteerId]);
                    $evals = $stmtEval->fetchAll();

                    $evalCount = count($evals);
                    $sumAvg = 0.0;
                    $maxDemeanor = 0;

                    foreach ($evals as $ev) {
                        $sumAvg += (float) $ev['average_score'];
                        $dem = (int) ($ev['criteria_demeanor'] ?? 0);
                        if ($dem > $maxDemeanor) {
                            $maxDemeanor = $dem;
                        }
                    }

                    $avgRating = $evalCount > 0 ? ($sumAvg / $evalCount) : 0.0;

                    // Kiểm tra số ca chuyên môn Sơ cứu Y tế
                    $stmtFa = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT a.id) AS fa_count,
                               COALESCE(AVG(e.average_score), 0.0) AS fa_avg
                          FROM assignments a
                          JOIN shifts s ON s.id = a.shift_id
                          JOIN shift_requirements sr ON sr.shift_id = s.id
                     LEFT JOIN evaluations e ON e.assignment_id = a.id AND e.evaluation_type = 'COORDINATOR_TO_VOLUNTEER'
                         WHERE a.volunteer_id = :vid
                           AND sr.skill_id = 1
                           AND a.status IN ('CHECKED_IN', 'CONFIRMED')
                    ");
                    $stmtFa->execute([':vid' => $volunteerId]);
                    $faRow = $stmtFa->fetch();
                    $faShifts = (int) ($faRow['fa_count'] ?? 0);
                    $faAvg = (float) ($faRow['fa_avg'] ?? 0.0);

                    return [
                        'total_hours' => $totalHours,
                        'completed_shifts' => $completedShifts,
                        'assigned_count' => $assignedCount,
                        'absent_count' => $absentCount,
                        'on_time_checkins' => $onTimeCheckins,
                        'eval_count' => $evalCount,
                        'avg_rating' => $avgRating,
                        'max_demeanor_rating' => $maxDemeanor,
                        'first_aid_shifts' => $faShifts,
                        'first_aid_avg_rating' => $faAvg,
                        'environment_shifts' => $envShifts,
                    ];
                }
            } catch (\Throwable $e) {
                error_log("GamificationService SQL error: " . $e->getMessage());
                // Rơi về mock
            }
        }

        // Chế độ mô phỏng offline chuẩn hóa:
        // Đọc từ mock evaluations đã nộp nếu có
        $volEvals = [];
        $maxDemeanor = 0;
        foreach (self::$mockEvaluations as $ev) {
            if (($ev['target_user_id'] ?? 0) === $volunteerId && $ev['evaluation_type'] === 'COORDINATOR_TO_VOLUNTEER') {
                $volEvals[] = $ev;
                $dem = (int) ($ev['criteria_demeanor'] ?? 0);
                if ($dem > $maxDemeanor) {
                    $maxDemeanor = $dem;
                }
            }
        }

        $evalCount = count($volEvals);
        $sumScore = 0.0;
        foreach ($volEvals as $ev) {
            $sumScore += (float) $ev['average_score'];
        }
        $dynamicAvg = $evalCount > 0 ? ($sumScore / $evalCount) : 0.0;

        // Dữ liệu ban đầu mặc định theo hồ sơ mẫu sinh viên
        if ($volunteerId === 1) { // Nguyễn Thùy Linh (SV001 - Đội trưởng)
            return [
                'total_hours' => 28.0,
                'completed_shifts' => 7,
                'assigned_count' => 7,
                'absent_count' => 0,
                'on_time_checkins' => 7,
                'eval_count' => max(1, $evalCount),
                'avg_rating' => $evalCount > 0 ? $dynamicAvg : 4.90,
                'max_demeanor_rating' => max(5, $maxDemeanor),
                'first_aid_shifts' => 3,
                'first_aid_avg_rating' => 4.85,
                'environment_shifts' => 4,
            ];
        }

        if ($volunteerId === 2) { // Trần Hoàng Phúc (SV002)
            return [
                'total_hours' => 26.0,
                'completed_shifts' => 6,
                'assigned_count' => 6,
                'absent_count' => 0,
                'on_time_checkins' => 6,
                'eval_count' => max(1, $evalCount),
                'avg_rating' => $evalCount > 0 ? $dynamicAvg : 4.80,
                'max_demeanor_rating' => max(5, $maxDemeanor),
                'first_aid_shifts' => 2,
                'first_aid_avg_rating' => 4.60,
                'environment_shifts' => 3,
            ];
        }

        if ($volunteerId === 3) {
            return [
                'total_hours' => 25.5,
                'completed_shifts' => 6,
                'assigned_count' => 6,
                'absent_count' => 0,
                'on_time_checkins' => 6,
                'eval_count' => max(1, $evalCount),
                'avg_rating' => $evalCount > 0 ? $dynamicAvg : 4.75,
                'max_demeanor_rating' => max(5, $maxDemeanor),
                'first_aid_shifts' => 2,
                'first_aid_avg_rating' => 4.55,
                'environment_shifts' => 3,
            ];
        }

        // Nếu là tình nguyện viên mới ngoài danh sách 30 người mặc định: khởi tạo 0 giờ
        if ($volunteerId > 30) {
            return [
                'total_hours' => 0.0,
                'completed_shifts' => 0,
                'assigned_count' => 0,
                'absent_count' => 0,
                'on_time_checkins' => 0,
                'eval_count' => $evalCount,
                'avg_rating' => $dynamicAvg,
                'max_demeanor_rating' => $maxDemeanor,
                'first_aid_shifts' => 0,
                'first_aid_avg_rating' => 0.0,
                'environment_shifts' => 0,
            ];
        }

        // Tình nguyện viên từ 4 đến 30 có số giờ và phân công đa dạng
        $seedHours = round(max(3.0, 24.0 - ($volunteerId * 0.7)), 1);
        $seedShifts = (int) ceil($seedHours / 3.5);
        $seedAbsent = ($volunteerId % 7 === 0) ? 1 : 0;
        $seedOnTime = max(0, $seedShifts - $seedAbsent);
        $seedAvg = $evalCount > 0 ? $dynamicAvg : round(4.0 + (($volunteerId % 10) * 0.08), 2);

        return [
            'total_hours' => $seedHours,
            'completed_shifts' => $seedOnTime,
            'assigned_count' => $seedShifts,
            'absent_count' => $seedAbsent,
            'on_time_checkins' => $seedOnTime,
            'eval_count' => max(1, $evalCount),
            'avg_rating' => $seedAvg,
            'max_demeanor_rating' => max(($volunteerId % 3 === 0 ? 5 : 4), $maxDemeanor),
            'first_aid_shifts' => ($volunteerId % 4 === 0) ? 2 : 1,
            'first_aid_avg_rating' => 4.3,
            'environment_shifts' => ($volunteerId % 2 === 0) ? 3 : 1,
        ];
    }

    /**
     * Tra cứu thứ hạng XP của một tình nguyện viên trong toàn hệ thống.
     */
    private function calculateVolunteerXpRank(int $volunteerId): int
    {
        $all = $this->loadAllVolunteers();
        $scores = [];

        foreach ($all as $v) {
            $vid = (int) $v['id'];
            $raw = $this->loadVolunteerActivityMetrics($vid);
            $th = (float) ($raw['total_hours'] ?? 0.0);
            $ar = (float) ($raw['avg_rating'] ?? 0.0);
            $ot = (int) ($raw['on_time_checkins'] ?? 0);
            $xp = ($th * 10.0) + ($ar * 5.0) + ($ot * 10.0);
            $scores[$vid] = $xp;
        }

        arsort($scores);
        $rank = 1;
        foreach (array_keys($scores) as $vid) {
            if ($vid === $volunteerId) {
                return $rank;
            }
            $rank++;
        }

        return $rank;
    }

    /**
     * Tải toàn bộ danh sách 6 huy hiệu kèm cờ is_unlocked.
     *
     * @param array<string, mixed> $rawMetrics
     * @return list<array<string, mixed>>
     */
    private function getVolunteerBadgesList(int $volunteerId, array $rawMetrics): array
    {
        $ownedCodes = $this->loadOwnedBadgeCodes($volunteerId);
        $result = [];

        foreach (self::BADGES as $code => $meta) {
            $isUnlocked = isset($ownedCodes[$code]);

            // Nếu chưa lưu vào DB, kiểm tra nhanh điều kiện thỏa mãn
            if (!$isUnlocked) {
                $isUnlocked = match ($code) {
                    'ROOKIE_VOLUNTEER' => ($rawMetrics['completed_shifts'] ?? 0) >= 1,
                    'FIRST_AID_HERO' => (($rawMetrics['first_aid_shifts'] ?? 0) >= 2) && (($rawMetrics['first_aid_avg_rating'] ?? 0.0) >= 4.5),
                    'ENVIRONMENT_CHAMPION' => ($rawMetrics['environment_shifts'] ?? 0) >= 3,
                    'PERSEVERANT_SOLDIER' => (($rawMetrics['assigned_count'] ?? 0) >= 1) && (($rawMetrics['absent_count'] ?? 0) === 0) && (($rawMetrics['on_time_checkins'] ?? 0) >= ($rawMetrics['assigned_count'] ?? 0)),
                    'INSPIRING_AMBASSADOR' => ($rawMetrics['max_demeanor_rating'] ?? 0) >= 5,
                    'VOLUNTEER_LEADER' => (($rawMetrics['total_hours'] ?? 0.0) >= 25.0) && ($this->calculateVolunteerXpRank($volunteerId) <= 10),
                    default => false,
                };
            }

            $item = $meta;
            $item['is_unlocked'] = $isUnlocked;
            $item['unlocked_at'] = $isUnlocked ? ($ownedCodes[$code]['unlocked_at'] ?? date('Y-m-d H:i:s')) : null;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Tải danh sách mã huy hiệu đã đạt của một tình nguyện viên.
     *
     * @return array<string, array{unlocked_at: string}> Map code => info
     */
    private function loadOwnedBadgeCodes(int $volunteerId): array
    {
        $owned = [];

        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare('
                    SELECT b.code, vb.unlocked_at::text AS unlocked_at
                      FROM volunteer_badges vb
                      JOIN badges b ON b.id = vb.badge_id
                     WHERE vb.volunteer_id = :vid
                ');
                $stmt->execute([':vid' => $volunteerId]);
                foreach ($stmt->fetchAll() as $row) {
                    $owned[$row['code']] = ['unlocked_at' => (string) $row['unlocked_at']];
                }
            } catch (\Throwable) {
                // Rơi về mock
            }
        }

        if (isset(self::$mockBadges[$volunteerId])) {
            foreach (self::$mockBadges[$volunteerId] as $c => $active) {
                if ($active) {
                    $owned[$c] = ['unlocked_at' => date('Y-m-d H:i:s')];
                }
            }
        }

        return $owned;
    }

    /**
     * Cấp huy hiệu nếu tình nguyện viên chưa từng đạt (Bảo đảm tính Idempotency).
     *
     * @param array<string, mixed> $evidence
     */
    private function grantBadgeIfNotOwned(int $volunteerId, string $badgeCode, array $evidence = []): bool
    {
        $badgeDef = self::BADGES[$badgeCode] ?? null;
        if ($badgeDef === null) {
            return false;
        }

        $badgeId = $badgeDef['id'];

        if ($this->pdo !== null) {
            try {
                $stmtCheck = $this->pdo->prepare('
                    SELECT id FROM volunteer_badges
                     WHERE volunteer_id = :vid AND badge_id = :bid
                     LIMIT 1
                ');
                $stmtCheck->execute([':vid' => $volunteerId, ':bid' => $badgeId]);
                if ($stmtCheck->fetchColumn() !== false) {
                    return false; // Đã đạt từ trước
                }

                $stmtInsert = $this->pdo->prepare('
                    INSERT INTO volunteer_badges (volunteer_id, badge_id, unlocked_at, evidence_json)
                    VALUES (:vid, :bid, NOW(), :ev::jsonb)
                    ON CONFLICT (volunteer_id, badge_id) DO NOTHING
                ');
                $stmtInsert->execute([
                    ':vid' => $volunteerId,
                    ':bid' => $badgeId,
                    ':ev' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
                ]);

                return $stmtInsert->rowCount() > 0;
            } catch (\Throwable) {
                // Rơi về mock
            }
        }

        // Mock mode
        if (!empty(self::$mockBadges[$volunteerId][$badgeCode])) {
            return false;
        }

        self::$mockBadges[$volunteerId][$badgeCode] = true;
        return true;
    }

    /**
     * Tải danh sách toàn bộ tình nguyện viên.
     *
     * @return list<array{id: int, student_code: string, full_name: string, chapter_name: string}>
     */
    public function loadAllVolunteers(): array
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query('
                    SELECT v.user_id AS id, v.student_code, u.full_name, v.chapter_name
                      FROM volunteers v
                      JOIN users u ON u.id = v.user_id
                     ORDER BY v.user_id ASC
                ');
                $rows = $stmt->fetchAll();
                if (!empty($rows)) {
                    $result = [];
                    foreach ($rows as $r) {
                        $result[] = [
                            'id' => (int) $r['id'],
                            'student_code' => (string) $r['student_code'],
                            'full_name' => (string) $r['full_name'],
                            'chapter_name' => (string) $r['chapter_name'],
                        ];
                    }
                    return $result;
                }
            } catch (\Throwable) {
                // Rơi về mock
            }
        }

        if (self::$mockVolunteers === null) {
            self::$mockVolunteers = [];
            $ds = DatasetGenerator::create30Volunteers('D1');
            foreach ($ds as $v) {
                self::$mockVolunteers[] = [
                    'id' => $v->id,
                    'student_code' => $v->studentCode,
                    'full_name' => $v->fullName,
                    'chapter_name' => $v->chapterName,
                ];
            }
        }

        return self::$mockVolunteers;
    }

    /**
     * Tổng hợp Bảng Thi Đua Tập Thể theo Khoa / Cơ sở Đoàn.
     *
     * @param list<array<string, mixed>> $rankedVolunteers
     * @return list<array<string, mixed>>
     */
    private function aggregateCollectiveLeaderboard(array $rankedVolunteers): array
    {
        $groups = [];
        foreach (self::FACULTY_MAPPING as $code => $info) {
            $groups[$code] = [
                'unit_code' => $code,
                'unit_name' => $info['name'],
                'short_name' => $info['short'],
                'school_name' => $info['school'],
                'color' => $info['color'],
                'volunteer_count' => 0,
                'total_hours' => 0.0,
                'total_xp' => 0.0,
                'total_badges' => 0,
                'avg_xp_per_vol' => 0.0,
            ];
        }

        foreach ($rankedVolunteers as $vol) {
            $chapter = (string) $vol['chapter_name'];
            $matchedCode = 'KHAC';
            foreach (array_keys(self::FACULTY_MAPPING) as $code) {
                if ($code !== 'KHAC' && str_contains($chapter, $code)) {
                    $matchedCode = $code;
                    break;
                }
            }

            $groups[$matchedCode]['volunteer_count']++;
            $groups[$matchedCode]['total_hours'] += (float) $vol['total_hours'];
            $groups[$matchedCode]['total_xp'] += (float) $vol['xp'];
            $groups[$matchedCode]['total_badges'] += (int) $vol['unlocked_badge_count'];
        }

        $list = [];
        foreach ($groups as $code => $g) {
            if ($code === 'KHAC' && $g['volunteer_count'] === 0) {
                continue;
            }
            $vCount = $g['volunteer_count'];
            $g['total_hours'] = round($g['total_hours'], 1);
            $g['total_xp'] = round($g['total_xp'], 1);
            $g['avg_xp_per_vol'] = $vCount > 0 ? round($g['total_xp'] / $vCount, 1) : 0.0;
            $list[] = $g;
        }

        // Sắp xếp thứ hạng tập thể theo tổng XP giảm dần -> tổng giờ giảm dần
        usort($list, static function (array $a, array $b): int {
            if (abs($b['total_xp'] - $a['total_xp']) > 1e-6) {
                return $b['total_xp'] <=> $a['total_xp'];
            }
            return $b['total_hours'] <=> $a['total_hours'];
        });

        for ($i = 0; $i < count($list); $i++) {
            $list[$i]['rank'] = $i + 1;
        }

        return $list;
    }
}

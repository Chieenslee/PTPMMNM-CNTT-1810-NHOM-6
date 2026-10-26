<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Scheduling\FifoCheckedScheduler;
use XanhHub\Domain\Scheduling\FifoUncheckedScheduler;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

echo "[Xanh Hub] Bắt đầu khởi tạo dữ liệu mẫu chuẩn D1, D2, D3, D4...\n";

$migrationFile = dirname(__DIR__) . '/database/migrations/001_init_schema.sql';
Database::migrate($migrationFile);

$pdo = Database::getConnection();
$repo = new XanhHubRepository($pdo);

$pdo->beginTransaction();

try {
    // 1. Nạp 5 kỹ năng chuẩn
    $stmtSkill = $pdo->prepare('
        INSERT INTO skills (id, code, name, description)
        VALUES (:id, :code, :name, :desc)
    ');
    foreach (DatasetGenerator::standardSkills() as $sk) {
        $stmtSkill->execute([
            ':id' => $sk['id'],
            ':code' => $sk['code'],
            ':name' => $sk['name'],
            ':desc' => $sk['description'],
        ]);
    }
    $pdo->exec("SELECT setval('skills_id_seq', (SELECT MAX(id) FROM skills))");

    // 2. Nạp 30 tình nguyện viên (ID 1..30) + Điều phối viên Thảo (ID 31) + Quản trị Đoàn Cô Hương (ID 32)
    $defaultPassHash = password_hash('XanhHub@2026', PASSWORD_ARGON2ID);
    $volunteers = DatasetGenerator::create30Volunteers('D1');

    $stmtUser = $pdo->prepare('
        INSERT INTO users (id, email, password_hash, full_name, role)
        VALUES (:id, :email, :hash, :name, :role)
    ');
    $stmtVol = $pdo->prepare('
        INSERT INTO volunteers (user_id, student_code, chapter_name, phone, max_shifts_per_week, lat, lng)
        VALUES (:uid, :code, :chapter, :phone, :max_s, :lat, :lng)
    ');
    $stmtVolSkill = $pdo->prepare('
        INSERT INTO volunteer_skills (volunteer_id, skill_id, level, is_verified)
        VALUES (:vid, :sid, :lvl, :ver)
    ');
    $stmtAvail = $pdo->prepare('
        INSERT INTO availability (volunteer_id, day_of_week, start_time, end_time, is_recurring)
        VALUES (:vid, :dow, :st, :et, TRUE)
    ');

    foreach ($volunteers as $vol) {
        $email = strtolower($vol->studentCode) . '@example.test';
        $phone = sprintf('0900000%03d', $vol->id);

        $stmtUser->execute([
            ':id' => $vol->id,
            ':email' => $email,
            ':hash' => $defaultPassHash,
            ':name' => $vol->fullName,
            ':role' => 'VOLUNTEER',
        ]);

        $stmtVol->execute([
            ':uid' => $vol->id,
            ':code' => $vol->studentCode,
            ':chapter' => $vol->chapterName,
            ':phone' => $phone,
            ':max_s' => $vol->maxShiftsPerWeek,
            ':lat' => $vol->lat,
            ':lng' => $vol->lng,
        ]);

        foreach ($vol->skills as $skillId => $sInfo) {
            $stmtVolSkill->execute([
                ':vid' => $vol->id,
                ':sid' => $skillId,
                ':lvl' => $sInfo['level'],
                ':ver' => $sInfo['is_verified'] ? 'true' : 'false',
            ]);
        }

        foreach ($vol->availabilitySlots as $slot) {
            $sh = intdiv($slot->startMinuteOfDay, 60);
            $sm = $slot->startMinuteOfDay % 60;
            $eh = intdiv($slot->endMinuteOfDay, 60);
            $em = $slot->endMinuteOfDay % 60;
            $stmtAvail->execute([
                ':vid' => $vol->id,
                ':dow' => $slot->dayOfWeek,
                ':st' => sprintf('%02d:%02d:00', $sh, $sm),
                ':et' => sprintf('%02d:%02d:00', $eh, $em),
            ]);
        }
    }

    // Thêm tài khoản Điều phối viên Thảo (ID 31) và Quản trị Đoàn Cô Hương (ID 32)
    $stmtUser->execute([
        ':id' => 31,
        ':email' => 'thao.dieuphoi@example.test',
        ':hash' => $defaultPassHash,
        ':name' => 'Nguyễn Phương Thảo (Điều phối viên)',
        ':role' => 'COORDINATOR',
    ]);
    $stmtUser->execute([
        ':id' => 32,
        ':email' => 'huong.quantri@example.test',
        ':hash' => $defaultPassHash,
        ':name' => 'Cô Lê Thu Hương (Cán bộ Đoàn)',
        ':role' => 'ADMIN',
    ]);
    $stmtUser->execute([
        ':id' => 33,
        ':email' => 'admin@xanhhub.vn',
        ':hash' => $defaultPassHash,
        ':name' => 'Quản trị viên Hệ thống (Thành Đoàn)',
        ':role' => 'ADMIN',
    ]);
    $pdo->exec("SELECT setval('users_id_seq', (SELECT MAX(id) FROM users))");

    // 3. Tạo 3 Hoạt động tương ứng với 3 bộ dữ liệu bắt buộc D1, D2, D3
    $activitiesMeta = [
        1 => ['D1', 'Chiến dịch Chủ Nhật Xanh (Bộ D1: Đủ người)',       'Khuôn viên Đại học & Công viên Trung tâm', DatasetGenerator::createD1SufficientDataset(1)],
        2 => ['D2', 'Mùa Hè Xanh Vùng Cao (Bộ D2: Thiếu người)',        'Điểm trường Vùng cao & Trạm Y tế Xã',      DatasetGenerator::createD2UnderstaffedDataset(2)],
        3 => ['D3', 'Tiếp Sức Mùa Thi 2026 (Bộ D3: Trùng lịch nhiều)',  'Cụm Điểm thi Bách Khoa & Khoa học Tự nhiên', DatasetGenerator::createD3HighOverlapDataset(3)],
    ];

    $stmtAct = $pdo->prepare('
        INSERT INTO activities (id, code, title, description, location_name, status, withdraw_deadline_hours, created_by)
        VALUES (:id, :code, :title, :desc, :loc, \'OPEN\', 24, 31)
    ');
    $stmtCoord = $pdo->prepare('
        INSERT INTO activity_coordinators (activity_id, user_id) VALUES (:aid, 31)
    ');
    $stmtShift = $pdo->prepare('
        INSERT INTO shifts (id, activity_id, code, title, start_time, end_time, during, min_people, max_people, priority, location_name, lat, lng)
        VALUES (:id, :aid, :code, :title, :st::timestamptz, :et::timestamptz, tstzrange(:st::timestamptz, :et::timestamptz, \'[)\'), :minp, :maxp, :pri, :loc, :lat, :lng)
    ');
    $stmtReq = $pdo->prepare('
        INSERT INTO shift_requirements (id, shift_id, skill_id, min_level, required_count, require_verified)
        VALUES (:id, :sid, :skid, :minl, :rcnt, :reqv)
    ');
    $stmtReg = $pdo->prepare('
        INSERT INTO registrations (activity_id, shift_id, volunteer_id, preference_rank, status, registered_at)
        VALUES (:aid, :sid, :vid, :pref, :status, :regat::timestamptz)
    ');

    foreach ($activitiesMeta as $actId => [$code, $title, $loc, $ds]) {
        $stmtAct->execute([
            ':id' => $actId,
            ':code' => $code,
            ':title' => $title,
            ':desc' => 'Hoạt động chuẩn phục vụ kiểm thử và trình diễn Đề tài 6 - Xanh Hub (' . $code . ').',
            ':loc' => $loc,
        ]);
        $stmtCoord->execute([':aid' => $actId]);

        foreach ($ds->shifts as $s) {
            $stmtShift->execute([
                ':id' => $s->id,
                ':aid' => $actId,
                ':code' => $s->code,
                ':title' => $s->title,
                ':st' => $s->startTimeIso,
                ':et' => $s->endTimeIso,
                ':minp' => $s->minPeople,
                ':maxp' => $s->maxPeople,
                ':pri' => $s->priority,
                ':loc' => $s->locationName,
                ':lat' => $s->lat,
                ':lng' => $s->lng,
            ]);

            foreach ($s->requirements as $r) {
                $stmtReq->execute([
                    ':id' => $r->id,
                    ':sid' => $s->id,
                    ':skid' => $r->skillId,
                    ':minl' => $r->minLevel,
                    ':rcnt' => $r->requiredCount,
                    ':reqv' => $r->requireVerified ? 'true' : 'false',
                ]);
            }
        }

        foreach ($ds->registrations as $reg) {
            $stmtReg->execute([
                ':aid' => $actId,
                ':sid' => $reg->shiftId,
                ':vid' => $reg->volunteerId,
                ':pref' => $reg->preferenceRank,
                ':status' => $reg->status,
                ':regat' => $reg->registeredAtIso,
            ]);
        }
    }

    $pdo->exec("SELECT setval('activities_id_seq', (SELECT MAX(id) FROM activities))");
    $pdo->exec("SELECT setval('shifts_id_seq', (SELECT MAX(id) FROM shifts))");
    $pdo->exec("SELECT setval('shift_requirements_id_seq', (SELECT MAX(id) FROM shift_requirements))");
    $pdo->exec("SELECT setval('registrations_id_seq', (SELECT MAX(id) FROM registrations))");

    // 4. Chạy cả 3 phương án B0, B1, X trên từng bộ dữ liệu D1, D2, D3 và lưu vào PostgreSQL
    $schedB0 = new FifoUncheckedScheduler();
    $schedB1 = new FifoCheckedScheduler();
    $schedX  = new XanhHubScheduler();

    $fixtureDir = dirname(__DIR__) . '/tests/fixtures';
    $expectedDir = dirname(__DIR__) . '/tests/expected';
    $publicDataDir = dirname(__DIR__) . '/public/assets/data';
    foreach ([$fixtureDir, $expectedDir, $publicDataDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    $summaryReport = [];

    foreach ([1 => 'D1', 2 => 'D2', 3 => 'D3'] as $actId => $dCode) {
        // Đối với D2, dùng đúng bộ nhớ của D2 (vì ở D2 kỹ năng cấp 4 hiếm hơn)
        $dataset = ($dCode === 'D2')
            ? DatasetGenerator::createD2UnderstaffedDataset(2)
            : $repo->loadDatasetForActivity($actId);

        $mode = ($dCode === 'D1') ? SchedulerWeights::MODE_FAIRNESS : SchedulerWeights::MODE_MAX_COVERAGE;
        $planB0 = $schedB0->schedule($dataset, 202606);
        $planB1 = $schedB1->schedule($dataset, 202606);
        $planX  = $schedX->schedule($dataset, SchedulerWeights::fromMode($mode, 202606));

        $repo->savePlanOutput($dataset, $planB0, 31);
        $repo->savePlanOutput($dataset, $planB1, 31);
        $planXId = $repo->savePlanOutput($dataset, $planX, 31);

        // Áp dụng phương án X cho cả D1, D2 và D3 để có sẵn dữ liệu phân công trên bảng điều phối (D2 giữ nguyên 3 ca thiếu người S04, S08, S12)
        if (in_array($dCode, ['D1', 'D2', 'D3'], true)) {
            $repo->applyPlanToAssignments($planXId, 31);
        }

        // Lưu tệp JSON fixture và expected cho bộ kiểm thử
        file_put_contents(
            "{$fixtureDir}/dataset_{$dCode}.json",
            json_encode($dataset->toCanonicalArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        file_put_contents(
            "{$expectedDir}/expected_{$dCode}.json",
            json_encode([
                'dataset_code' => $dCode,
                'snapshot_hash' => $planX->snapshotHash,
                'B0_metrics' => $planB0->metrics,
                'B1_metrics' => $planB1->metrics,
                'X_metrics'  => $planX->metrics,
                'X_unassigned_slots' => array_values(array_filter(
                    array_map(static fn($u) => $u->toArray(), $planX->unassigned),
                    static fn(array $u) => $u['item_type'] === 'SLOT'
                )),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($dCode === 'D3') {
            // Xuất tệp JSON thật của bộ D3 cho Sân khấu Canvas 6 cảnh ở Trang chủ (Cảnh 2 B0 -> Cảnh 3 X)
            file_put_contents(
                "{$publicDataDir}/d3_stage_comparison.json",
                json_encode([
                    'dataset_code' => 'D3',
                    'snapshot_hash' => $planX->snapshotHash,
                    'shifts' => array_map(static fn($s) => $s->toArray(), $dataset->shifts),
                    'volunteers' => array_map(static fn($v) => [
                        'id' => $v->id,
                        'student_code' => $v->studentCode,
                        'full_name' => $v->fullName,
                        'chapter_name' => $v->chapterName,
                    ], $dataset->volunteers),
                    'B0' => $planB0->toArray(),
                    'B1' => $planB1->toArray(),
                    'X'  => $planX->toArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        $summaryReport[$dCode] = [
            'B0' => [
                'headcount_cov' => $planB0->metrics['headcount_coverage_rate'] . '%',
                'valid_cov'     => $planB0->metrics['valid_coverage_rate'] . '%',
                'violations'    => $planB0->metrics['total_violations'],
                'std_dev'       => $planB0->metrics['load_std_dev'],
            ],
            'B1' => [
                'headcount_cov' => $planB1->metrics['headcount_coverage_rate'] . '%',
                'valid_cov'     => $planB1->metrics['valid_coverage_rate'] . '%',
                'violations'    => $planB1->metrics['total_violations'],
                'std_dev'       => $planB1->metrics['load_std_dev'],
            ],
            'X' => [
                'headcount_cov' => $planX->metrics['headcount_coverage_rate'] . '%',
                'valid_cov'     => $planX->metrics['valid_coverage_rate'] . '%',
                'violations'    => $planX->metrics['total_violations'],
                'std_dev'       => $planX->metrics['load_std_dev'],
            ],
        ];
    }

    // 5. Tạo chứng nhận mẫu cho Nguyễn Thùy Linh (SV001) để demo tra cứu công khai
    $pdo->exec("
        INSERT INTO certificates (certificate_code, volunteer_id, activity_id, total_hours, issued_by)
        VALUES ('XH2026-SV001-9F8A2C', 1, 3, 12.0, 32),
               ('XH2026-SV002-7B4D1E', 2, 3, 9.0, 32)
    ");

    $pdo->commit();

    echo "[Xanh Hub] Nạp dữ liệu mẫu thành công! Bảng đối chiếu kết quả KT2:\n";
    foreach ($summaryReport as $code => $algos) {
        echo "  === Bộ dữ liệu {$code} ===\n";
        foreach ($algos as $algo => $m) {
            echo sprintf(
                "    %-3s | Đủ đầu người: %-7s | Đủ hợp lệ: %-7s | Vi phạm: %-3d | Độ lệch chuẩn tải: %.4f\n",
                $algo,
                $m['headcount_cov'],
                $m['valid_cov'],
                $m['violations'],
                $m['std_dev']
            );
        }
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[LỖI SEED] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

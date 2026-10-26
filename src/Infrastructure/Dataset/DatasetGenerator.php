<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Dataset;

use XanhHub\Domain\Model\AvailabilitySlot;
use XanhHub\Domain\Model\RegistrationSpec;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\VolunteerSpec;

/**
 * Bộ sinh dữ liệu mẫu tất định (Deterministic Synthetic Dataset Generator) cho Xanh Hub.
 * Chỉ dùng dữ liệu giả theo đúng quy định an toàn của đề bài:
 * - D1 (Đủ người: 30 TNV, 12 ca, 5 kỹ năng)
 * - D2 (Thiếu người: 30 TNV, 12 ca, 5 kỹ năng - đúng 3 ca thiếu người do kỹ năng hiếm)
 * - D3 (Trùng lịch nhiều: 30 TNV, 12 ca, 5 kỹ năng - tích hợp bài toán Linh – Phúc)
 * - D4 (Benchmark lớn: 300 TNV, 60 ca, 12 kỹ năng)
 * - LINH_PHUC (Ví dụ số nhỏ giải thích thuật toán khi vấn đáp)
 */
final class DatasetGenerator
{
    public const SKILL_FIRST_AID = 1; // Sơ cứu
    public const SKILL_DRIVING   = 2; // Lái xe
    public const SKILL_TEACHING  = 3; // Dạy học
    public const SKILL_MEDIA     = 4; // Truyền thông
    public const SKILL_LOGISTICS = 5; // Hậu cần

    /**
     * @return list<array{id: int, code: string, name: string, description: string}>
     */
    public static function standardSkills(): array
    {
        return [
            ['id' => 1, 'code' => 'FIRST_AID', 'name' => 'Sơ cứu y tế', 'description' => 'Sơ cấp cứu ban đầu, hỗ trợ y tế điểm thi và chiến dịch'],
            ['id' => 2, 'code' => 'DRIVING',   'name' => 'Lái xe & Điều tiết', 'description' => 'Đưa đón thí sinh, vận chuyển vật tư, điều phối giao thông'],
            ['id' => 3, 'code' => 'TEACHING',  'name' => 'Dạy học & Sinh hoạt', 'description' => 'Dạy học tình nguyện, tổ chức sinh hoạt thiếu nhi'],
            ['id' => 4, 'code' => 'MEDIA',     'name' => 'Truyền thông & Ảnh', 'description' => 'Chụp ảnh, viết tin bài, thiết kế ấn phẩm chiến dịch'],
            ['id' => 5, 'code' => 'LOGISTICS', 'name' => 'Hậu cần & Dựng trại', 'description' => 'Dựng lều trại, chuẩn bị nước uống, hậu cần sự kiện'],
        ];
    }

    /**
     * Ví dụ số nhỏ Linh – Phúc ở Mục 5.3 để giải thích trực tiếp khi vấn đáp bảo vệ.
     */
    public static function createLinhPhucMiniDataset(): ScheduleDataset
    {
        $baseTs = strtotime('2026-11-07 00:00:00'); // Thứ Bảy (dayOfWeek = 6)

        $shiftA = new ShiftSpec(
            id: 101,
            code: 'CA-A',
            title: 'Trực Sơ cứu Điểm thi A (08:00–11:00)',
            startEpoch: $baseTs + 8 * 3600,
            endEpoch: $baseTs + 11 * 3600,
            startTimeIso: '2026-11-07T08:00:00+07:00',
            endTimeIso: '2026-11-07T11:00:00+07:00',
            minPeople: 1,
            maxPeople: 1,
            priority: 5,
            requirements: [
                new SkillRequirementSpec(1001, 101, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', 2, 1, true),
            ]
        );

        $shiftB = new ShiftSpec(
            id: 102,
            code: 'CA-B',
            title: 'Trực Hậu cần Điểm thi B (09:00–12:00)',
            startEpoch: $baseTs + 9 * 3600,
            endEpoch: $baseTs + 12 * 3600,
            startTimeIso: '2026-11-07T09:00:00+07:00',
            endTimeIso: '2026-11-07T12:00:00+07:00',
            minPeople: 1,
            maxPeople: 1,
            priority: 3,
            requirements: [
                new SkillRequirementSpec(1002, 102, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 1, 1, false),
            ]
        );

        $linh = new VolunteerSpec(
            id: 1,
            studentCode: 'SV001',
            fullName: 'Nguyễn Thùy Linh',
            chapterName: 'Chi đoàn KTPM-01',
            maxShiftsPerWeek: 3,
            skills: [
                self::SKILL_FIRST_AID => ['level' => 3, 'is_verified' => true],
                self::SKILL_LOGISTICS => ['level' => 2, 'is_verified' => true],
            ],
            availabilitySlots: [
                AvailabilitySlot::fromHm(6, '07:00', '18:00'),
            ]
        );

        $phuc = new VolunteerSpec(
            id: 2,
            studentCode: 'SV002',
            fullName: 'Trần Hoàng Phúc',
            chapterName: 'Chi đoàn KTPM-01',
            maxShiftsPerWeek: 3,
            skills: [
                self::SKILL_LOGISTICS => ['level' => 2, 'is_verified' => true],
            ],
            availabilitySlots: [
                AvailabilitySlot::fromHm(6, '07:00', '18:00'),
            ]
        );

        // Linh nộp đơn Ca B trước (08:00), rồi mới nộp Ca A (08:05); Phúc nộp Ca B lúc (08:10).
        $registrations = [
            new RegistrationSpec(1, 102, 1, 1, $baseTs - 86400 + 60, '2026-11-06T08:01:00+07:00'),
            new RegistrationSpec(2, 101, 1, 1, $baseTs - 86400 + 300, '2026-11-06T08:05:00+07:00'),
            new RegistrationSpec(3, 102, 2, 1, $baseTs - 86400 + 600, '2026-11-06T08:10:00+07:00'),
        ];

        return new ScheduleDataset(
            activityId: 99,
            datasetCode: 'LINH_PHUC',
            shifts: [$shiftA, $shiftB],
            volunteers: [$linh, $phuc],
            registrations: $registrations
        );
    }

    /**
     * Sinh 30 tình nguyện viên chuẩn cho D1, D2, D3.
     *
     * @return list<VolunteerSpec>
     */
    public static function create30Volunteers(string $mode = 'D1'): array
    {
        $names = [
            1  => ['SV001', 'Nguyễn Thùy Linh',   'Chi đoàn KTPM-01'],
            2  => ['SV002', 'Trần Hoàng Phúc',    'Chi đoàn KTPM-01'],
            3  => ['SV003', 'Lê Minh Châu',       'Chi đoàn KTPM-02'],
            4  => ['SV004', 'Phạm Quốc Bảo',      'Chi đoàn KTPM-02'],
            5  => ['SV005', 'Hoàng Ngọc Ánh',     'Chi đoàn HTTT-01'],
            6  => ['SV006', 'Vũ Đức Thịnh',       'Chi đoàn HTTT-01'],
            7  => ['SV007', 'Đặng Phương Thảo',   'Chi đoàn KHMT-01'],
            8  => ['SV008', 'Bùi Tuấn Kiệt',      'Chi đoàn KHMT-01'],
            9  => ['SV009', 'Đỗ Khánh Huyền',     'Chi đoàn MMT-01'],
            10 => ['SV010', 'Ngô Gia Huy',        'Chi đoàn MMT-01'],
            11 => ['SV011', 'Dương Thanh Tùng',   'Chi đoàn KTPM-03'],
            12 => ['SV012', 'Lý Mỹ Duyên',        'Chi đoàn KTPM-03'],
            13 => ['SV013', 'Trương Đình Nam',    'Chi đoàn ATTT-01'],
            14 => ['SV014', 'Phan Bảo Ngọc',      'Chi đoàn ATTT-01'],
            15 => ['SV015', 'Võ Minh Khôi',       'Chi đoàn KHDL-01'],
            16 => ['SV016', 'Mai Thanh Trúc',     'Chi đoàn KHDL-01'],
            17 => ['SV017', 'Đinh Quốc Khánh',    'Chi đoàn KTPM-01'],
            18 => ['SV018', 'Hồ Thảo Nguyên',     'Chi đoàn KTPM-02'],
            19 => ['SV019', 'Lương Gia Bảo',      'Chi đoàn HTTT-02'],
            20 => ['SV020', 'Châu Ngọc Hân',      'Chi đoàn HTTT-02'],
            21 => ['SV021', 'Tạ Minh Đức',        'Chi đoàn KHMT-02'],
            22 => ['SV022', 'Phùng Yến Nhi',      'Chi đoàn KHMT-02'],
            23 => ['SV023', 'Tô Hải Đăng',        'Chi đoàn MMT-02'],
            24 => ['SV024', 'Đoàn Thu Hà',        'Chi đoàn MMT-02'],
            25 => ['SV025', 'Quách Tuấn Anh',     'Chi đoàn ATTT-02'],
            26 => ['SV026', 'Lâm Bảo Trân',       'Chi đoàn ATTT-02'],
            27 => ['SV027', 'Huỳnh Nhật Minh',    'Chi đoàn KHDL-02'],
            28 => ['SV028', 'Trịnh Khánh Linh',   'Chi đoàn KHDL-02'],
            29 => ['SV029', 'Hà Trọng Nghĩa',     'Chi đoàn KTPM-03'],
            30 => ['SV030', 'Kiều Phương Trang',  'Chi đoàn KTPM-03'],
        ];

        $volunteers = [];
        foreach ($names as $id => [$code, $fullName, $chapter]) {
            $skills = [];
            // Mọi tình nguyện viên đều có kỹ năng Hậu cần cấp 2..3
            $skills[self::SKILL_LOGISTICS] = ['level' => ($id % 2 === 0 ? 2 : 3), 'is_verified' => true];

            // Phân bổ kỹ năng chuyên môn rõ ràng:
            // Nhóm 1..6: Chuyên gia Sơ cứu (FIRST_AID cấp 3..4)
            if ($id >= 1 && $id <= 6) {
                $lvl = ($mode === 'D2' && $id > 2) ? 3 : 4; // Ở D2, chỉ có 2 người đạt Sơ cứu cấp 4 (còn lại cấp 3)!
                $skills[self::SKILL_FIRST_AID] = ['level' => $lvl, 'is_verified' => true];
            }
            // Nhóm 2, 7..12: Kỹ năng Lái xe (DRIVING cấp 3..4)
            if ($id === 2 || ($id >= 7 && $id <= 12)) {
                $lvl = ($mode === 'D2' && $id > 7) ? 3 : 4; // Ở D2, chỉ có 2 người đạt Lái xe cấp 4 (còn lại cấp 3)!
                $skills[self::SKILL_DRIVING] = ['level' => $lvl, 'is_verified' => true];
            }
            // Nhóm 13..18: Kỹ năng Dạy học (TEACHING cấp 3..4)
            if ($id >= 13 && $id <= 18) {
                $skills[self::SKILL_TEACHING] = ['level' => 3 + ($id % 2), 'is_verified' => true];
            }
            // Nhóm 1, 19..24: Kỹ năng Truyền thông (MEDIA cấp 3..4)
            if ($id === 1 || ($id >= 19 && $id <= 24)) {
                $skills[self::SKILL_MEDIA] = ['level' => 3 + ($id % 2), 'is_verified' => true];
            }
            // Nhóm 25..30: Bổ sung Dạy học & Truyền thông cấp 2..3
            if ($id >= 25) {
                $skills[self::SKILL_TEACHING] = ['level' => 3, 'is_verified' => true];
                $skills[self::SKILL_MEDIA] = ['level' => 3, 'is_verified' => true];
            }

            // Lịch rảnh từ Thứ Hai (1) đến Chủ Nhật (7), khung 07:00 - 21:00
            // Riêng người số 29, 30 bận sáng Chủ Nhật để kiểm tra H4 (NO_AVAILABLE)
            $avail = [];
            for ($d = 1; $d <= 7; $d++) {
                if ($d === 7 && ($id === 29 || $id === 30)) {
                    $avail[] = AvailabilitySlot::fromHm($d, '13:00', '21:00');
                } else {
                    $avail[] = AvailabilitySlot::fromHm($d, '07:00', '21:00');
                }
            }

            $volunteers[] = new VolunteerSpec(
                id: $id,
                studentCode: $code,
                fullName: $fullName,
                chapterName: $chapter,
                maxShiftsPerWeek: 4,
                skills: $skills,
                availabilitySlots: $avail,
                absentRiskProbability: round((($id * 7) % 25) / 100.0, 2)
            );
        }

        return $volunteers;
    }

    /**
     * Bộ dữ liệu D1: Đủ người (30 tình nguyện viên, 12 ca, 5 kỹ năng).
     * Mọi ca đều có thể lấp đầy hợp lệ (12/12 ca), nhưng B0 vẫn vi phạm do lấy đơn đăng ký trái kỹ năng/trùng giờ nộp sớm,
     * và ở chế độ FAIRNESS thuật toán X giảm trên 40% độ lệch chuẩn số ca so với B1.
     */
    public static function createD1SufficientDataset(int $activityId = 1): ScheduleDataset
    {
        $volunteers = self::create30Volunteers('D1');
        // 12 ca trải đều trong 3 ngày (Thứ Sáu, Thứ Bảy, Chủ Nhật), mỗi ngày 4 ca
        // Để kiểm tra trùng giờ nhẹ cho B0, Ca 1 & Ca 2 giao nhau 1 tiếng, các ca khác tách biệt
        $shifts = self::build12Shifts($activityId, mode: 'D1');

        $regs = [];
        $regId = 1;
        $baseRegTs = strtotime('2026-11-01 08:00:00');

        // 1. Tạo một số đơn đăng ký sớm của người thiếu kỹ năng chuyên môn hoặc đăng ký 2 ca giao nhau (Ca 1 & Ca 2)
        // để B0 (thứ tự đăng ký thuần) bộc lộ vi phạm NO_SKILL và TIME_CONFLICT:
        $regs[] = new RegistrationSpec($regId++, $shifts[0]->id, 25, 1, $baseRegTs + 10, date('c', $baseRegTs + 10)); // SV025 không có FIRST_AID đăng ký Ca 1 sớm nhất
        $regs[] = new RegistrationSpec($regId++, $shifts[0]->id, 1,  1, $baseRegTs + 20, date('c', $baseRegTs + 20)); // Linh đăng ký Ca 1 (08:00-11:00)
        $regs[] = new RegistrationSpec($regId++, $shifts[1]->id, 1,  2, $baseRegTs + 25, date('c', $baseRegTs + 25)); // Linh đăng ký cả Ca 2 trùng giờ (09:30-12:00) -> B0 sẽ gán trùng giờ!

        // 2. Để B1 dồn nhiều ca vào một vài người đăng ký sớm (gây lệch tải cao), cho một nhóm nhỏ nộp đơn sớm vào nhiều ca:
        $heavyEarlyMap = [
            1 => [0, 4, 8, 10], // SV001 (FIRST_AID)
            7 => [2, 6, 9, 11], // SV007 (DRIVING)
            13 => [3, 5, 7],    // SV013 (TEACHING)
            19 => [1, 5, 9],    // SV019 (MEDIA)
        ];
        $offset = 100;
        foreach ($heavyEarlyMap as $volId => $shiftIndices) {
            foreach ($shiftIndices as $sIdx) {
                $shift = $shifts[$sIdx];
                if (!self::hasReg($regs, $shift->id, $volId)) {
                    $regs[] = new RegistrationSpec($regId++, $shift->id, $volId, 1, $baseRegTs + $offset, date('c', $baseRegTs + $offset));
                    $offset += 10;
                }
            }
        }

        // 3. Tiếp theo, toàn bộ 30 tình nguyện viên đăng ký vào các ca phù hợp kỹ năng của mình để Xanh Hub có thể vừa lấp kín 12/12 ca vừa chia đều số ca:
        foreach ($shifts as $sIdx => $shift) {
            $req = $shift->requirements[0];
            foreach ($volunteers as $vol) {
                if ($vol->satisfiesRequirement($req) && $vol->isAvailableForShift($shift)) {
                    if (!self::hasReg($regs, $shift->id, $vol->id)) {
                        $pref = (($vol->id + $sIdx) % 3) + 1;
                        $regs[] = new RegistrationSpec(
                            $regId++,
                            $shift->id,
                            $vol->id,
                            $pref,
                            $baseRegTs + $offset,
                            date('c', $baseRegTs + $offset)
                        );
                        $offset += 5;
                    }
                }
            }
        }

        return new ScheduleDataset(
            activityId: $activityId,
            datasetCode: 'D1',
            shifts: $shifts,
            volunteers: $volunteers,
            registrations: $regs
        );
    }

    /**
     * Bộ dữ liệu D2: Thiếu người (30 tình nguyện viên, 12 ca, 5 kỹ năng).
     * Đúng 3 ca (Ca 4, Ca 8, Ca 12) yêu cầu kỹ năng hiếm cấp 4 (Sơ cứu cấp 4 cần 3 người trong khi chỉ có 2 người đạt cấp 4;
     * Lái xe cấp 4 cần 3 người trong khi chỉ có 2 người đạt cấp 4).
     * Kết quả kỳ vọng:
     * - Đúng 9/12 ca đủ người hợp lệ, đúng 3 ca thiếu người được báo cáo trong unassigned kèm mã NO_SKILL.
     * - Không tạo thêm người giả.
     */
    public static function createD2UnderstaffedDataset(int $activityId = 2): ScheduleDataset
    {
        $volunteers = self::create30Volunteers('D2');
        $shifts = self::build12Shifts($activityId, mode: 'D2');

        $regs = [];
        $regId = 1;
        $baseRegTs = strtotime('2026-11-01 08:00:00');
        $offset = 10;

        // Cho một vài người cấp 2 nộp đơn vào ca đòi cấp 4 để B0 gán sai kỹ năng (NO_SKILL), còn X từ chối đúng luật
        foreach ([3, 7, 11] as $rareIdx) {
            $rareShift = $shifts[$rareIdx];
            foreach ([3, 4, 8, 9, 10] as $lowSkillVolId) {
                $regs[] = new RegistrationSpec(
                    $regId++,
                    $rareShift->id,
                    $lowSkillVolId,
                    1,
                    $baseRegTs + $offset,
                    date('c', $baseRegTs + $offset)
                );
                $offset += 5;
            }
        }

        // Đăng ký tất cả ứng viên đủ kỹ năng vào các ca
        foreach ($shifts as $sIdx => $shift) {
            $req = $shift->requirements[0];
            foreach ($volunteers as $vol) {
                if ($vol->satisfiesRequirement($req) && $vol->isAvailableForShift($shift)) {
                    if (!self::hasReg($regs, $shift->id, $vol->id)) {
                        $regs[] = new RegistrationSpec(
                            $regId++,
                            $shift->id,
                            $vol->id,
                            (($vol->id + $sIdx) % 3) + 1,
                            $baseRegTs + $offset,
                            date('c', $baseRegTs + $offset)
                        );
                        $offset += 5;
                    }
                }
            }
        }

        return new ScheduleDataset(
            activityId: $activityId,
            datasetCode: 'D2',
            shifts: $shifts,
            volunteers: $volunteers,
            registrations: $regs
        );
    }

    /**
     * Bộ dữ liệu D3: Nhiều trường hợp trùng lịch (30 tình nguyện viên, 12 ca, 5 kỹ năng).
     * Các cặp ca (Ca 1 & Ca 2, Ca 3 & Ca 4, Ca 5 & Ca 6, Ca 7 & Ca 8, Ca 9 & Ca 10, Ca 11 & Ca 12) diễn ra trùng giờ nhau!
     * Trong mỗi cặp:
     * - Ca lẻ (Ca 1, 3, 5, 7, 9, 11) đòi kỹ năng chuyên môn hiếm (Sơ cứu, Lái xe, Dạy học, Truyền thông).
     * - Ca chẵn (Ca 2, 4, 6, 8, 10, 12) chỉ đòi kỹ năng Hậu cần phổ thông.
     * Các tình nguyện viên có kỹ năng chuyên môn (như Linh SV001) lại nộp đơn vào Ca chẵn (Hậu cần) TRƯỚC, rồi mới nộp đơn vào Ca lẻ (Chuyên môn),
     * trong khi các tình nguyện viên chỉ biết Hậu cần (như Phúc SV002) nộp đơn vào Ca chẵn SAU!
     *
     * Kết quả chứng minh trực quan KT1 & KT2:
     * - B0 (Thứ tự thuần): Gán cùng một người vào cả 2 ca trùng giờ -> Hàng loạt vi phạm TIME_CONFLICT!
     * - B1 (Thứ tự có kiểm tra): Không vi phạm, nhưng gán hết chuyên gia vào ca Hậu cần trước -> Đến ca Chuyên môn thì chuyên gia đã bận, người còn lại không có kỹ năng -> Thiếu hụt nghiêm trọng (chỉ đạt ~6/12 ca)!
     * - X (Xanh Hub): Nhận diện ca Chuyên môn khan hiếm ứng viên hơn (và dùng chuỗi đổi chỗ Kuhn) -> Xếp chuyên gia vào ca Chuyên môn, xếp người Hậu cần vào ca Hậu cần -> Đạt 12/12 ca (100%), 0 vi phạm!
     */
    public static function createD3HighOverlapDataset(int $activityId = 3): ScheduleDataset
    {
        $volunteers = self::create30Volunteers('D3');
        $shifts = self::build12Shifts($activityId, mode: 'D3');

        $regs = [];
        $regId = 1;
        $baseRegTs = strtotime('2026-11-01 08:00:00');
        $offset = 10;

        // Xử lý từng cặp ca trùng giờ (0 & 1, 2 & 3, 4 & 5, 6 & 7, 8 & 9, 10 & 11)
        for ($pair = 0; $pair < 6; $pair++) {
            $specialistShift = $shifts[$pair * 2];     // Ca chuyên môn (Sơ cứu / Lái xe / Dạy học / Truyền thông)
            $logisticsShift  = $shifts[$pair * 2 + 1]; // Ca hậu cần trùng giờ với ca chuyên môn

            $specReq = $specialistShift->requirements[0];

            // Tìm các chuyên gia thỏa mãn ca chuyên môn này
            $specialists = [];
            $generalists = [];
            foreach ($volunteers as $vol) {
                if (!$vol->isAvailableForShift($specialistShift)) {
                    continue;
                }
                if ($vol->satisfiesRequirement($specReq)) {
                    $specialists[] = $vol;
                } else {
                    $generalists[] = $vol;
                }
            }

            // Chọn đúng số lượng chuyên gia vừa khít cho ca chuyên môn để tạo độ khan hiếm thực sự
            $neededSpec = $specialistShift->minPeople;
            $chosenSpecialists = array_slice($specialists, ($pair % 2) * 2, $neededSpec);
            if (count($chosenSpecialists) < $neededSpec) {
                $chosenSpecialists = array_slice($specialists, 0, $neededSpec);
            }

            // Bước A: Các chuyên gia này nộp đơn vào ca HẬU CẦN trước!
            foreach ($chosenSpecialists as $sp) {
                $regs[] = new RegistrationSpec(
                    $regId++,
                    $logisticsShift->id,
                    $sp->id,
                    2, // Nguyện vọng 2 ở ca hậu cần nhưng nộp đơn SỚM HƠN
                    $baseRegTs + $offset,
                    date('c', $baseRegTs + $offset)
                );
                $offset += 5;
            }

            // Bước B: Các chuyên gia nộp đơn vào ca CHUYÊN MÔN trùng giờ ngay sau đó (Nguyện vọng 1)
            foreach ($chosenSpecialists as $sp) {
                $regs[] = new RegistrationSpec(
                    $regId++,
                    $specialistShift->id,
                    $sp->id,
                    1,
                    $baseRegTs + $offset,
                    date('c', $baseRegTs + $offset)
                );
                $offset += 5;
            }

            // Bước C: Một người không có kỹ năng chuyên môn cũng nộp đơn vào ca chuyên môn (để B0 dính thêm lỗi NO_SKILL)
            if (isset($generalists[$pair])) {
                $gWrong = $generalists[$pair];
                $regs[] = new RegistrationSpec(
                    $regId++,
                    $specialistShift->id,
                    $gWrong->id,
                    1,
                    $baseRegTs + $offset,
                    date('c', $baseRegTs + $offset)
                );
                $offset += 5;
            }

            // Bước D: Các tình nguyện viên Hậu cần nộp đơn vào ca Hậu cần SAU các chuyên gia
            $chosenGeneralists = array_slice($generalists, $pair * 2, $logisticsShift->maxPeople + 2);
            if (count($chosenGeneralists) < $logisticsShift->maxPeople) {
                $chosenGeneralists = array_slice($generalists, 0, $logisticsShift->maxPeople + 2);
            }
            foreach ($chosenGeneralists as $gen) {
                if (!self::hasReg($regs, $logisticsShift->id, $gen->id)) {
                    $regs[] = new RegistrationSpec(
                        $regId++,
                        $logisticsShift->id,
                        $gen->id,
                        1,
                        $baseRegTs + $offset,
                        date('c', $baseRegTs + $offset)
                    );
                    $offset += 5;
                }
            }
        }

        return new ScheduleDataset(
            activityId: $activityId,
            datasetCode: 'D3',
            shifts: $shifts,
            volunteers: $volunteers,
            registrations: $regs
        );
    }

    /**
     * Bộ dữ liệu D4: Benchmark lớn (300 tình nguyện viên, 60 ca, 12 kỹ năng), sinh tất định từ hạt cố định.
     */
    public static function createD4BenchmarkDataset(int $activityId = 4, int $seed = 202606): ScheduleDataset
    {
        mt_srand($seed);

        $volunteers = [];
        for ($i = 1; $i <= 300; $i++) {
            $skills = [
                self::SKILL_LOGISTICS => ['level' => mt_rand(2, 5), 'is_verified' => true],
            ];
            $primarySkill = ($i % 12) + 1;
            $secondarySkill = (($i + 4) % 12) + 1;
            $skills[$primarySkill] = ['level' => mt_rand(3, 5), 'is_verified' => true];
            $skills[$secondarySkill] = ['level' => mt_rand(2, 4), 'is_verified' => true];

            $avail = [];
            for ($d = 1; $d <= 7; $d++) {
                $avail[] = AvailabilitySlot::fromHm($d, '07:00', '21:00');
            }

            $volunteers[] = new VolunteerSpec(
                id: $i,
                studentCode: sprintf('SV%04d', $i),
                fullName: sprintf('Tình nguyện viên %03d', $i),
                chapterName: sprintf('Chi đoàn K%02d', ($i % 15) + 1),
                maxShiftsPerWeek: 4,
                skills: $skills,
                availabilitySlots: $avail
            );
        }

        $shifts = [];
        $baseDay = strtotime('2026-11-02 00:00:00'); // Thứ Hai
        $reqId = 5000;
        for ($s = 1; $s <= 60; $s++) {
            $dayOffset = intdiv($s - 1, 9); // 7 ngày
            $slotInDay = ($s - 1) % 9;
            $startHour = 7 + ($slotInDay % 3) * 4;
            $startEpoch = $baseDay + $dayOffset * 86400 + $startHour * 3600;
            $endEpoch = $startEpoch + 3 * 3600;
            $skillId = (($s - 1) % 12) + 1;
            $minP = 3;
            $maxP = 4;

            $shifts[] = new ShiftSpec(
                id: 400 + $s,
                code: sprintf('D4-CA%02d', $s),
                title: sprintf('Ca chiến dịch mở rộng #%02d', $s),
                startEpoch: $startEpoch,
                endEpoch: $endEpoch,
                startTimeIso: date('c', $startEpoch),
                endTimeIso: date('c', $endEpoch),
                minPeople: $minP,
                maxPeople: $maxP,
                priority: ($s % 5) + 1,
                requirements: [
                    new SkillRequirementSpec($reqId++, 400 + $s, $skillId, 'SKILL_' . $skillId, 'Kỹ năng #' . $skillId, 2, $maxP, false),
                ]
            );
        }

        $regs = [];
        $regId = 1;
        $baseRegTs = strtotime('2026-10-25 08:00:00');
        foreach ($shifts as $shift) {
            $req = $shift->requirements[0];
            foreach ($volunteers as $vol) {
                if ($vol->satisfiesRequirement($req) && mt_rand(1, 100) <= 35) {
                    $regs[] = new RegistrationSpec(
                        $regId++,
                        $shift->id,
                        $vol->id,
                        mt_rand(1, 3),
                        $baseRegTs + mt_rand(1, 50000),
                        date('c', $baseRegTs + $regId)
                    );
                }
            }
        }

        return new ScheduleDataset(
            activityId: $activityId,
            datasetCode: 'D4',
            shifts: $shifts,
            volunteers: $volunteers,
            registrations: $regs
        );
    }

    /**
     * @return list<ShiftSpec>
     */
    private static function build12Shifts(int $activityId, string $mode): array
    {
        // Ngày cơ sở: mỗi bộ dữ liệu (D1, D2, D3) nằm ở một tuần độc lập (Thứ Sáu, Thứ Bảy, Chủ Nhật)
        $weekOffset = max(0, $activityId - 1) * 7 * 86400;
        $fri = strtotime('2026-11-06 00:00:00') + $weekOffset;
        $sat = strtotime('2026-11-07 00:00:00') + $weekOffset;
        $sun = strtotime('2026-11-08 00:00:00') + $weekOffset;

        $idOffset = ($activityId - 1) * 100;
        $reqOffset = ($activityId - 1) * 1000;

        if ($mode === 'D3') {
            // Ở bộ D3 (Trùng lịch nhiều): 6 cặp ca trùng giờ nhau hoàn toàn hoặc một phần
            $specs = [
                // Cặp 1: Sáng Thứ Sáu (Ca 1 Sơ cứu 08:00–11:00 trùng giờ Ca 2 Hậu cần 09:00–12:00 - Bài toán Linh - Phúc mở rộng)
                [1, 'CA-01', 'Trực Sơ cứu Điểm thi Bách Khoa', $fri + 8 * 3600,  $fri + 11 * 3600, 2, 3, 5, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', 3, 2, true],
                [2, 'CA-02', 'Hậu cần & Tiếp nước Điểm thi A', $fri + 9 * 3600,  $fri + 12 * 3600, 3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Cặp 2: Chiều Thứ Sáu (Ca 3 Lái xe 13:30–16:30 trùng giờ Ca 4 Hậu cần 14:00–17:00)
                [3, 'CA-03', 'Đội Xe tình nguyện đưa đón thí sinh', $fri + 13.5 * 3600, $fri + 16.5 * 3600, 2, 3, 5, self::SKILL_DRIVING, 'DRIVING', 'Lái xe & Điều tiết', 3, 2, true],
                [4, 'CA-04', 'Dựng nhà bạt & Hậu cần chiều Thứ Sáu', $fri + 14 * 3600,   $fri + 17 * 3600,   3, 4, 2, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Cặp 3: Sáng Thứ Bảy (Ca 5 Dạy học 08:00–11:00 trùng giờ Ca 6 Hậu cần 08:30–11:30)
                [5, 'CA-05', 'Lớp học tình nguyện cho thiếu nhi', $sat + 8 * 3600,   $sat + 11 * 3600,   2, 3, 4, self::SKILL_TEACHING, 'TEACHING', 'Dạy học & Sinh hoạt', 3, 2, false],
                [6, 'CA-06', 'Phát suất ăn & Nước uống sáng Thứ Bảy', $sat + 8.5 * 3600, $sat + 11.5 * 3600, 3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Cặp 4: Chiều Thứ Bảy (Ca 7 Truyền thông 13:30–16:30 trùng giờ Ca 8 Hậu cần 14:00–17:00)
                [7, 'CA-07', 'Phóng viên ảnh & Truyền thông chiến dịch', $sat + 13.5 * 3600, $sat + 16.5 * 3600, 2, 3, 4, self::SKILL_MEDIA, 'MEDIA', 'Truyền thông & Ảnh', 3, 2, false],
                [8, 'CA-08', 'Vận chuyển vật tư & Dọn vệ sinh chiều T7', $sat + 14 * 3600,   $sat + 17 * 3600,   3, 4, 2, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Cặp 5: Sáng Chủ Nhật (Ca 9 Sơ cứu 08:00–11:00 trùng giờ Ca 10 Hậu cần 08:30–11:30)
                [9,  'CA-09', 'Trực Y tế Ngày hội Chủ Nhật Xanh', $sun + 8 * 3600,   $sun + 11 * 3600,   2, 3, 5, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', 3, 2, true],
                [10, 'CA-10', 'Trồng cây & Thu gom rác sáng Chủ Nhật', $sun + 8.5 * 3600, $sun + 11.5 * 3600, 3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Cặp 6: Chiều Chủ Nhật (Ca 11 Lái xe 13:30–16:30 trùng giờ Ca 12 Hậu cần 14:00–17:00)
                [11, 'CA-11', 'Điều tiết giao thông & Xe chở vật tư', $sun + 13.5 * 3600, $sun + 16.5 * 3600, 2, 3, 4, self::SKILL_DRIVING, 'DRIVING', 'Lái xe & Điều tiết', 3, 2, true],
                [12, 'CA-12', 'Tổng kết & Thu dọn trại tập kết',      $sun + 14 * 3600,   $sun + 17 * 3600,   3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
            ];
        } else {
            // Ở bộ D1 (Đủ người) và D2 (Thiếu người ở đúng 3 ca số 4, 8, 12):
            $isD2 = ($mode === 'D2');
            $specs = [
                [1,  'CA-01', 'Trực Sơ cứu Điểm thi A (Sáng T6)',        $fri + 7.5 * 3600,  $fri + 10.5 * 3600, 2, 3, 5, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', 3, 2, true],
                [2,  'CA-02', 'Truyền thông Lễ ra quân (Sáng T6)',       $fri + 9.5 * 3600,  $fri + 12 * 3600,   2, 3, 4, self::SKILL_MEDIA,     'MEDIA',     'Truyền thông & Ảnh', 3, 2, false],
                [3,  'CA-03', 'Đội Xe đón thí sinh (Chiều T6)',          $fri + 13 * 3600,   $fri + 16 * 3600,   2, 3, 4, self::SKILL_DRIVING,   'DRIVING',   'Lái xe & Điều tiết', 3, 2, true],
                // Ca 4: ở D2 đòi 4 chuyên gia Sơ cứu cấp 4 (trong khi toàn bộ 30 TNV ở D2 chỉ có 2 người đạt cấp 4!) -> Thiếu người!
                [4,  'CA-04', 'Trạm Cấp cứu Lưu động Chuyên sâu (Tối T6)', $fri + 17 * 3600, $fri + 20 * 3600, $isD2 ? 4 : 2, $isD2 ? 4 : 3, 5, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', $isD2 ? 4 : 2, $isD2 ? 4 : 2, true],

                [5,  'CA-05', 'Lớp học tình nguyện (Sáng T7)',           $sat + 7.5 * 3600,  $sat + 10.5 * 3600, 3, 4, 4, self::SKILL_TEACHING,  'TEACHING',  'Dạy học & Sinh hoạt', 3, 3, false],
                [6,  'CA-06', 'Hậu cần Trạm tiếp sức (Trưa T7)',         $sat + 10.5 * 3600, $sat + 13.5 * 3600, 3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                [7,  'CA-07', 'Dạy học & Sinh hoạt hè (Chiều T7)',       $sat + 14 * 3600,   $sat + 17 * 3600,   3, 4, 3, self::SKILL_TEACHING,  'TEACHING',  'Dạy học & Sinh hoạt', 3, 3, false],
                // Ca 8: ở D2 đòi 4 tài xế Lái xe cấp 4 (trong khi ở D2 chỉ có 2 người đạt cấp 4!) -> Thiếu người!
                [8,  'CA-08', 'Đoàn Xe Vận chuyển Vùng cao (Tối T7)',    $sat + 17.5 * 3600, $sat + 20.5 * 3600, $isD2 ? 4 : 2, $isD2 ? 4 : 3, 5, self::SKILL_DRIVING, 'DRIVING', 'Lái xe & Điều tiết', $isD2 ? 4 : 2, $isD2 ? 4 : 2, true],

                [9,  'CA-09', 'Truyền thông Ngày hội Xanh (Sáng CN)',    $sun + 7.5 * 3600,  $sun + 10.5 * 3600, 2, 3, 4, self::SKILL_MEDIA,     'MEDIA',     'Truyền thông & Ảnh', 3, 2, false],
                [10, 'CA-10', 'Dọn vệ sinh & Trồng cây (Trưa CN)',       $sun + 10.5 * 3600, $sun + 13.5 * 3600, 3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                [11, 'CA-11', 'Hậu cần Tổng kết Chiến dịch (Chiều CN)',  $sun + 14 * 3600,   $sun + 17 * 3600,   3, 4, 3, self::SKILL_LOGISTICS, 'LOGISTICS', 'Hậu cần & Dựng trại', 2, 3, false],
                // Ca 12: ở D2 đòi 4 chuyên gia Sơ cứu cấp 4 -> Thiếu người!
                [12, 'CA-12', 'Trực Y tế Khẩn cấp Đêm Tổng kết (Tối CN)', $sun + 17.5 * 3600, $sun + 20.5 * 3600, $isD2 ? 4 : 2, $isD2 ? 4 : 3, 5, self::SKILL_FIRST_AID, 'FIRST_AID', 'Sơ cứu y tế', $isD2 ? 4 : 2, $isD2 ? 4 : 2, true],
            ];
        }

        $shifts = [];
        foreach ($specs as $row) {
            [$idx, $code, $title, $startFloat, $endFloat, $minP, $maxP, $pri, $skillId, $skillCode, $skillName, $minLvl, $reqCount, $reqVerified] = $row;
            $startEpoch = (int) round($startFloat);
            $endEpoch = (int) round($endFloat);
            $shiftId = $idOffset + $idx;
            $reqId = $reqOffset + $idx;

            $canon = \XanhHub\Infrastructure\Service\GisService::CANONICAL_LOCATIONS[$shiftId] ?? null;
            $lat = $canon !== null ? $canon['lat'] : 10.7769;
            $lng = $canon !== null ? $canon['lng'] : 106.7009;
            $locName = $canon !== null ? $canon['location'] : ('Điểm hoạt động Thanh niên #' . $idx);

            $shifts[] = new ShiftSpec(
                id: $shiftId,
                code: $code,
                title: $title,
                startEpoch: $startEpoch,
                endEpoch: $endEpoch,
                startTimeIso: date('c', $startEpoch),
                endTimeIso: date('c', $endEpoch),
                minPeople: $minP,
                maxPeople: $maxP,
                priority: $pri,
                requirements: [
                    new SkillRequirementSpec(
                        id: $reqId,
                        shiftId: $shiftId,
                        skillId: $skillId,
                        skillCode: $skillCode,
                        skillName: $skillName,
                        minLevel: $minLvl,
                        requiredCount: $maxP,
                        requireVerified: $reqVerified
                    ),
                ],
                isLocked: false,
                lat: $lat,
                lng: $lng,
                locationName: $locName
            );
        }

        return $shifts;
    }

    /**
     * @param list<RegistrationSpec> $regs
     */
    private static function hasReg(array $regs, int $shiftId, int $volunteerId): bool
    {
        foreach ($regs as $r) {
            if ($r->shiftId === $shiftId && $r->volunteerId === $volunteerId) {
                return true;
            }
        }
        return false;
    }
}

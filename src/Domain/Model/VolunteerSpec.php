<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class VolunteerSpec
{
    /**
     * @param array<int, array{level: int, is_verified: bool}> $skills Map skill_id => ['level' => 1..5, 'is_verified' => bool]
     * @param list<AvailabilitySlot> $availabilitySlots
     */
    public function __construct(
        public readonly int $id,
        public readonly string $studentCode,
        public readonly string $fullName,
        public readonly string $chapterName,
        public readonly int $maxShiftsPerWeek,
        public readonly array $skills,
        public readonly array $availabilitySlots,
        public readonly float $absentRiskProbability = 0.0,
        public readonly float $lat = 10.7769,
        public readonly float $lng = 106.7009,
        public readonly float $reputationScore = 4.0
    ) {
    }

    public function skillLevel(int $skillId, bool $requireVerified = false): int
    {
        if (!isset($this->skills[$skillId])) {
            return 0;
        }
        $entry = $this->skills[$skillId];
        if ($requireVerified && !$entry['is_verified']) {
            return 0;
        }
        return (int) $entry['level'];
    }

    public function satisfiesRequirement(SkillRequirementSpec $req): bool
    {
        return $this->skillLevel($req->skillId, $req->requireVerified) >= $req->minLevel;
    }

    public function skillWasteFor(SkillRequirementSpec $req): int
    {
        $lvl = $this->skillLevel($req->skillId, $req->requireVerified);
        return max(0, $lvl - $req->minLevel);
    }

    /**
     * Kiểm tra ca trực có nằm trong khung lịch rảnh của tình nguyện viên (đã xét ngày bận đột xuất) hay không.
     */
    public function isAvailableForShift(ShiftSpec $shift): bool
    {
        $dateYmd = $shift->dateYmd();
        $dayOfWeek = $shift->dayOfWeekIso();
        $shiftStart = $shift->startMinutesOfDay();
        $shiftEnd = $shift->endMinutesOfDay();

        // 1. Kiểm tra ngày bận đột xuất (override isAvailable = false)
        foreach ($this->availabilitySlots as $slot) {
            if ($slot->overrideDate === $dateYmd && !$slot->isAvailable) {
                if ($shiftStart < $slot->endMinuteOfDay && $slot->startMinuteOfDay < $shiftEnd) {
                    return false;
                }
            }
        }

        // 2. Kiểm tra ngày rảnh đột xuất (override isAvailable = true)
        foreach ($this->availabilitySlots as $slot) {
            if ($slot->overrideDate === $dateYmd && $slot->isAvailable) {
                if ($slot->startMinuteOfDay <= $shiftStart && $shiftEnd <= $slot->endMinuteOfDay) {
                    return true;
                }
            }
        }

        // 3. Kiểm tra lịch rảnh lặp theo thứ trong tuần
        foreach ($this->availabilitySlots as $slot) {
            if ($slot->overrideDate === null && $slot->isAvailable && $slot->dayOfWeek === $dayOfWeek) {
                if ($slot->startMinuteOfDay <= $shiftStart && $shiftEnd <= $slot->endMinuteOfDay) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_code' => $this->studentCode,
            'full_name' => $this->fullName,
            'chapter_name' => $this->chapterName,
            'max_shifts_per_week' => $this->maxShiftsPerWeek,
            'skills' => $this->skills,
            'availability' => array_map(static fn(AvailabilitySlot $s) => $s->toArray(), $this->availabilitySlots),
            'absent_risk_probability' => $this->absentRiskProbability,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'reputation_score' => $this->reputationScore,
        ];
    }
}

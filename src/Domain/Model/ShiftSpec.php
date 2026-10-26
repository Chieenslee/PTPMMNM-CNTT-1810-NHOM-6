<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class ShiftSpec
{
    /**
     * @param list<SkillRequirementSpec> $requirements
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $title,
        public readonly int $startEpoch,
        public readonly int $endEpoch,
        public readonly string $startTimeIso,
        public readonly string $endTimeIso,
        public readonly int $minPeople,
        public readonly int $maxPeople,
        public readonly int $priority,
        public readonly array $requirements,
        public readonly bool $isLocked = false,
        public readonly float $lat = 10.7769,
        public readonly float $lng = 106.7009,
        public readonly string $locationName = ''
    ) {
    }

    /**
     * Kiểm tra hai ca có giao nhau theo khoảng nửa mở [start, end) hay không.
     * Ca kết thúc lúc 10:00 và ca bắt đầu lúc 10:00 KHÔNG tính là trùng giờ.
     */
    public function overlapsWith(self $other): bool
    {
        return $this->startEpoch < $other->endEpoch && $other->startEpoch < $this->endEpoch;
    }

    public function durationHours(): float
    {
        return max(0.0, ($this->endEpoch - $this->startEpoch) / 3600.0);
    }

    public function dayOfWeekIso(): int
    {
        return (int) date('N', $this->startEpoch);
    }

    public function dateYmd(): string
    {
        return date('Y-m-d', $this->startEpoch);
    }

    public function startMinutesOfDay(): int
    {
        return ((int) date('G', $this->startEpoch)) * 60 + (int) date('i', $this->startEpoch);
    }

    public function endMinutesOfDay(): int
    {
        $endMin = ((int) date('G', $this->endEpoch)) * 60 + (int) date('i', $this->endEpoch);
        if ($endMin === 0 && $this->endEpoch > $this->startEpoch) {
            return 24 * 60;
        }
        return $endMin;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'start_epoch' => $this->startEpoch,
            'end_epoch' => $this->endEpoch,
            'start_time' => $this->startTimeIso,
            'end_time' => $this->endTimeIso,
            'min_people' => $this->minPeople,
            'max_people' => $this->maxPeople,
            'priority' => $this->priority,
            'is_locked' => $this->isLocked,
            'location_name' => $this->locationName,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'requirements' => array_map(static fn(SkillRequirementSpec $r) => $r->toArray(), $this->requirements),
        ];
    }
}

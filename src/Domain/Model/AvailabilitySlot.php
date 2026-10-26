<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class AvailabilitySlot
{
    public function __construct(
        public readonly int $dayOfWeek, // 1 (Thứ Hai) .. 7 (Chủ Nhật)
        public readonly int $startMinuteOfDay, // 0 .. 1440
        public readonly int $endMinuteOfDay,   // 0 .. 1440
        public readonly ?string $overrideDate = null,
        public readonly bool $isAvailable = true
    ) {
    }

    public static function fromHm(
        int $dayOfWeek,
        string $startHm,
        string $endHm,
        ?string $overrideDate = null,
        bool $isAvailable = true
    ): self {
        return new self(
            $dayOfWeek,
            self::parseHmToMinutes($startHm),
            self::parseHmToMinutes($endHm),
            $overrideDate,
            $isAvailable
        );
    }

    public static function parseHmToMinutes(string $hm): int
    {
        $parts = explode(':', trim($hm));
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        return $h * 60 + $m;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'day_of_week' => $this->dayOfWeek,
            'start_minute' => $this->startMinuteOfDay,
            'end_minute' => $this->endMinuteOfDay,
            'override_date' => $this->overrideDate,
            'is_available' => $this->isAvailable,
        ];
    }
}

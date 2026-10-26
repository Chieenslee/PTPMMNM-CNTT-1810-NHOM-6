<?php

declare(strict_types=1);

namespace XanhHub\Domain\Model;

final class RegistrationSpec
{
    public function __construct(
        public readonly int $id,
        public readonly int $shiftId,
        public readonly int $volunteerId,
        public readonly int $preferenceRank, // 1, 2, 3
        public readonly int $registeredAtEpoch,
        public readonly string $registeredAtIso,
        public readonly string $status = 'ACTIVE'
    ) {
    }

    /**
     * Quy đổi nguyện vọng 1..3 thành điểm ưu tiên (NV1 = 3, NV2 = 2, NV3 = 1).
     */
    public function preferenceScore(): float
    {
        return (float) max(1, 4 - $this->preferenceRank);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shiftId,
            'volunteer_id' => $this->volunteerId,
            'preference_rank' => $this->preferenceRank,
            'registered_at_epoch' => $this->registeredAtEpoch,
            'registered_at' => $this->registeredAtIso,
            'status' => $this->status,
        ];
    }
}

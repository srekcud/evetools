<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperationMember;
use App\Enum\MinedQuantityState;
use App\Enum\MiningFleetStorageDelivery;

final readonly class MiningParticipantValuation
{
    /**
     * @param list<MiningCharacterValuation> $characters
     */
    public function __construct(
        public ?MiningOperationMember $member,
        public ?string $participantName,
        public ?MinedQuantity $quantity,
        public ?int $minedValue,
        public ?float $valuePercent,
        public ?int $share,
        public ?int $roundingAdjustment,
        public ?int $durationSeconds,
        public ?float $iskPerHour,
        public array $characters,
        public ?MiningFleetStorageDelivery $fleetStorageDelivery = null,
        public bool $isFleetStorageRecipient = false,
        public ?int $deliveredValue = null,
        public ?int $keptValue = null,
        public ?int $fleetStorageDebt = null,
    ) {
    }

    public function deliversMoreThanMined(): bool
    {
        return $this->member?->getFleetStorageDelivery() === MiningFleetStorageDelivery::Partial
            && $this->quantity?->state === MinedQuantityState::Known
            && MiningFleetStorageKeeping::deliversMoreThanMined($this->quantity, $this->member->getFleetStorageQuantities() ?? throw new \LogicException('A partial delivery declares its quantities'));
    }
}

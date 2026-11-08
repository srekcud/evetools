<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use Symfony\Component\Validator\Constraints as Assert;

class CreateMiningOperationInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100, normalizer: 'trim')]
    public string $label = '';

    #[Assert\NotNull]
    public ?\DateTimeImmutable $plannedStartAt = null;

    #[Assert\Choice(choices: ['private', 'corporation'])]
    public string $visibility = '';

    #[Assert\Choice(choices: ['value_prorata', 'each_for_themselves'])]
    public string $splitMode = '';

    /** @var list<string> */
    #[Assert\Count(min: 1)]
    #[Assert\Unique]
    #[Assert\All([new Assert\Uuid()])]
    public array $characterIds = [];

    #[Assert\Valid]
    public ?MiningOperationRecurrenceInput $recurrence = null;

    #[Assert\Uuid]
    public ?string $chunkId = null;

    #[Assert\Choice(callback: [MiningBoostShip::class, 'values'])]
    public ?int $boostShipTypeId = null;

    /** @var list<string> */
    #[Assert\Unique]
    #[Assert\All([new Assert\Choice(callback: [MiningCompressorKind::class, 'values'])])]
    public array $mountedCompressorKinds = [];

    public function declaredBoostShip(): ?MiningBoostShip
    {
        return null === $this->boostShipTypeId ? null : MiningBoostShip::from($this->boostShipTypeId);
    }

    /**
     * @return list<MiningCompressorKind>
     */
    public function declaredCompressorKinds(): array
    {
        return array_map(MiningCompressorKind::from(...), $this->mountedCompressorKinds);
    }
}

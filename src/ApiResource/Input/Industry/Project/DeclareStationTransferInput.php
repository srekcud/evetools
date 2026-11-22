<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class DeclareStationTransferInput
{
    private const int MAXIMUM_SOLAR_SYSTEM_ID = 2_147_483_647;

    #[Assert\NotNull]
    #[Assert\Type('int')]
    #[Assert\Range(min: 1, max: self::MAXIMUM_SOLAR_SYSTEM_ID)]
    public mixed $fromSolarSystemId = null;

    #[Assert\Type('int')]
    #[Assert\Positive]
    public mixed $fromLocationId = null;

    #[Assert\NotNull]
    #[Assert\Type('int')]
    #[Assert\Range(min: 1, max: self::MAXIMUM_SOLAR_SYSTEM_ID)]
    public mixed $toSolarSystemId = null;

    #[Assert\Type('int')]
    #[Assert\Positive]
    public mixed $toLocationId = null;

    /**
     * @var array<mixed>|null
     */
    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[Assert\Count(min: 1)]
    #[Assert\All([
        new Assert\Collection(fields: [
            'typeId' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive()],
            'quantity' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive()],
        ]),
    ])]
    public mixed $lines = null;

    #[Assert\Type('int')]
    #[Assert\Positive]
    public mixed $carrierCharacterId = null;

    #[Assert\Type('int')]
    #[Assert\Positive]
    public mixed $contractId = null;
}

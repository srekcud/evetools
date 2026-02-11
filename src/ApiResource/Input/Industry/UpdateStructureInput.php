<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateStructureInput
{
    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    #[Assert\Choice(choices: ['highsec', 'lowsec', 'nullsec'])]
    public ?string $securityType = null;

    #[Assert\Choice(choices: ['station', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'engineering_complex', 'refinery'])]
    public ?string $structureType = null;

    /** @var string[]|null */
    public ?array $rigs = null;

    public ?bool $isDefault = null;

    public ?bool $isCorporationStructure = null;

    #[Assert\Positive]
    public ?int $solarSystemId = null;
}

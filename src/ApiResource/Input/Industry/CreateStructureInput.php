<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateStructureInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name;

    #[Assert\Choice(choices: ['highsec', 'lowsec', 'nullsec'])]
    public string $securityType = 'nullsec';

    #[Assert\Choice(choices: ['station', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'engineering_complex', 'refinery'])]
    public string $structureType = 'raitaru';

    /** @var string[] */
    public array $rigs = [];

    public bool $isDefault = false;

    #[Assert\Positive]
    public ?int $locationId = null;
}

<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class StructureBonusPreviewInput
{
    #[Assert\Choice(choices: ['highsec', 'lowsec', 'nullsec'])]
    public string $securityType = 'nullsec';

    #[Assert\Choice(choices: ['station', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'engineering_complex', 'refinery'])]
    public string $structureType = 'raitaru';

    /** @var string[] */
    #[Assert\All([new Assert\Type('string')])]
    public array $rigs = [];
}

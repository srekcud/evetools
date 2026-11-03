<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

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
}

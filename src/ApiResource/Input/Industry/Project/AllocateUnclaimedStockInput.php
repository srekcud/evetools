<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class AllocateUnclaimedStockInput
{
    public const string MODE_ASSIGN = 'assign';
    public const string MODE_DONATE = 'donate';

    private const int MAXIMUM_QUANTITY = 2147483647;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    public ?string $bomItemId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAXIMUM_QUANTITY)]
    public ?int $quantity = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::MODE_ASSIGN, self::MODE_DONATE])]
    public ?string $mode = null;

    #[Assert\Uuid]
    public ?string $participantId = null;
}

<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class AttachLineBlueprintInput
{
    private const int MAXIMUM_ITEM_ID = 9_223_372_036_854_775_807;

    #[Assert\NotNull]
    #[Assert\Type('string')]
    #[Assert\Uuid]
    public mixed $bomLineId = null;

    #[Assert\NotNull]
    #[Assert\Type('int')]
    #[Assert\Range(min: 1, max: self::MAXIMUM_ITEM_ID)]
    public mixed $itemId = null;
}

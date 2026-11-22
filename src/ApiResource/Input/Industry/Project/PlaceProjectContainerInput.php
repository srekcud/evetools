<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class PlaceProjectContainerInput
{
    private const int NAME_MAXIMUM_LENGTH = 255;

    #[Assert\NotNull]
    #[Assert\Type('int')]
    #[Assert\Positive]
    public mixed $locationId = null;

    #[Assert\Length(max: self::NAME_MAXIMUM_LENGTH)]
    public ?string $name = null;
}

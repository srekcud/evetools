<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class RecordSaleInput
{
    public const int MAXIMUM_QUANTITY = 2147483647;
    public const int MAXIMUM_TEXT_LENGTH = 255;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public int $typeId;

    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAXIMUM_TEXT_LENGTH)]
    public string $typeName;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: self::MAXIMUM_QUANTITY)]
    public int $quantity;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public float $unitPrice;

    #[Assert\Length(max: self::MAXIMUM_TEXT_LENGTH)]
    public ?string $venue = null;

    #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
    public ?string $soldAt = null;
}

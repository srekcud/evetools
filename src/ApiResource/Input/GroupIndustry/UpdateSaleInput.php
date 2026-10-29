<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateSaleInput
{
    #[Assert\PositiveOrZero]
    public ?int $typeId = null;

    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Length(max: RecordSaleInput::MAXIMUM_TEXT_LENGTH)]
    public ?string $typeName = null;

    #[Assert\Range(min: 1, max: RecordSaleInput::MAXIMUM_QUANTITY)]
    public ?int $quantity = null;

    #[Assert\PositiveOrZero]
    public ?float $unitPrice = null;

    #[Assert\Length(max: RecordSaleInput::MAXIMUM_TEXT_LENGTH)]
    public ?string $venue = null;

    #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
    public ?string $soldAt = null;

    #[Assert\Positive]
    public ?int $sellerCharacterId = null;
}

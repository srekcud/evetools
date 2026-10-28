<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateGroupProjectInput
{
    private const int MAXIMUM_TEXT_LENGTH = 255;
    private const int MAXIMUM_MATERIAL_EFFICIENCY = 10;
    private const int MAXIMUM_TIME_EFFICIENCY = 20;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    #[Assert\NotBlank]
    #[Assert\Count(min: 1, max: 10)]
    #[Assert\All([
        new Assert\Collection(fields: [
            'typeId' => new Assert\Required([new Assert\Type('int'), new Assert\PositiveOrZero()]),
            'typeName' => new Assert\Required([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: self::MAXIMUM_TEXT_LENGTH)]),
            'meLevel' => new Assert\Required([new Assert\Type('int'), new Assert\Range(min: 0, max: self::MAXIMUM_MATERIAL_EFFICIENCY)]),
            'teLevel' => new Assert\Required([new Assert\Type('int'), new Assert\Range(min: 0, max: self::MAXIMUM_TIME_EFFICIENCY)]),
            'runs' => new Assert\Required([new Assert\Type('int'), new Assert\GreaterThanOrEqual(1)]),
        ]),
    ])]
    public array $items = [];

    /** @var int[] */
    public array $blacklistGroupIds = [];

    /** @var int[] */
    public array $blacklistTypeIds = [];

    /** @var string[] Category keys like 'fuel_blocks', 'advanced_components' */
    public array $blacklistCategoryKeys = [];

    #[Assert\Length(max: 255)]
    public ?string $containerName = null;

    /** @var array<string, int>|null */
    public ?array $lineRentalRatesOverride = null;

    #[Assert\PositiveOrZero]
    public ?float $brokerFeePercent = null;

    #[Assert\PositiveOrZero]
    public ?float $salesTaxPercent = null;
}

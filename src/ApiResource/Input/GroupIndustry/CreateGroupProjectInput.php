<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CreateGroupProjectInput
{
    private const int MAXIMUM_TEXT_LENGTH = 255;
    private const int MAXIMUM_MATERIAL_EFFICIENCY = 10;
    private const int MAXIMUM_TIME_EFFICIENCY = 20;
    private const int MAXIMUM_RUNS = 100000;
    private const int MAXIMUM_BLACKLIST_ENTRIES = 200;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    #[Assert\NotBlank]
    #[Assert\Count(min: 1, max: 10)]
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(fields: [
                'typeId' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\PositiveOrZero()]),
                'typeName' => new Assert\Required([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: self::MAXIMUM_TEXT_LENGTH)]),
                'meLevel' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 0, max: self::MAXIMUM_MATERIAL_EFFICIENCY)]),
                'teLevel' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 0, max: self::MAXIMUM_TIME_EFFICIENCY)]),
                'runs' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 1, max: self::MAXIMUM_RUNS)]),
            ]),
        ]),
    ])]
    public array $items = [];

    /** @var int[] */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public array $blacklistGroupIds = [];

    /** @var int[] */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public array $blacklistTypeIds = [];

    /** @var string[] Category keys like 'fuel_blocks', 'advanced_components' */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public array $blacklistCategoryKeys = [];

    #[Assert\Length(max: 255)]
    public ?string $containerName = null;

    /** @var array<string, int>|null */
    public ?array $lineRentalRatesOverride = null;

    #[Assert\Range(min: 0, max: GroupProjectFees::MAXIMUM_PERCENT)]
    public ?float $brokerFeePercent = null;

    #[Assert\Range(min: 0, max: GroupProjectFees::MAXIMUM_PERCENT)]
    public ?float $salesTaxPercent = null;

    #[Assert\Callback]
    public function validateTheSumOfTheRuns(ExecutionContextInterface $context): void
    {
        $sumOfTheRuns = array_sum(array_map(self::validRunsOf(...), $this->items));

        if ($sumOfTheRuns > self::MAXIMUM_RUNS) {
            $context->buildViolation(sprintf('The runs of all the items add up to more than %d.', self::MAXIMUM_RUNS))->atPath('items')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateTheSumOfTheFees(ExecutionContextInterface $context): void
    {
        GroupProjectFees::validateSentFees($this->brokerFeePercent, $this->salesTaxPercent, $context);
    }

    private static function validRunsOf(mixed $item): int
    {
        if (!is_array($item) || !isset($item['runs']) || !is_int($item['runs']) || $item['runs'] < 1) {
            return 0;
        }

        return $item['runs'];
    }
}

<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateProjectInput
{
    public const int MAXIMUM_NOTES_LENGTH = 5000;
    public const int MAXIMUM_BLACKLIST_ENTRIES = 200;
    public const int MAXIMUM_CONTAINER_NAMES = 20;
    public const int MAXIMUM_CONTAINER_NAME_LENGTH = 255;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Choice(choices: ['published', 'in_progress', 'selling', 'completed'])]
    public ?string $status = null;

    /** @var list<string>|null */
    #[Assert\Count(max: self::MAXIMUM_CONTAINER_NAMES)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: self::MAXIMUM_CONTAINER_NAME_LENGTH)])]
    public ?array $containerNames = null;

    #[Assert\Range(min: 0, max: ProjectFees::MAXIMUM_PERCENT)]
    public ?float $brokerFeePercent = null;

    #[Assert\Range(min: 0, max: ProjectFees::MAXIMUM_PERCENT)]
    public ?float $salesTaxPercent = null;

    public ?bool $private = null;

    #[Assert\Positive]
    public ?int $sellStructureId = null;

    #[Assert\Length(max: self::MAXIMUM_NOTES_LENGTH)]
    public ?string $notes = null;

    public ?bool $acceptsOutsideFavoriteSystem = null;

    public ?bool $excludesInvention = null;

    #[Assert\PositiveOrZero]
    public ?float $minimumOutsideSaving = null;

    /** @var int[]|null */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public ?array $blacklistGroupIds = null;

    /** @var int[]|null */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public ?array $blacklistTypeIds = null;

    /** @var string[]|null */
    #[Assert\Count(max: self::MAXIMUM_BLACKLIST_ENTRIES)]
    public ?array $blacklistCategoryKeys = null;

    #[Assert\Callback]
    public function validateTheSumOfTheFees(ExecutionContextInterface $context): void
    {
        ProjectFees::validateSentFees($this->brokerFeePercent, $this->salesTaxPercent, $context);
    }
}

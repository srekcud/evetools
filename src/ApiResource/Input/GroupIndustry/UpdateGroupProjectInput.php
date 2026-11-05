<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateGroupProjectInput
{
    public const int MAXIMUM_NOTES_LENGTH = 5000;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Choice(choices: ['published', 'in_progress', 'selling', 'completed'])]
    public ?string $status = null;

    #[Assert\Length(max: 255)]
    public ?string $containerName = null;

    #[Assert\Range(min: 0, max: GroupProjectFees::MAXIMUM_PERCENT)]
    public ?float $brokerFeePercent = null;

    #[Assert\Range(min: 0, max: GroupProjectFees::MAXIMUM_PERCENT)]
    public ?float $salesTaxPercent = null;

    public ?bool $private = null;

    #[Assert\Length(max: self::MAXIMUM_NOTES_LENGTH)]
    public ?string $notes = null;

    #[Assert\Callback]
    public function validateTheSumOfTheFees(ExecutionContextInterface $context): void
    {
        GroupProjectFees::validateSentFees($this->brokerFeePercent, $this->salesTaxPercent, $context);
    }
}

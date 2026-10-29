<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateGroupProjectInput
{
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

    #[Assert\Callback]
    public function validateTheSumOfTheFees(ExecutionContextInterface $context): void
    {
        GroupProjectFees::validateSentFees($this->brokerFeePercent, $this->salesTaxPercent, $context);
    }
}

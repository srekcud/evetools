<?php

declare(strict_types=1);

namespace App\Service\Industry\SalesFees;

final readonly class SalesFeeCalculator
{
    private const int MIN_SKILL_LEVEL = 0;
    private const int MAX_SKILL_LEVEL = 5;
    private const float BASE_SALES_TAX_RATE = 0.075;
    private const float ACCOUNTING_REDUCTION_PER_LEVEL = 0.11;
    private const float BASE_BROKER_FEE_RATE = 0.03;
    private const float BROKER_RELATIONS_REDUCTION_PER_LEVEL = 0.003;
    private const float FACTION_STANDING_REDUCTION_PER_POINT = 0.0003;
    private const float CORPORATION_STANDING_REDUCTION_PER_POINT = 0.0002;

    public function calculate(int $accountingLevel, int $brokerRelationsLevel, float $factionStanding, float $corporationStanding): SalesFees
    {
        $this->assertSkillLevel('Accounting', $accountingLevel);
        $this->assertSkillLevel('Broker Relations', $brokerRelationsLevel);

        $salesTaxRate = self::BASE_SALES_TAX_RATE * (1 - self::ACCOUNTING_REDUCTION_PER_LEVEL * $accountingLevel);
        $brokerFeeRate = self::BASE_BROKER_FEE_RATE
            - self::BROKER_RELATIONS_REDUCTION_PER_LEVEL * $brokerRelationsLevel
            - self::FACTION_STANDING_REDUCTION_PER_POINT * $factionStanding
            - self::CORPORATION_STANDING_REDUCTION_PER_POINT * $corporationStanding;

        return new SalesFees($brokerFeeRate, $salesTaxRate);
    }

    private function assertSkillLevel(string $skillName, int $level): void
    {
        if ($level < self::MIN_SKILL_LEVEL || $level > self::MAX_SKILL_LEVEL) {
            throw new \InvalidArgumentException(sprintf('%s level must be between %d and %d, %d given.', $skillName, self::MIN_SKILL_LEVEL, self::MAX_SKILL_LEVEL, $level));
        }
    }
}

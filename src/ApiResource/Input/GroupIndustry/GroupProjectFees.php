<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class GroupProjectFees
{
    public const float MAXIMUM_PERCENT = 100.0;

    private const string SALES_TAX_PROPERTY_PATH = 'salesTaxPercent';

    private const string SUM_EXCEEDS_MAXIMUM_MESSAGE = 'The broker fee and the sales tax must not add up to more than 100 percent.';

    public static function validateSentFees(?float $brokerFeePercent, ?float $salesTaxPercent, ExecutionContextInterface $context): void
    {
        if ($brokerFeePercent === null || $salesTaxPercent === null || !self::sumExceedsMaximum($brokerFeePercent, $salesTaxPercent)) {
            return;
        }

        $context->buildViolation(self::SUM_EXCEEDS_MAXIMUM_MESSAGE)->atPath(self::SALES_TAX_PROPERTY_PATH)->addViolation();
    }

    public static function rejectEffectiveFeesAboveMaximum(float $brokerFeePercent, float $salesTaxPercent): void
    {
        if (!self::sumExceedsMaximum($brokerFeePercent, $salesTaxPercent)) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(
                self::SUM_EXCEEDS_MAXIMUM_MESSAGE,
                self::SUM_EXCEEDS_MAXIMUM_MESSAGE,
                [],
                $salesTaxPercent,
                self::SALES_TAX_PROPERTY_PATH,
                $salesTaxPercent,
            ),
        ]));
    }

    private static function sumExceedsMaximum(float $brokerFeePercent, float $salesTaxPercent): bool
    {
        return $brokerFeePercent + $salesTaxPercent > self::MAXIMUM_PERCENT;
    }
}

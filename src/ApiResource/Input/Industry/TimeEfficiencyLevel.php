<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class TimeEfficiencyLevel extends Compound
{
    private const int MINIMUM_LEVEL = 0;
    private const int MAXIMUM_LEVEL = 20;
    private const int LEVEL_STEP = 2;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Range(min: self::MINIMUM_LEVEL, max: self::MAXIMUM_LEVEL),
            new Assert\DivisibleBy(
                value: self::LEVEL_STEP,
                message: 'TE must be an even level between '.self::MINIMUM_LEVEL.' and '.self::MAXIMUM_LEVEL.'.',
            ),
        ];
    }
}

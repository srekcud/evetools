<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Number of runs of a job. Never a number of units: see Quantity.
 */
final readonly class Runs
{
    private const int MINIMUM = 1;

    public function __construct(public int $value)
    {
        if ($value < self::MINIMUM) {
            throw new \InvalidArgumentException(\sprintf('A job has at least %d run, got %d.', self::MINIMUM, $value));
        }
    }

    /**
     * @return list<self>
     */
    public static function splitUnderCap(self $total, self $cap): array
    {
        $partCount = (int) ceil($total->value / $cap->value);
        $runsPerPart = intdiv($total->value, $partCount);
        $partsWithOneMoreRun = $total->value % $partCount;

        $parts = [];
        for ($partIndex = 0; $partIndex < $partCount; ++$partIndex) {
            $parts[] = new self($partIndex < $partsWithOneMoreRun ? $runsPerPart + 1 : $runsPerPart);
        }

        return $parts;
    }
}

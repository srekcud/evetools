<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class OutputPerRun
{
    public function __construct(public int $value)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\MissingData;

final readonly class PlannedCopyDuration
{
    /**
     * @param list<MissingData> $missingData
     */
    public function __construct(public ?int $seconds, public array $missingData, public ?string $characterName)
    {
    }
}

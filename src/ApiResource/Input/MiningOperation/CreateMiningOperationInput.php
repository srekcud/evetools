<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class CreateMiningOperationInput
{
    public string $label = '';

    public ?\DateTimeImmutable $plannedStartAt = null;

    public string $visibility = '';

    public string $splitMode = '';

    public array $characterIds = [];
}

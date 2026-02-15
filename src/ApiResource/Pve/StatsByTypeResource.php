<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class StatsByTypeResource
{
    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, mixed> */
    public array $income = [];

    /** @var array<string, mixed> */
    public array $expenses = [];
}

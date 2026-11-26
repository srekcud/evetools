<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Planetary;

class CreatePlanTemplateInput
{
    public string $name = '';

    /** @var array<int|string, array{typeId?: mixed, targetPerDay?: mixed}> */
    public array $productions = [];

    /** @var array<int|string, string> */
    public array $choices = [];

    /** @var array{priceSource?: string, taxPercent?: int|float, daysBetweenRounds?: int|float} */
    public array $settings = [];
}

<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Mining;

class MiningValuationProfileInput
{
    public ?string $market = null;

    public ?string $side = null;

    public ?string $form = null;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public ?float $exportTax = null;

    public bool $clearStructure = false;
}

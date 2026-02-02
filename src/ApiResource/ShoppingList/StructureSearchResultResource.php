<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

class StructureSearchResultResource
{
    public int $id = 0;

    public string $name = '';

    public ?int $typeId = null;

    public ?int $solarSystemId = null;
}

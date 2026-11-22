<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface ShipProducts
{
    public function isShip(int $typeId): bool;
}

<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface ItemVolumes
{
    public function volumeOf(int $typeId): ?float;
}

<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

interface RigCategories
{
    public function categoryOf(int $productTypeId, ActivityKind $activity): ?string;
}

<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Domain\LineRentalCategory;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\InventionService;

class LineRentalCategoryResolver
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryRigCategoryRepository $rigCategoryRepository,
        private readonly InventionService $inventionService,
    ) {
    }

    public function categoryOf(int $productTypeId): ?LineRentalCategory
    {
        return null;
    }
}

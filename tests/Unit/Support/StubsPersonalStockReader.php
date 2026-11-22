<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Repository\CachedAssetRepository;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Stock\PersonalStockReader;

trait StubsPersonalStockReader
{
    private function personalStockReaderOn(CachedAssetRepository $assetRepository): PersonalStockReader
    {
        $declaredContainers = $this->createStub(PersonalStockContainerRepository::class);
        $declaredContainers->method('findByUser')->willReturn([]);

        return new PersonalStockReader(
            $assetRepository,
            $declaredContainers,
            $this->createStub(VisibleCorporationAssetsFinder::class),
            $this->createStub(ProjectContainerService::class),
        );
    }
}

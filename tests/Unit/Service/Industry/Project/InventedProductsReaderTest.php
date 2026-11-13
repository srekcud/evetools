<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Service\Industry\InventionService;
use App\Service\Industry\Project\InventedProductsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventedProductsReader::class)]
#[Group('issue-806')]
final class InventedProductsReaderTest extends TestCase
{
    use BuildsChainBomLines;

    private const int ISHTAR = 12_005;
    private const int HAIL_L = 12_779;
    private const int RIFTER = 587;

    public function testInventedProductsAddUpTheRunsOfTheirManufacturingLinesAndLeaveOutProductsWithoutInvention(): void
    {
        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('getInventionData')->willReturnCallback(static fn (int $productTypeId): ?array => match ($productTypeId) {
            self::ISHTAR => ['t1BlueprintTypeId' => 11_000, 't2BlueprintTypeId' => 12_006, 'probability' => 0.3, 'baseRuns' => 10, 'materials' => []],
            self::HAIL_L => ['t1BlueprintTypeId' => 898, 't2BlueprintTypeId' => 12_780, 'probability' => 0.4, 'baseRuns' => 4, 'materials' => []],
            default => null,
        });
        $project = new IndustryProject();
        $project->addBomItem($this->jobLine(self::ISHTAR, 'Ishtar', 'final', null, 6));
        $project->addBomItem($this->jobLine(self::ISHTAR, 'Ishtar', 'component', self::HAIL_L, 9));
        $project->addBomItem($this->jobLine(self::HAIL_L, 'Hail L', 'final', null, 4));
        $project->addBomItem($this->jobLine(self::HAIL_L, 'Hail L', 'blueprint', null, 3, 'invention'));
        $project->addBomItem($this->jobLine(self::RIFTER, 'Rifter', 'final', null, 20));

        $inventedProducts = (new InventedProductsReader($inventionService))->inventedProductsOf($project);

        self::assertSame(
            [self::ISHTAR => ['typeName' => 'Ishtar', 'manufacturingRuns' => 15], self::HAIL_L => ['typeName' => 'Hail L', 'manufacturingRuns' => 4]],
            $inventedProducts,
        );
    }
}

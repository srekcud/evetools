<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\ProjectLaunchPriceFreezer;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectLaunchPriceFreezer::class)]
#[Group('issue-1006')]
final class ProjectLaunchPriceFreezerTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int FEROX = 16227;
    private const float DEPOSIT_DAY_PERCENTILE = 0.05;

    public function testEachMaterialLineIsFrozenAtItsJitaFifthPercentilePrice(): void
    {
        $project = new IndustryProject();
        $tritanium = $this->materialLine($project, self::TRITANIUM, 1000);
        $pyerite = $this->materialLine($project, self::PYERITE, 500);
        $market = $this->createMock(JitaMarketService::class);
        $market->expects($this->once())
            ->method('getCheapestPercentilePrices')
            ->with($this->callback(static fn (array $typeIds): bool => [self::TRITANIUM, self::PYERITE] === array_values($typeIds)), self::DEPOSIT_DAY_PERCENTILE)
            ->willReturn([self::TRITANIUM => 5.25, self::PYERITE => 12.5]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertSame(5.25, $tritanium->getLaunchPrice());
        self::assertSame(12.5, $pyerite->getLaunchPrice());
    }

    public function testJobLinesAreNeverFrozen(): void
    {
        $project = new IndustryProject();
        $job = (new IndustryProjectBomLine())->setTypeId(self::FEROX)->setTypeName('Ferox')->setRequiredQuantity(1)->setIsJob(true);
        $project->addBomItem($job);
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::FEROX => 99.0]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertNull($job->getLaunchPrice());
    }

    public function testMaterialWithoutAJitaPriceStaysUnknownAndTheOthersAreFrozen(): void
    {
        $project = new IndustryProject();
        $tritanium = $this->materialLine($project, self::TRITANIUM, 1000);
        $pyerite = $this->materialLine($project, self::PYERITE, 500);
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => null, self::PYERITE => 12.5]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertNull($tritanium->getLaunchPrice());
        self::assertSame(12.5, $pyerite->getLaunchPrice());
    }

    public function testZeroJitaPriceIsNeverFrozen(): void
    {
        $project = new IndustryProject();
        $tritanium = $this->materialLine($project, self::TRITANIUM, 1000);
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => 0.0]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertNull($tritanium->getLaunchPrice());
    }

    public function testAFrozenPriceIsNeverRewritten(): void
    {
        $project = new IndustryProject();
        $tritanium = $this->materialLine($project, self::TRITANIUM, 1000);
        $tritanium->setLaunchPrice(5.25);
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => 8.0]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertSame(5.25, $tritanium->getLaunchPrice());
    }

    public function testProjectWithoutMaterialLineDoesNotReadTheMarket(): void
    {
        $market = $this->createMock(JitaMarketService::class);
        $market->expects($this->never())->method('getCheapestPercentilePrices');

        (new ProjectLaunchPriceFreezer($market))->freeze(new IndustryProject());
    }

    private function materialLine(IndustryProject $project, int $typeId, int $quantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Material '.$typeId)->setRequiredQuantity($quantity)->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }
}

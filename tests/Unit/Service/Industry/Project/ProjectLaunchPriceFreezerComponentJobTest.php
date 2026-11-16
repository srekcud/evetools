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
#[Group('issue-1013')]
final class ProjectLaunchPriceFreezerComponentJobTest extends TestCase
{
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FEROX = 16227;
    private const int TRITANIUM = 34;
    private const float DEPOSIT_DAY_PERCENTILE = 0.05;

    public function testComponentJobLineIsFrozenAtItsJitaFifthPercentilePriceWithTheMaterials(): void
    {
        $project = new IndustryProject();
        $material = $this->line($project, self::TRITANIUM, false, null);
        $component = $this->line($project, self::HYDROGEN_FUEL_BLOCK, true, 'component');
        $market = $this->createMock(JitaMarketService::class);
        $market->expects($this->once())
            ->method('getCheapestPercentilePrices')
            ->with($this->callback(static fn (array $typeIds): bool => [self::TRITANIUM, self::HYDROGEN_FUEL_BLOCK] === array_values($typeIds)), self::DEPOSIT_DAY_PERCENTILE)
            ->willReturn([self::TRITANIUM => 5.25, self::HYDROGEN_FUEL_BLOCK => 2150.0]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertSame(5.25, $material->getLaunchPrice());
        self::assertSame(2150.0, $component->getLaunchPrice());
    }

    public function testFinalAndBlueprintJobLinesAreStillNeverFrozen(): void
    {
        $project = new IndustryProject();
        $final = $this->line($project, self::FEROX, true, 'final');
        $blueprint = $this->line($project, 1_000_000 + self::FEROX, true, 'blueprint');
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::FEROX => 99.0, 1_000_000 + self::FEROX => 5.0]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertNull($final->getLaunchPrice());
        self::assertNull($blueprint->getLaunchPrice());
    }

    public function testComponentJobLineWithoutAJitaPriceStaysUnknown(): void
    {
        $project = new IndustryProject();
        $component = $this->line($project, self::HYDROGEN_FUEL_BLOCK, true, 'component');
        $market = $this->createStub(JitaMarketService::class);
        $market->method('getCheapestPercentilePrices')->willReturn([self::HYDROGEN_FUEL_BLOCK => null]);

        (new ProjectLaunchPriceFreezer($market))->freeze($project);

        self::assertNull($component->getLaunchPrice());
    }

    private function line(IndustryProject $project, int $typeId, bool $isJob, ?string $jobGroup): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Line '.$typeId)->setRequiredQuantity(100)->setIsJob($isJob)->setJobGroup($jobGroup);
        $project->addBomItem($line);

        return $line;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectProduct;
use App\Entity\User;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Runs;
use App\Service\Industry\Project\InventionLineExpectedCopies;
use App\Tests\Unit\Industry\Application\Double\InMemoryDecryptors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionLineExpectedCopies::class)]
#[Group('issue-1316')]
final class InventionLineExpectedCopiesTest extends TestCase
{
    private const int HAIL_L = 12_779;
    private const int HAIL_L_BLUEPRINT = 12_780;
    private const int T1_PRODUCT_OF_HAIL_L = 12_778;
    private const int PRODUCT_WITHOUT_BASE_PROBABILITY = 990_004;
    private const int RIFTER = 587;
    private const float EXACT_PROBABILITY_DELTA = 0.000_000_001;

    public function testInventionLineExpectsItsAttemptsTimesTheBaseProbabilityOfTheRecipe(): void
    {
        $project = $this->projectOf();
        $line = $this->line(self::HAIL_L, IndustryProjectBomLine::ACTIVITY_INVENTION, 59);

        $expectedCopies = $this->expectedCopies()->of($project, $line);

        self::assertEqualsWithDelta(20.06, $expectedCopies, self::EXACT_PROBABILITY_DELTA);
    }

    public function testDecryptorChosenOnTheProductOfTheProjectChangesTheProbabilityOfTheInventionLine(): void
    {
        $project = $this->projectOf(InMemoryDecryptors::ATTAINMENT);
        $line = $this->line(self::HAIL_L, IndustryProjectBomLine::ACTIVITY_INVENTION, 24);

        $expectedCopies = $this->expectedCopies()->of($project, $line);

        self::assertEqualsWithDelta(14.688, $expectedCopies, self::EXACT_PROBABILITY_DELTA);
    }

    #[DataProvider('linesWithoutExpectedBlueprintCopiesProvider')]
    public function testLineThatIsNoInventionOrCannotBePricedExpectsNoCopiesAtAll(int $typeId, string $activityType, ?int $runs): void
    {
        $project = $this->projectOf();
        $line = $this->line($typeId, $activityType, $runs);

        $expectedCopies = $this->expectedCopies()->of($project, $line);

        self::assertNull($expectedCopies);
    }

    /**
     * @return iterable<string, array{int, string, ?int}>
     */
    public static function linesWithoutExpectedBlueprintCopiesProvider(): iterable
    {
        yield 'manufacturing line of an invented product' => [self::HAIL_L, IndustryProjectBomLine::ACTIVITY_MANUFACTURING, 59];
        yield 'copy line of an invented product' => [self::HAIL_L, IndustryProjectBomLine::ACTIVITY_COPY, 59];
        yield 'invention whose recipe has no base probability' => [self::PRODUCT_WITHOUT_BASE_PROBABILITY, IndustryProjectBomLine::ACTIVITY_INVENTION, 12];
        yield 'invention of a product without recipe' => [self::RIFTER, IndustryProjectBomLine::ACTIVITY_INVENTION, 12];
        yield 'invention line without runs' => [self::HAIL_L, IndustryProjectBomLine::ACTIVITY_INVENTION, null];
    }

    private function expectedCopies(): InventionLineExpectedCopies
    {
        $catalog = $this->createStub(InventionCatalog::class);
        $catalog->method('inventionOf')->willReturnCallback(
            static fn (int $productTypeId): ?InventionRecipe => match ($productTypeId) {
                self::HAIL_L => new InventionRecipe(self::HAIL_L_BLUEPRINT, self::T1_PRODUCT_OF_HAIL_L, 0.34, new Runs(10), []),
                self::PRODUCT_WITHOUT_BASE_PROBABILITY => new InventionRecipe(990_005, 990_006, null, new Runs(10), []),
                default => null,
            },
        );

        return new InventionLineExpectedCopies($catalog, InMemoryDecryptors::ofTheSde());
    }

    private function projectOf(?int $decryptorTypeIdOfHailL = null): IndustryProject
    {
        $project = (new IndustryProject())->setOwner(new User());
        $project->addItem(
            (new IndustryProjectProduct())
                ->setTypeId(self::HAIL_L)
                ->setTypeName('Hail L')
                ->setRuns(10)
                ->setInventionDecryptorTypeId($decryptorTypeIdOfHailL),
        );

        return $project;
    }

    private function line(int $typeId, string $activityType, ?int $runs): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Line of ' . $typeId)
            ->setRequiredQuantity($runs ?? 1)
            ->setIsJob(true)
            ->setJobGroup('blueprint')
            ->setActivityType($activityType)
            ->setRuns($runs);
    }
}

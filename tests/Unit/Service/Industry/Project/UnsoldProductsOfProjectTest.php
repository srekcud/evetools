<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Service\Industry\Project\UnsoldProduct;
use App\Service\Industry\Project\UnsoldProductsOfProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnsoldProductsOfProject::class)]
#[Group('issue-50')]
final class UnsoldProductsOfProjectTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int RAVEN_TYPE_ID = 638;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int RAM_TYPE_ID = 11478;

    public function testRemainderIsTheRequiredQuantityOfTheFinalJobMinusWhatWasSold(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10));

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [$this->sale(self::HAMMERHEAD_TYPE_ID, 4, 800_000.0)]);

        self::assertEquals([new UnsoldProduct(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, 800_000.0)], $unsoldProducts);
    }

    public function testTwoFinalLinesOfTheSameTypeAddUpBeforeTheSalesAreSubtracted(): void
    {
        $project = $this->projectOf(
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3),
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 2),
        );

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [$this->sale(self::SABRE_TYPE_ID, 1, 1_300_000.0)]);

        self::assertEquals([new UnsoldProduct(self::SABRE_TYPE_ID, 'Sabre', 4, 1_300_000.0)], $unsoldProducts);
    }

    public function testSeveralSalesOfTheSameTypeAddUpAndEachTypeKeepsItsOwnRemainder(): void
    {
        $project = $this->projectOf(
            $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10),
            $this->finalJobLine(self::RAVEN_TYPE_ID, 'Raven', 7),
        );

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [
            $this->sale(self::HAMMERHEAD_TYPE_ID, 3, 800_000.0),
            $this->sale(self::HAMMERHEAD_TYPE_ID, 2, 810_000.0, '2026-11-02 10:00:00'),
            $this->sale(self::RAVEN_TYPE_ID, 1, 190_000_000.0),
        ]);

        self::assertEquals([
            new UnsoldProduct(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 5, 810_000.0),
            new UnsoldProduct(self::RAVEN_TYPE_ID, 'Raven', 6, 190_000_000.0),
        ], $unsoldProducts);
    }

    public function testNothingRemainsWhenEveryFinalProductWasSold(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10));

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [
            $this->sale(self::HAMMERHEAD_TYPE_ID, 6, 800_000.0),
            $this->sale(self::HAMMERHEAD_TYPE_ID, 4, 790_000.0),
        ]);

        self::assertSame([], $unsoldProducts);
    }

    public function testProductSoldBeyondTheRequiredQuantityIsNotListed(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10));

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [$this->sale(self::HAMMERHEAD_TYPE_ID, 12, 800_000.0)]);

        self::assertSame([], $unsoldProducts);
    }

    public function testSaleOfATypeOutsideTheFinalBomIsIgnored(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10));

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [
            $this->sale(self::RAVEN_TYPE_ID, 5, 190_000_000.0),
            $this->sale(self::HAMMERHEAD_TYPE_ID, 4, 800_000.0),
        ]);

        self::assertEquals([new UnsoldProduct(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, 800_000.0)], $unsoldProducts);
    }

    public function testMaterialLinesAndComponentJobsAreNotProductsToSell(): void
    {
        $project = $this->projectOf(
            $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000),
            $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 300),
            $this->jobLine(self::RAVEN_TYPE_ID, 'Raven', null, 4),
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 5),
        );

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, []);

        self::assertEquals([new UnsoldProduct(self::SABRE_TYPE_ID, 'Sabre', 5, null)], $unsoldProducts);
    }

    public function testProjectWithoutFinalJobHasNothingToSell(): void
    {
        $project = $this->projectOf($this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000));

        self::assertSame([], (new UnsoldProductsOfProject())->of($project, []));
    }

    public function testTypeNameIsTheOneOfTheFirstFinalLine(): void
    {
        $project = $this->projectOf(
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3),
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre (renamed)', 2),
        );

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, []);

        self::assertEquals([new UnsoldProduct(self::SABRE_TYPE_ID, 'Sabre', 5, null)], $unsoldProducts);
    }

    public function testLastUnitPriceIsTheOneOfTheMostRecentSaleWhateverTheOrderOfTheSales(): void
    {
        $project = $this->projectOf($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 50));

        $unsoldProducts = (new UnsoldProductsOfProject())->of($project, [
            $this->sale(self::HAMMERHEAD_TYPE_ID, 20, 40_000.0, '2026-11-02 10:00:00'),
            $this->sale(self::HAMMERHEAD_TYPE_ID, 10, 30_000.0, '2026-11-01 10:00:00'),
        ]);

        self::assertEquals([new UnsoldProduct(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 20, 40_000.0)], $unsoldProducts);
    }

    private function projectOf(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = new IndustryProject();
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }

        return $project;
    }

    private function finalJobLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        return $this->jobLine($typeId, $typeName, 'final', $requiredQuantity);
    }

    private function jobLine(int $typeId, string $typeName, ?string $jobGroup, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup);
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false)
            ->setJobGroup('final');
    }

    private function sale(int $typeId, int $quantity, float $unitPrice, string $soldAt = '2026-11-01 10:00:00'): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName('Sold type')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSoldAt(new \DateTimeImmutable($soldAt));
    }
}

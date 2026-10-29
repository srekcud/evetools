<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\EstimatedSellPriceEstimator;
use App\Service\Industry\ProjectCorrectionOutcome;
use App\Service\Industry\ReactionProjectSellPriceRecalculation;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ReactionProjectSellPriceRecalculation::class)]
#[CoversClass(EstimatedSellPriceEstimator::class)]
#[Group('issue-278')]
final class ReactionProjectSellPriceRecalculationTest extends TestCase
{
    use AutowiresByType;
    use ResolvesTypeNamesFromSde;

    private const string CREATED_BEFORE = '2026-11-01T00:00:00+00:00';
    private const string CREATED_BEFORE_THE_DEPLOYMENT = '2026-10-15T09:00:00+00:00';
    private const string CREATED_AFTER_THE_DEPLOYMENT = '2026-11-02T09:00:00+00:00';

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46166;
    private const int FERNITE_CARBIDE_UNITS_PER_RUN = 10_000;
    private const int FERNITE_CARBIDE_RUNS = 8;
    private const float FERNITE_CARBIDE_JITA_PRICE = 30.0;
    private const float FERNITE_CARBIDE_STRUCTURE_PRICE = 35.0;
    private const float SELL_PRICE_OF_THE_OLD_CALCULATION = 240.0;
    private const float TAX_OF_THE_OLD_CALCULATION = 7.2;

    private const int CERAMIC_POWDER = 16660;
    private const int CERAMIC_POWDER_REACTION_FORMULA = 1_016_660;
    private const int CERAMIC_POWDER_UNITS_PER_RUN = 200;
    private const int CERAMIC_POWDER_RUNS = 12;

    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 4314;
    private const int NITROGEN_FUEL_BLOCK_UNITS_PER_RUN = 40;
    private const float NITROGEN_FUEL_BLOCK_JITA_PRICE = 1_250.0;

    private const int ROBOTICS = 9848;
    private const float ROBOTICS_JITA_PRICE = 15_000.0;

    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const float BROKER_FEE_RATE = 0.01;
    private const float SALES_TAX_RATE = 0.02;

    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000016673';
    private const string SECOND_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000026673';
    private const string CERAMIC_POWDER_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000016660';
    private const string NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000004051';
    private const string ROBOTICS_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000009848';

    private const string FERNITE_CARBIDE_CHANGE_AT_JITA = 'sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00';

    private array $entityManagerCalls = [];

    private ?\Throwable $flushFailure = null;

    private bool $entityManagerIsOpen = true;

    public function testReactionProjectOfTheOldCalculationIsRecalculatedWithTheUnitsPerRunOfItsReactionFormula(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation();

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_CHANGE_AT_JITA, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([2_400_000.0, 'jita', 72_000.0], self::estimatedSellPriceOf($project));
        self::assertSame(['flush', 'clear'], $this->entityManagerCalls);
    }

    public function testSecondRunWithTheSamePricesLeavesTheRecalculatedProjectUnchangedAndWritesNothing(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation();
        $recalculation = $this->recalculationOf($project);
        $recalculation->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);
        $this->entityManagerCalls = [];

        $outcomes = $recalculation->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Unchanged, null, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([2_400_000.0, 'jita', 72_000.0], self::estimatedSellPriceOf($project));
        self::assertNotContains('flush', $this->entityManagerCalls);
    }

    public function testDryRunReportsTheSameChangeAndWritesNothing(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation();

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), true);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_CHANGE_AT_JITA, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([self::SELL_PRICE_OF_THE_OLD_CALCULATION, 'jita', self::TAX_OF_THE_OLD_CALCULATION], self::estimatedSellPriceOf($project));
        self::assertNotContains('flush', $this->entityManagerCalls);
    }

    public function testHigherPriceInThePreferredStructureIsTakenAsAtTheCreation(): void
    {
        $owner = (new User())->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
        $project = $this->ferniteCarbideProjectOfTheOldCalculation($owner);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, 'sell 240.00 (jita), tax 7.20 -> sell 2,800,000.00 (structure), tax 84,000.00', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([2_800_000.0, 'structure', 84_000.0], self::estimatedSellPriceOf($project));
    }

    public function testReactionProjectCreatedAfterTheDeploymentIsNotSelected(): void
    {
        $project = $this->project(self::FERNITE_CARBIDE_PROJECT_ID, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS, self::CREATED_AFTER_THE_DEPLOYMENT)
            ->setEstimatedSellPrice(2_160_000.0)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(64_800.0);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame([], $outcomes);
        self::assertSame([2_160_000.0, 'jita', 64_800.0], self::estimatedSellPriceOf($project));
    }

    public function testReactionProjectCreatedAtTheDeploymentTimeIsNotSelected(): void
    {
        $project = $this->project(self::FERNITE_CARBIDE_PROJECT_ID, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS, self::CREATED_BEFORE)
            ->setEstimatedSellPrice(self::SELL_PRICE_OF_THE_OLD_CALCULATION)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(self::TAX_OF_THE_OLD_CALCULATION);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame([], $outcomes);
    }

    public function testProjectWhoseProducerIsAManufacturingBlueprintOfSeveralUnitsPerRunIsNotSelected(): void
    {
        $project = $this->project(self::NITROGEN_FUEL_BLOCK_PROJECT_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 25)
            ->setEstimatedSellPrice(1_000_000.0)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(30_000.0);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame([], $outcomes);
        self::assertSame([1_000_000.0, 'jita', 30_000.0], self::estimatedSellPriceOf($project));
    }

    public function testReactionProjectWithAnUnknownEstimatedSellPriceIsNotSelected(): void
    {
        $project = $this->project(self::FERNITE_CARBIDE_PROJECT_ID, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame([], $outcomes);
        self::assertSame([null, null, null], self::estimatedSellPriceOf($project));
    }

    public function testCompletedReactionProjectIsNotSelected(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation()->setStatus(ProjectStatus::Completed);

        $outcomes = $this->recalculationOf($project)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame([], $outcomes);
        self::assertSame([self::SELL_PRICE_OF_THE_OLD_CALCULATION, 'jita', self::TAX_OF_THE_OLD_CALCULATION], self::estimatedSellPriceOf($project));
    }

    public function testReactionProjectWithoutJitaNorStructurePriceFailsKeepsItsValuesAndTheNextProjectIsRecalculated(): void
    {
        $ceramicPowderProject = $this->project(self::CERAMIC_POWDER_PROJECT_ID, self::CERAMIC_POWDER, self::CERAMIC_POWDER_RUNS, '2026-10-10T09:00:00+00:00')
            ->setEstimatedSellPrice(4_800.0)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(144.0);
        $ferniteCarbideProject = $this->ferniteCarbideProjectOfTheOldCalculation();

        $outcomes = $this->recalculationOf($ceramicPowderProject, $ferniteCarbideProject)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [
                [self::CERAMIC_POWDER_PROJECT_ID, 'Ceramic Powder', self::CERAMIC_POWDER_RUNS, ProjectCorrectionStatus::Failed, null, 'No Jita nor structure sell price for type ID 16660'],
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_CHANGE_AT_JITA, null],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame([4_800.0, 'jita', 144.0], self::estimatedSellPriceOf($ceramicPowderProject));
        self::assertSame([2_400_000.0, 'jita', 72_000.0], self::estimatedSellPriceOf($ferniteCarbideProject));
    }

    public function testProjectWhoseProductHasNoProducerInTheSdeFailsAndTheNextProjectIsRecalculated(): void
    {
        $roboticsProject = $this->project(self::ROBOTICS_PROJECT_ID, self::ROBOTICS, 6, '2026-10-12T09:00:00+00:00')
            ->setEstimatedSellPrice(90_000.0)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(2_700.0);
        $ferniteCarbideProject = $this->ferniteCarbideProjectOfTheOldCalculation();

        $outcomes = $this->recalculationOf($roboticsProject, $ferniteCarbideProject)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [
                [self::ROBOTICS_PROJECT_ID, 'Robotics', 6, ProjectCorrectionStatus::Failed, null, 'No blueprint or reaction found for product type ID 9848'],
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_CHANGE_AT_JITA, null],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame([90_000.0, 'jita', 2_700.0], self::estimatedSellPriceOf($roboticsProject));
        self::assertSame([2_400_000.0, 'jita', 72_000.0], self::estimatedSellPriceOf($ferniteCarbideProject));
    }

    public function testFailedWriteThatClosesTheEntityManagerFailsTheProjectAndLeavesEveryNextProjectNotProcessed(): void
    {
        $this->flushFailure = new \RuntimeException('SQLSTATE[08006]: connection to the database lost');
        $ferniteCarbideProject = $this->ferniteCarbideProjectOfTheOldCalculation();
        $secondFerniteCarbideProject = $this->project(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, self::FERNITE_CARBIDE, 4, '2026-10-20T09:00:00+00:00')
            ->setEstimatedSellPrice(120.0)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(3.6);

        $outcomes = $this->recalculationOf($ferniteCarbideProject, $secondFerniteCarbideProject)->run(new \DateTimeImmutable(self::CREATED_BEFORE), false);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Failed, null, 'SQLSTATE[08006]: connection to the database lost'],
                [self::SECOND_FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 4, ProjectCorrectionStatus::Failed, null, 'not processed: the entity manager was closed by the failure of project '.self::FERNITE_CARBIDE_PROJECT_ID],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame([120.0, 'jita', 3.6], self::estimatedSellPriceOf($secondFerniteCarbideProject));
    }

    public function testNoProjectGivesNoOutcome(): void
    {
        self::assertSame([], $this->recalculationOf()->run(new \DateTimeImmutable(self::CREATED_BEFORE), false));
    }

    private function ferniteCarbideProjectOfTheOldCalculation(?User $owner = null): IndustryProject
    {
        return $this->project(self::FERNITE_CARBIDE_PROJECT_ID, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS, owner: $owner)
            ->setEstimatedSellPrice(self::SELL_PRICE_OF_THE_OLD_CALCULATION)
            ->setEstimatedSellPriceSource('jita')
            ->setEstimatedTaxAmount(self::TAX_OF_THE_OLD_CALCULATION);
    }

    private function project(string $projectId, int $productTypeId, int $runs, string $createdAt = self::CREATED_BEFORE_THE_DEPLOYMENT, ?User $owner = null): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($owner ?? new User())
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::fromString($projectId));
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable($createdAt));

        return $project;
    }

    private function recalculationOf(IndustryProject ...$projectsInCreationOrder): ReactionProjectSellPriceRecalculation
    {
        $projectsById = [];
        foreach ($projectsInCreationOrder as $project) {
            $projectsById[$project->getId()?->toRfc4122()] = $project;
        }
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findIdsInCreationOrder')->willReturn(array_map(
            static fn (IndustryProject $project): ?Uuid => $project->getId(),
            array_values($projectsInCreationOrder),
        ));
        $projectRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProject => $projectsById[(string) $id] ?? null,
        );

        return self::autowired(ReactionProjectSellPriceRecalculation::class, [
            $projectRepository,
            $this->entityManagerRecordingWrites(),
            $this->producersOfTheSde(),
            $this->jitaPrices(),
            $this->preferredStructurePrices(),
            $this->settingsRepositoryOfEveryUser(),
            $this->calculationServiceOnSdeKnowing([
                self::FERNITE_CARBIDE => 'Fernite Carbide',
                self::CERAMIC_POWDER => 'Ceramic Powder',
                self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME,
                self::ROBOTICS => 'Robotics',
            ]),
        ]);
    }

    private function entityManagerRecordingWrites(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(fn (): bool => $this->entityManagerIsOpen);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'flush';
            if ($this->flushFailure !== null) {
                $this->entityManagerIsOpen = false;

                throw $this->flushFailure;
            }
        });
        $entityManager->method('clear')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'clear';
        });
        $entityManager->method('persist')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'persist';
        });
        $entityManager->method('remove')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'remove';
        });

        return $entityManager;
    }

    private function producersOfTheSde(): IndustryActivityProductRepository
    {
        $producersByProductAndActivity = [
            self::FERNITE_CARBIDE => [IndustryActivityType::Reaction->value => self::producer(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN)],
            self::CERAMIC_POWDER => [IndustryActivityType::Reaction->value => self::producer(self::CERAMIC_POWDER_REACTION_FORMULA, IndustryActivityType::Reaction, self::CERAMIC_POWDER, self::CERAMIC_POWDER_UNITS_PER_RUN)],
            self::NITROGEN_FUEL_BLOCK_TYPE_ID => [IndustryActivityType::Manufacturing->value => self::producer(self::NITROGEN_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::NITROGEN_FUEL_BLOCK_UNITS_PER_RUN)],
        ];
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct => $producersByProductAndActivity[$productTypeId][$activityId] ?? null,
        );

        return $productRepository;
    }

    private static function producer(int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId, int $unitsPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity($unitsPerRun);
    }

    private function jitaPrices(): JitaMarketService
    {
        $jitaPricesByType = [
            self::FERNITE_CARBIDE => self::FERNITE_CARBIDE_JITA_PRICE,
            self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_JITA_PRICE,
            self::ROBOTICS => self::ROBOTICS_JITA_PRICE,
        ];
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturnCallback(
            static fn (int $typeId): ?float => $jitaPricesByType[$typeId] ?? null,
        );

        return $jitaMarketService;
    }

    private function preferredStructurePrices(): StructureMarketService
    {
        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            static fn (int $structureId, int $typeId): ?float => $structureId === self::PREFERRED_MARKET_STRUCTURE_ID && $typeId === self::FERNITE_CARBIDE
                ? self::FERNITE_CARBIDE_STRUCTURE_PRICE
                : null,
        );

        return $structureMarketService;
    }

    private function settingsRepositoryOfEveryUser(): IndustryUserSettingsRepository
    {
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(
            (new IndustryUserSettings())->setBrokerFeeRate(self::BROKER_FEE_RATE)->setSalesTaxRate(self::SALES_TAX_RATE),
        );

        return $settingsRepository;
    }

    private static function summaryOf(array $outcomes): array
    {
        return array_map(
            static fn (ProjectCorrectionOutcome $outcome): array => [$outcome->projectId, $outcome->label, $outcome->runs, $outcome->status, $outcome->change, $outcome->reason],
            $outcomes,
        );
    }

    private static function estimatedSellPriceOf(IndustryProject $project): array
    {
        return [$project->getEstimatedSellPrice(), $project->getEstimatedSellPriceSource(), $project->getEstimatedTaxAmount()];
    }
}

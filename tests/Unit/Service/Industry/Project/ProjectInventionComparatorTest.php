<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Runs;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\Project\ProjectInventionComparator;
use App\Service\Industry\ProfitMarginService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProjectInventionComparatorTest extends TestCase
{
    private const int HAMMERHEAD_II = 2_185;
    private const int ISHTAR = 12_005;
    private const int VEXOR = 626;
    private const int RIFTER = 587;

    private ProfitMarginService&MockObject $profitMargin;
    private IndustryUserSettingsRepository&MockObject $settings;
    private User $owner;

    protected function setUp(): void
    {
        $this->profitMargin = $this->createMock(ProfitMarginService::class);
        $this->settings = $this->createMock(IndustryUserSettingsRepository::class);
        $this->owner = new User();
    }

    public function testProductThatIsNotInventedIsSkippedAndNeverAnalyzed(): void
    {
        $project = $this->projectOf([[self::VEXOR, 'Vexor', 3, 10, 20]]);
        $this->profitMargin->expects(self::never())->method('analyze');

        self::assertSame([], $this->comparator([])->compare($project, null));
    }

    public function testInventedProductIsAnalyzedAtTheRunsMeAndTeOfTheProjectForItsOwnerAndTheChosenInventor(): void
    {
        $project = $this->projectOf([[self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4]]);
        $inventor = new Character();
        $this->settings->method('findOneBy')->with(['user' => $this->owner])->willReturn(null);
        $received = [];
        $this->profitMargin->expects(self::once())->method('analyze')->willReturnCallback(
            function (...$arguments) use (&$received): array {
                $received = $arguments;

                return $this->analysisOf(self::HAMMERHEAD_II, []);
            },
        );

        $this->comparator([self::HAMMERHEAD_II])->compare($project, $inventor);

        self::assertSame(
            [self::HAMMERHEAD_II, 50, 2, 4, EveConstants::JITA_STATION_ID, EveConstants::JITA_SOLAR_SYSTEM_ID, null, null, $this->owner, EveConstants::JITA_SOLAR_SYSTEM_ID, $inventor],
            $received,
        );
    }

    #[Group('issue-880')]
    public function testFirstMarketStructureOfTheOwnerListIsTheSellStructureOfTheAnalysisWhenNoPreferredStructureIsSet(): void
    {
        $this->owner->setMarketStructures([
            ['id' => 1_049_588_174_021, 'name' => 'C-J6MT'],
            ['id' => 1_035_466_617_946, 'name' => '4-HWWF'],
        ]);
        $project = $this->projectOf([[self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4]]);
        $this->settings->method('findOneBy')->willReturn(null);
        $sellStructureId = null;
        $this->profitMargin->method('analyze')->willReturnCallback(
            function (...$arguments) use (&$sellStructureId): array {
                $sellStructureId = $arguments[4];

                return $this->analysisOf(self::HAMMERHEAD_II, []);
            },
        );

        $this->comparator([self::HAMMERHEAD_II])->compare($project, null);

        self::assertSame(1_049_588_174_021, $sellStructureId);
    }

    public function testFavoriteSystemsOfTheOwnerAndNoInventorAreForwardedToTheAnalysis(): void
    {
        $project = $this->projectOf([[self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4]]);
        $this->settings->method('findOneBy')->willReturn(
            (new IndustryUserSettings())->setFavoriteManufacturingSystemId(30_002_187)->setFavoriteReactionSystemId(30_000_144),
        );
        $received = [];
        $this->profitMargin->method('analyze')->willReturnCallback(
            function (...$arguments) use (&$received): array {
                $received = $arguments;

                return $this->analysisOf(self::HAMMERHEAD_II, []);
            },
        );

        $this->comparator([self::HAMMERHEAD_II])->compare($project, null);

        self::assertSame([30_002_187, 30_000_144, null], [$received[5], $received[9], $received[10]]);
    }

    public function testEntryCarriesTheProjectProductAndKeepsTheOptionsOfTheAnalysisWithoutTheMargin(): void
    {
        $project = $this->projectOf([[self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4]]);
        $this->settings->method('findOneBy')->willReturn(null);
        $this->profitMargin->method('analyze')->willReturn($this->analysisOf(self::HAMMERHEAD_II, [
            $this->optionOf(null, 'No decryptor', 0.34, 10, 8.5, 5, 1_245_000.0, 0.0, 1_100_000.0, 0.0),
            $this->optionOf(34_201, 'Accelerant Decryptor', 0.51, 11, 5.0, 5, 980_000.0, 900_000.0, 1_050_000.0, -50_000.0),
        ]));

        $entries = $this->comparator([self::HAMMERHEAD_II])->compare($project, null);

        self::assertSame(
            [
                [
                    'typeId' => self::HAMMERHEAD_II,
                    'typeName' => 'Hammerhead II',
                    'runs' => 50,
                    'me' => 2,
                    'te' => 4,
                    'invention' => [
                        'baseProbability' => 0.34,
                        'unknownReason' => null,
                        'datacores' => ['Datacore - Electronic Engineering', 'Datacore - Graviton Physics'],
                        'datacoresCostPerAttempt' => 74_000.0,
                        'inventor' => null,
                        'options' => [
                            $this->publicOptionOf(null, 'No decryptor', 0.34, 10, 8.5, 5, 1_245_000.0, 0.0, 1_100_000.0, 0.0),
                            $this->publicOptionOf(34_201, 'Accelerant Decryptor', 0.51, 11, 5.0, 5, 980_000.0, 900_000.0, 1_050_000.0, -50_000.0),
                        ],
                    ],
                ],
            ],
            $entries,
        );
    }

    public function testUnknownProbabilityAndCostsStayNullWithTheirReason(): void
    {
        $project = $this->projectOf([[self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4]]);
        $this->settings->method('findOneBy')->willReturn(null);
        $unknownOption = $this->optionOf(null, 'No decryptor', null, 10, null, 5, null, null, null, null);
        $analysis = $this->analysisOf(self::HAMMERHEAD_II, [$unknownOption]);
        $analysis['invention']['unknownReason'] = 'missing_inventor_skills';
        $this->profitMargin->method('analyze')->willReturn($analysis);

        $entry = $this->comparator([self::HAMMERHEAD_II])->compare($project, new Character())[0]['invention'];

        self::assertSame(
            ['missing_inventor_skills', null, null, null, null],
            [$entry['unknownReason'], $entry['options'][0]['probability'], $entry['options'][0]['expectedAttempts'], $entry['options'][0]['costPerUnit'], $entry['options'][0]['inventionCost']],
        );
    }

    public function testOnlyInventedProductsAreComparedInTheOrderOfTheProject(): void
    {
        $project = $this->projectOf([
            [self::ISHTAR, 'Ishtar', 2, 4, 8],
            [self::RIFTER, 'Rifter', 5, 10, 20],
            [self::HAMMERHEAD_II, 'Hammerhead II', 50, 2, 4],
        ]);
        $this->settings->method('findOneBy')->willReturn(null);
        $this->profitMargin->method('analyze')->willReturnCallback(
            fn (int $typeId): array => $this->analysisOf($typeId, []),
        );

        $entries = $this->comparator([self::ISHTAR, self::HAMMERHEAD_II])->compare($project, null);

        self::assertSame([self::ISHTAR, self::HAMMERHEAD_II], array_column($entries, 'typeId'));
    }

    /**
     * @param list<int> $inventedTypeIds
     */
    private function comparator(array $inventedTypeIds): ProjectInventionComparator
    {
        $inventions = $this->createMock(InventionCatalog::class);
        $inventions->method('inventionOf')->willReturnCallback(
            static fn (int $typeId): ?InventionRecipe => \in_array($typeId, $inventedTypeIds, true)
                ? new InventionRecipe(1_000 + $typeId, 2_000 + $typeId, 0.34, new Runs(10), [])
                : null,
        );

        return new ProjectInventionComparator($this->profitMargin, $inventions, $this->settings);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int, 3: int, 4: int}> $products typeId, name, runs, me, te
     */
    private function projectOf(array $products): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
        foreach ($products as [$typeId, $name, $runs, $meLevel, $teLevel]) {
            $project->addItem(
                (new IndustryProjectProduct())->setTypeId($typeId)->setTypeName($name)->setRuns($runs)->setMeLevel($meLevel)->setTeLevel($teLevel),
            );
        }

        return $project;
    }

    /**
     * @param list<array<string, mixed>> $options
     *
     * @return array<string, mixed>
     */
    private function analysisOf(int $typeId, array $options): array
    {
        return [
            'typeId' => $typeId,
            'typeName' => 'Analysis name',
            'invention' => [
                'baseProbability' => 0.34,
                'unknownReason' => null,
                'datacores' => ['Datacore - Electronic Engineering', 'Datacore - Graviton Physics'],
                'datacoresCostPerAttempt' => 74_000.0,
                'inventor' => null,
                'selectedDecryptorTypeId' => null,
                'selectedDecryptorName' => 'No decryptor',
                'options' => $options,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionOf(?int $decryptorTypeId, string $name, ?float $probability, int $runs, ?float $attempts, int $jobs, ?float $inventionCost, ?float $decryptorCost, ?float $costPerUnit, ?float $delta): array
    {
        return [...$this->publicOptionOf($decryptorTypeId, $name, $probability, $runs, $attempts, $jobs, $inventionCost, $decryptorCost, $costPerUnit, $delta), 'bestMargin' => 12.5];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicOptionOf(?int $decryptorTypeId, string $name, ?float $probability, int $runs, ?float $attempts, int $jobs, ?float $inventionCost, ?float $decryptorCost, ?float $costPerUnit, ?float $delta): array
    {
        return [
            'decryptorTypeId' => $decryptorTypeId,
            'decryptorName' => $name,
            'me' => 2,
            'te' => 4,
            'runs' => $runs,
            'probability' => $probability,
            'inventionCost' => $inventionCost,
            'totalProductionCost' => $costPerUnit,
            'expectedAttempts' => $attempts,
            'manufacturingJobs' => $jobs,
            'decryptorCost' => $decryptorCost,
            'costPerUnit' => $costPerUnit,
            'costPerUnitDelta' => $delta,
        ];
    }
}

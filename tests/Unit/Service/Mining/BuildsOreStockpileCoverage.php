<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\ReprocessingProfile;
use App\Entity\Sde\InvType;
use App\Entity\Sde\InvTypeMaterial;
use App\Entity\User;
use App\Enum\ReprocessingFailure;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\Mining\OreStockpileCoverageService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;

trait BuildsOreStockpileCoverage
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int MORPHITE = 11_399;
    private const int VELDSPAR = 1_230;
    private const int SCORDITE = 1_228;
    private const int PYROXERES = 1_224;
    private const int OMBER = 1_227;
    private const int FULLERITE_C50 = 30_370;
    private const float VALUE_DELTA = 1e-9;
    private const string MINING_DAY = '2026-11-05';
    private const array TYPE_NAMES = [
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::ISOGEN => 'Isogen',
        self::MORPHITE => 'Morphite',
        self::VELDSPAR => 'Veldspar',
        self::SCORDITE => 'Scordite',
        self::PYROXERES => 'Pyroxeres',
        self::OMBER => 'Omber',
        self::FULLERITE_C50 => 'Fullerite-C50',
    ];
    private const array SDE_PORTION_SIZES = [
        self::TRITANIUM => 1,
        self::PYERITE => 1,
        self::MEXALLON => 1,
        self::ISOGEN => 1,
        self::MORPHITE => 1,
        self::VELDSPAR => 100,
        self::SCORDITE => 100,
        self::PYROXERES => 250,
        self::OMBER => 500,
        self::FULLERITE_C50 => 10,
    ];
    private const array REPROCESSING_MATERIALS = [
        self::VELDSPAR => [self::TRITANIUM => 400],
        self::SCORDITE => [self::TRITANIUM => 150, self::PYERITE => 90],
        self::PYROXERES => [self::PYERITE => 59, self::MEXALLON => 120],
        self::OMBER => [self::PYERITE => 75, self::ISOGEN => 22],
    ];
    private const array COVERAGE_KEYS = ['operationId', 'state', 'unknownReason', 'yieldKnown', 'reprocessYield', 'minerals'];
    private const array MINERAL_KEYS = ['typeId', 'typeName', 'producedQuantity', 'deficit', 'hasDeficit', 'coveragePercent', 'coversWholeDeficit', 'coverageUnknownReason'];

    private MiningOperation $operation;

    private array $portionSizes = self::SDE_PORTION_SIZES;

    private array $reprocessingProfiles = [];

    private array $shoppingListsByUserId = [];

    private function setUpOreStockpileCoverage(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperationWithBrann();
        $this->identifyEnrolments();
    }

    private function brannMember(): MiningOperationMember
    {
        return $this->membershipOf($this->operation, $this->brann) ?? throw new \LogicException('Brann is a member');
    }

    private function minedDuringOperation(array $minedByCharacterName): void
    {
        $characterNames = ['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'];
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach ($characterNames as $characterName) {
            $this->takenStartSnapshot($characterName, []);
        }
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        foreach ($characterNames as $characterName) {
            $this->takenEndSnapshot($characterName, $this->entriesOf($minedByCharacterName[$characterName] ?? []));
        }
    }

    private function entriesOf(array $quantitiesByTypeId): array
    {
        $entries = [];
        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $entries[] = $this->entry(self::MINING_DAY, $typeId, self::S1, $quantity);
        }

        return $entries;
    }

    private function reprocessingYieldOf(User $user, ?float $reprocessYield): void
    {
        $this->reprocessingProfiles[] = (new ReprocessingProfile())
            ->setUser($user)
            ->setReprocessYield($reprocessYield)
            ->setFailure($reprocessYield === null ? ReprocessingFailure::EsiError : null);
    }

    private function stockpileDeficitsOf(User $user, array $deficitsByTypeId): void
    {
        $shoppingList = [];
        foreach ($deficitsByTypeId as $typeId => $deficit) {
            $shoppingList[] = ['typeId' => $typeId, 'typeName' => self::TYPE_NAMES[$typeId], 'deficit' => $deficit];
        }
        $this->shoppingListsByUserId[$user->getId()->toRfc4122()] = $shoppingList;
    }

    private function coverageService(): OreStockpileCoverageService
    {
        $profileRepository = $this->createStub(ReprocessingProfileRepository::class);
        $profileRepository->method('findOneBy')->willReturnCallback(function (array $criteria): ?ReprocessingProfile {
            foreach ($this->reprocessingProfiles as $profile) {
                if ($profile->getUser() === ($criteria['user'] ?? null)) {
                    return $profile;
                }
            }

            return null;
        });

        $materialRepository = $this->createStub(InvTypeMaterialRepository::class);
        $materialRepository->method('findByTypeIds')->willReturnCallback(static function (array $typeIds): array {
            $materialsByTypeId = [];
            foreach (array_intersect_key(self::REPROCESSING_MATERIALS, array_flip($typeIds)) as $typeId => $materials) {
                foreach ($materials as $materialTypeId => $quantity) {
                    $materialsByTypeId[$typeId][] = (new InvTypeMaterial())->setTypeId($typeId)->setMaterialTypeId($materialTypeId)->setQuantity($quantity);
                }
            }

            return $materialsByTypeId;
        });

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('findByTypeIds')->willReturnCallback(function (array $typeIds): array {
            $types = [];
            foreach (array_intersect_key(self::TYPE_NAMES, array_flip($typeIds)) as $typeId => $typeName) {
                $types[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($typeName)->setPortionSize($this->portionSizes[$typeId]);
            }

            return $types;
        });

        $stockpileService = $this->createStub(StockpileService::class);
        $stockpileService->method('getStockpileStatus')->willReturnCallback(function (User $user): array {
            $shoppingList = $this->shoppingListsByUserId[$user->getId()->toRfc4122()] ?? [];

            return ['targetCount' => count($shoppingList), 'shoppingList' => $shoppingList];
        });

        return new OreStockpileCoverageService(
            new MinedQuantityCalculator(),
            $profileRepository,
            $materialRepository,
            $typeRepository,
            $stockpileService,
        );
    }

    private function coverage(string $state, ?string $unknownReason, bool $yieldKnown, ?float $reprocessYield, array $minerals): array
    {
        return [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'state' => $state,
            'unknownReason' => $unknownReason,
            'yieldKnown' => $yieldKnown,
            'reprocessYield' => $reprocessYield,
            'minerals' => $minerals,
        ];
    }

    private function mineral(
        int $typeId,
        ?float $producedQuantity,
        int $deficit,
        bool $hasDeficit,
        ?float $coveragePercent,
        ?bool $coversWholeDeficit,
        ?string $coverageUnknownReason = null,
    ): array {
        return [
            'typeId' => $typeId,
            'typeName' => self::TYPE_NAMES[$typeId],
            'producedQuantity' => $producedQuantity,
            'deficit' => $deficit,
            'hasDeficit' => $hasDeficit,
            'coveragePercent' => $coveragePercent,
            'coversWholeDeficit' => $coversWholeDeficit,
            'coverageUnknownReason' => $coverageUnknownReason,
        ];
    }

    private function assertCoverage(array $expected, array $actual): void
    {
        self::assertSame(self::sortedKeys(self::COVERAGE_KEYS), self::sortedKeys(array_keys($actual)));
        self::assertIsArray($actual['minerals']);
        self::assertSame(array_column($expected['minerals'], 'typeId'), array_column($actual['minerals'], 'typeId'));

        foreach ($expected['minerals'] as $index => $expectedMineral) {
            $actualMineral = $actual['minerals'][$index];
            self::assertSame(self::sortedKeys(self::MINERAL_KEYS), self::sortedKeys(array_keys($actualMineral)), sprintf('keys of mineral %d', $expectedMineral['typeId']));
            if (is_float($expectedMineral['producedQuantity']) && is_float($actualMineral['producedQuantity'])) {
                self::assertEqualsWithDelta($expectedMineral['producedQuantity'], $actualMineral['producedQuantity'], self::VALUE_DELTA, sprintf('produced quantity of mineral %d', $expectedMineral['typeId']));
                $actualMineral['producedQuantity'] = $expectedMineral['producedQuantity'];
            }
            ksort($expectedMineral);
            ksort($actualMineral);
            self::assertSame($expectedMineral, $actualMineral, sprintf('mineral %d', $expectedMineral['typeId']));
        }

        $expectedWithoutMinerals = array_diff_key($expected, ['minerals' => true]);
        $actualWithoutMinerals = array_diff_key($actual, ['minerals' => true]);
        ksort($expectedWithoutMinerals);
        ksort($actualWithoutMinerals);
        self::assertSame($expectedWithoutMinerals, $actualWithoutMinerals);
    }

    private static function sortedKeys(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}

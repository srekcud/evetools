<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Dto\Assets\AssetScope;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryCustomsOfficeStockReader;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1390')]
#[CoversClass(PlanetaryCustomsOfficeStockReader::class)]
final class PlanetaryCustomsOfficeStockReaderTest extends TestCase
{
    private const string ALMA_UUID = '0193a000-0000-7000-8000-000000001390';
    private const string CORA_UUID = '0193a000-0000-7000-8000-000000001391';
    private const string STRANGER_UUID = '0193a000-0000-7000-8000-000000001392';

    private const int FACTORY_TYPE_ID = 2473;
    private const int EXTRACTOR_TYPE_ID = 2848;

    private const int BACTERIA = 2393;
    private const int PROTEINS = 2395;
    private const int OXYGEN = 3683;
    private const int SILICON = 9828;
    private const int ROBOTICS = 9848;
    private const int NANITES = 2867;

    private const int BACTERIA_TO_OXYGEN = 65;
    private const int OXYGEN_TO_ROBOTICS = 67;
    private const int SILICON_TO_NANITES = 68;

    private const int PLANET_ALPHA = 40_390_001;
    private const int PLANET_BETA = 40_390_002;

    private const int FACTORY_PIN = 1_390_000_001;
    private const int EXTRACTOR_PIN = 1_390_000_002;

    private AssetBrowseRepository $assetBrowseRepository;
    private PlanetSchematicTypeRepository $schematicTypeRepository;
    private InvTypeRepository $invTypeRepository;
    private TypeNameResolver $typeNameResolver;
    private User $user;

    /** @var array<string, array<int, int>> */
    private array $customsByCharacterUuid = [];

    /** @var list<array{string|null, list<int>}> */
    private array $customsRequests = [];

    /** @var array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}> */
    private array $schematicMap = [];

    /** @var array<int, InvType> */
    private array $sdeTypes = [];

    protected function setUp(): void
    {
        $this->assetBrowseRepository = $this->createStub(AssetBrowseRepository::class);
        $this->assetBrowseRepository->method('customsOfficeQuantities')->willReturnCallback(
            function (AssetScope $scope, array $typeIds): array {
                $this->customsRequests[] = [$scope->characterId, $typeIds];

                return array_intersect_key($this->customsByCharacterUuid[$scope->characterId] ?? [], array_flip($typeIds));
            },
        );

        $this->schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $this->schematicTypeRepository->method('getSchematicMap')->willReturnCallback(fn (): array => $this->schematicMap);

        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->invTypeRepository->method('findByTypeIds')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->sdeTypes, array_flip($typeIds)),
        );
        $this->invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $this->invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);

        $this->typeNameResolver = $this->createStub(TypeNameResolver::class);
        $this->typeNameResolver->method('findName')->willReturnCallback(
            fn (int $typeId): ?string => isset($this->sdeTypes[$typeId]) ? $this->sdeTypes[$typeId]->getTypeName() : null,
        );
        $this->typeNameResolver->method('findNames')->willReturnCallback(
            fn (array $typeIds): array => array_map(
                static fn (InvType $type): string => $type->getTypeName(),
                array_intersect_key($this->sdeTypes, array_flip($typeIds)),
            ),
        );

        $this->schematicMap = [
            self::BACTERIA_TO_OXYGEN => ['inputs' => [self::BACTERIA => 40], 'output' => ['typeId' => self::OXYGEN, 'quantity' => 20]],
            self::OXYGEN_TO_ROBOTICS => ['inputs' => [self::OXYGEN => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3]],
            self::SILICON_TO_NANITES => ['inputs' => [self::SILICON => 30], 'output' => ['typeId' => self::NANITES, 'quantity' => 5]],
        ];
        $this->sdeTypes = [
            self::BACTERIA => $this->sdeType(self::BACTERIA, 'Bacteria', 1.5),
            self::PROTEINS => $this->sdeType(self::PROTEINS, 'Proteins', null),
            self::OXYGEN => $this->sdeType(self::OXYGEN, 'Oxygen', 0.25),
            self::SILICON => $this->sdeType(self::SILICON, 'Silicon', 0.75),
            self::ROBOTICS => $this->sdeType(self::ROBOTICS, 'Robotics', 6.0),
            self::NANITES => $this->sdeType(self::NANITES, 'Nanites', 4.0),
        ];

        $this->user = new User();
        $this->user->addCharacter($this->character(self::ALMA_UUID, 2_139_000_001, 'Alma'));
        $this->user->addCharacter($this->character(self::CORA_UUID, 2_139_000_002, 'Cora'));
    }

    public function testTypesConsumedOrProducedByTheColoniesAreReadAndTheRowsAreSortedByTypeId(): void
    {
        $this->customsByCharacterUuid = [
            self::ALMA_UUID => [self::OXYGEN => 55, self::BACTERIA => 700, self::PROTEINS => 20, self::NANITES => 999],
        ];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN), $this->extractorPin(self::PROTEINS));

        $rows = $this->reader()->read($this->user, [$alpha]);

        $alphaPlanet = ['colonyId' => $alpha->getId()?->toRfc4122(), 'planetName' => 'Aldranette II'];
        self::assertSame(
            [
                ['typeId' => self::BACTERIA, 'typeName' => 'Bacteria', 'quantity' => 700, 'volumePerUnit' => 1.5, 'volumeM3' => 1050.0, 'probablePlanet' => $alphaPlanet],
                ['typeId' => self::PROTEINS, 'typeName' => 'Proteins', 'quantity' => 20, 'volumePerUnit' => null, 'volumeM3' => null, 'probablePlanet' => $alphaPlanet],
                ['typeId' => self::OXYGEN, 'typeName' => 'Oxygen', 'quantity' => 55, 'volumePerUnit' => 0.25, 'volumeM3' => 13.75, 'probablePlanet' => $alphaPlanet],
            ],
            $rows,
        );
        self::assertCount(2, $this->customsRequests);
        foreach ($this->customsRequests as [, $typeIds]) {
            self::assertEqualsCanonicalizing([self::BACTERIA, self::OXYGEN, self::PROTEINS], $typeIds);
        }
    }

    public function testQuantitiesOfEveryCharacterOfTheUserAreSummedAndTheCharactersOfAnotherUserAreNotRead(): void
    {
        $this->customsByCharacterUuid = [
            self::ALMA_UUID => [self::BACTERIA => 700],
            self::CORA_UUID => [self::BACTERIA => 500],
            self::STRANGER_UUID => [self::BACTERIA => 9_999],
        ];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        $rows = $this->reader()->read($this->user, [$alpha]);

        self::assertSame([[self::BACTERIA, 1_200, 1_800.0]], array_map(
            static fn (array $row): array => [$row['typeId'], $row['quantity'], $row['volumeM3']],
            $rows,
        ));
        self::assertSame([self::ALMA_UUID, self::CORA_UUID], array_column($this->customsRequests, 0));
    }

    public function testATypeWithAQuantityOfZeroHasNoRow(): void
    {
        $this->customsByCharacterUuid = [
            self::ALMA_UUID => [self::BACTERIA => 0, self::OXYGEN => 55],
            self::CORA_UUID => [self::BACTERIA => 0],
        ];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        $rows = $this->reader()->read($this->user, [$alpha]);

        self::assertSame([self::OXYGEN], array_column($rows, 'typeId'));
    }

    public function testATypeNoColonyConsumesOrProducesHasNoRowAndNoColonyGivesNoRow(): void
    {
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::NANITES => 999, self::ROBOTICS => 40]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        self::assertSame([], $this->reader()->read($this->user, [$alpha]));
        self::assertSame([], $this->reader()->read($this->user, []));
    }

    public function testFactoryWithAnUnknownSchematicDoesNotGuessItsTypes(): void
    {
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::BACTERIA => 700, self::OXYGEN => 55]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(999));

        self::assertSame([], $this->reader()->read($this->user, [$alpha]));
    }

    public function testUserWithoutCharacterHasNoRowAndNothingIsRead(): void
    {
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        self::assertSame([], $this->reader()->read(new User(), [$alpha]));
        self::assertSame([], $this->customsRequests);
    }

    public function testProbablePlanetIsTheOnlyColonyThatConsumesOrProducesTheTypeEvaluatedPerType(): void
    {
        $this->customsByCharacterUuid = [
            self::ALMA_UUID => [self::BACTERIA => 700, self::OXYGEN => 55, self::PROTEINS => 20],
        ];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));
        $beta = $this->colony(self::PLANET_BETA, 'Brinmoor V', $this->extractorPin(self::PROTEINS));

        $rows = $this->reader()->read($this->user, [$alpha, $beta]);

        self::assertSame(
            [
                [self::BACTERIA, ['colonyId' => $alpha->getId()?->toRfc4122(), 'planetName' => 'Aldranette II']],
                [self::PROTEINS, ['colonyId' => $beta->getId()?->toRfc4122(), 'planetName' => 'Brinmoor V']],
                [self::OXYGEN, ['colonyId' => $alpha->getId()?->toRfc4122(), 'planetName' => 'Aldranette II']],
            ],
            array_map(static fn (array $row): array => [$row['typeId'], $row['probablePlanet']], $rows),
        );
    }

    public function testTypeConsumedByTwoColoniesHasNoProbablePlanet(): void
    {
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::SILICON => 120, self::BACTERIA => 700]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::SILICON_TO_NANITES), $this->factoryPin(self::BACTERIA_TO_OXYGEN, 1_390_000_011));
        $beta = $this->colony(self::PLANET_BETA, 'Brinmoor V', $this->factoryPin(self::SILICON_TO_NANITES));

        $rows = $this->reader()->read($this->user, [$alpha, $beta]);

        self::assertSame(
            [
                [self::BACTERIA, ['colonyId' => $alpha->getId()?->toRfc4122(), 'planetName' => 'Aldranette II']],
                [self::SILICON, null],
            ],
            array_map(static fn (array $row): array => [$row['typeId'], $row['probablePlanet']], $rows),
        );
        self::assertSame([700, 120], array_column($rows, 'quantity'));
    }

    public function testTypeProducedByOneColonyAndConsumedByAnotherHasNoProbablePlanet(): void
    {
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::OXYGEN => 55, self::ROBOTICS => 12, self::BACTERIA => 700]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::OXYGEN_TO_ROBOTICS));
        $beta = $this->colony(self::PLANET_BETA, 'Brinmoor V', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        $rows = $this->reader()->read($this->user, [$alpha, $beta]);

        self::assertSame(
            [
                [self::BACTERIA, ['colonyId' => $beta->getId()?->toRfc4122(), 'planetName' => 'Brinmoor V']],
                [self::OXYGEN, null],
                [self::ROBOTICS, ['colonyId' => $alpha->getId()?->toRfc4122(), 'planetName' => 'Aldranette II']],
            ],
            array_map(static fn (array $row): array => [$row['typeId'], $row['probablePlanet']], $rows),
        );
    }

    public function testTypeProducedByAnExtractorOfTwoColoniesHasNoProbablePlanet(): void
    {
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::PROTEINS => 20]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->extractorPin(self::PROTEINS));
        $beta = $this->colony(self::PLANET_BETA, 'Brinmoor V', $this->extractorPin(self::PROTEINS));

        $rows = $this->reader()->read($this->user, [$alpha, $beta]);

        self::assertSame([[self::PROTEINS, null]], array_map(static fn (array $row): array => [$row['typeId'], $row['probablePlanet']], $rows));
    }

    public function testTypeMissingFromTheSdeKeepsAnUnknownNameAndAnUnknownVolume(): void
    {
        unset($this->sdeTypes[self::OXYGEN]);
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::OXYGEN => 55]];
        $alpha = $this->colony(self::PLANET_ALPHA, 'Aldranette II', $this->factoryPin(self::BACTERIA_TO_OXYGEN));

        $rows = $this->reader()->read($this->user, [$alpha]);

        self::assertCount(1, $rows);
        self::assertSame(
            [self::OXYGEN, null, 55, null, null],
            [$rows[0]['typeId'], $rows[0]['typeName'], $rows[0]['quantity'], $rows[0]['volumePerUnit'], $rows[0]['volumeM3']],
        );
    }

    private function reader(): PlanetaryCustomsOfficeStockReader
    {
        return new PlanetaryCustomsOfficeStockReader(
            $this->assetBrowseRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
        );
    }

    private function character(string $uuid, int $eveCharacterId, string $name): Character
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($uuid));

        return $character;
    }

    private function sdeType(int $typeId, string $typeName, ?float $volume): InvType
    {
        return (new InvType())->setTypeId($typeId)->setTypeName($typeName)->setVolume($volume);
    }

    private function colony(int $planetId, string $planetName, PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = (new PlanetaryColony())
            ->setPlanetId($planetId)
            ->setPlanetName($planetName)
            ->setPlanetType('temperate')
            ->setSolarSystemName('Aldranette');
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::v4());
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function factoryPin(int $schematicId, int $pinId = self::FACTORY_PIN): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId($schematicId);
    }

    private function extractorPin(int $productTypeId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId(self::EXTRACTOR_PIN)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId);
    }
}

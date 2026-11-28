<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryPlannerResource;
use App\Entity\Character;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\User;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;
use App\Service\Planetary\PlanetaryPlanetTypeCatalog;
use App\Service\Planetary\PlanetaryPlannerCatalog;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Support\BuildsPlanetaryPlannerUserPrices;
use App\Tests\Unit\Support\StubsPlanetaryTypes;
use App\State\Provider\Planetary\PlanetaryPlannerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryPlannerProvider::class)]
#[Group('issue-1402')]
#[Group('issue-1454')]
final class PlanetaryPlannerProviderTest extends TestCase
{
    use BuildsPlanetaryPlannerUserPrices;
    use StubsPlanetaryTypes;

    private Character $pilot;

    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int WATER = 91_011;
    private const int DEFAULT_HUB_ID = 1_049_588_174_021;
    private const string DEFAULT_HUB_NAME = 'Default Hub';
    private const int PREFERRED_HUB_ID = 1_040_000_000_007;
    private const string PREFERRED_HUB_NAME = 'Preferred Hub';

    protected function setUp(): void
    {
        $this->pilot = (new Character())->setEveCharacterId(2112000001)->setName('Pilot One');
    }

    public function testTheResourceCarriesTheRecipesTheTypesAndThePricesOfTheCatalogUnderTheFixedIdentifier(): void
    {
        $resource = $this->provide($this->pilotUser());

        $this->assertSame('planner', $resource->id);
        $this->assertSame([
            ['outputTypeId' => self::WATER, 'outputQuantity' => 20, 'cycleSeconds' => 1800, 'inputs' => [['typeId' => self::AQUEOUS_LIQUIDS, 'quantity' => 3000]]],
        ], $resource->recipes);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'name' => 'Aqueous Liquids', 'tier' => 'P0', 'volume' => 0.01],
            ['typeId' => self::WATER, 'name' => 'Water', 'tier' => 'P1', 'volume' => null],
        ], $resource->types);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'sell' => 6.0, 'buy' => null, 'hub' => null, 'paid' => 5.5],
            ['typeId' => self::WATER, 'sell' => 60.0, 'buy' => 50.0, 'hub' => 58.0, 'paid' => null],
        ], $resource->prices);
    }

    public function testTheResourceCarriesTheDefaultHubWithItsNameAndItsCacheStateWhenTheUserHasNoPreferredStructure(): void
    {
        $resource = $this->provide($this->pilotUser());

        $this->assertSame(['structureId' => self::DEFAULT_HUB_ID, 'structureName' => self::DEFAULT_HUB_NAME, 'hasData' => true], $resource->hub);
    }

    public function testTheHubIsThePreferredStructureOfTheUserAndItsPricesAreReadFromIt(): void
    {
        $user = $this->pilotUser()
            ->setPreferredMarketStructureId(self::PREFERRED_HUB_ID)
            ->setPreferredMarketStructureName(self::PREFERRED_HUB_NAME);

        $resource = $this->provide($user, hubStructureId: self::PREFERRED_HUB_ID);

        $this->assertSame(['structureId' => self::PREFERRED_HUB_ID, 'structureName' => self::PREFERRED_HUB_NAME, 'hasData' => true], $resource->hub);
        $this->assertSame([null, 58.0], array_column($resource->prices, 'hub'));
    }

    public function testAHubWithoutCachedMarketDataIsReportedAsSuchAndLeavesEveryHubPriceNull(): void
    {
        $resource = $this->provide($this->pilotUser(), hubHasData: false);

        $this->assertSame(['structureId' => self::DEFAULT_HUB_ID, 'structureName' => self::DEFAULT_HUB_NAME, 'hasData' => false], $resource->hub);
        $this->assertSame([null, null], array_column($resource->prices, 'hub'));
        $this->assertSame([5.5, null], array_column($resource->prices, 'paid'));
    }

    public function testTheResourceAlsoCarriesThePlanetTypesAndTheExtractionPlanetTypesOfTheCatalog(): void
    {
        $resource = $this->provide(new User(), self::planetaryTypeTable());

        $this->assertSame(['Barren', 'Gas', 'Ice'], array_column($resource->planetTypes, 'name'));
        $this->assertSame(['cpu' => 25_415, 'pg' => 19_000], $resource->planetTypes[0]['commandCenter']);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS_P0, 'planetTypes' => ['Barren', 'Gas']],
            ['typeId' => self::BASE_METALS_P0, 'planetTypes' => ['Barren', 'Ice']],
        ], $resource->extractionPlanetTypes);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null);
    }

    private function pilotUser(): User
    {
        $user = new User();
        $user->addCharacter($this->pilot);

        return $user;
    }

    private function provide(?User $user, array $planetaryTypeTable = ['types' => [], 'attributes' => []], int $hubStructureId = self::DEFAULT_HUB_ID, bool $hubHasData = true): PlanetaryPlannerResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('findAll')->willReturn([(new PlanetSchematic())->setSchematicId(201)->setCycleTime(1800)]);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            201 => ['inputs' => [self::AQUEOUS_LIQUIDS => 3000], 'output' => ['typeId' => self::WATER, 'quantity' => 20]],
        ]);
        $invTypes = [
            self::AQUEOUS_LIQUIDS => (new InvType())->setTypeId(self::AQUEOUS_LIQUIDS)->setTypeName('Aqueous Liquids')->setVolume(0.01),
            self::WATER => (new InvType())->setTypeId(self::WATER)->setTypeName('Water')->setVolume(null)->setMarketGroup((new InvMarketGroup())->setMarketGroupId(1334)),
        ];
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturn($invTypes);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $invTypes[$typeId] ?? null);
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturn([self::AQUEOUS_LIQUIDS => 6.0, self::WATER => 60.0]);
        $jitaMarketService->method('getBuyPrices')->willReturn([self::AQUEOUS_LIQUIDS => null, self::WATER => 50.0]);

        [$planetInvTypeRepository, $planetAttributeRepository] = $this->planetaryTypeRepositories($planetaryTypeTable);

        $catalog = new PlanetaryPlannerCatalog(
            $schematicRepository,
            $schematicTypeRepository,
            $invTypeRepository,
            new PlanetaryProductionCalculator($schematicRepository, $schematicTypeRepository, $invTypeRepository, $this->createStub(TypeNameResolver::class), $jitaMarketService, $this->createStub(ExtractorYieldCalculator::class)),
            $jitaMarketService,
            new PlanetaryPlanetTypeCatalog($planetInvTypeRepository, $planetAttributeRepository),
        );

        $userPrices = $this->plannerUserPrices(
            $hubStructureId,
            [self::WATER => 58.0],
            $hubHasData,
            [$this->plannerPurchase($this->pilot, self::AQUEOUS_LIQUIDS, 3, 5.5, 'now')],
            [self::AQUEOUS_LIQUIDS],
        );

        return (new PlanetaryPlannerProvider($security, $catalog, $userPrices, self::DEFAULT_HUB_ID, self::DEFAULT_HUB_NAME))->provide(new Get());
    }
}

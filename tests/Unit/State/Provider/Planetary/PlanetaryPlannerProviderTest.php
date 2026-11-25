<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryPlannerResource;
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
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Support\StubsPlanetaryTypes;
use App\State\Provider\Planetary\PlanetaryPlannerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryPlannerProvider::class)]
#[Group('issue-1402')]
final class PlanetaryPlannerProviderTest extends TestCase
{
    use StubsPlanetaryTypes;

    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int WATER = 91_011;

    public function testTheResourceCarriesTheRecipesTheTypesAndThePricesOfTheCatalogUnderTheFixedIdentifier(): void
    {
        $resource = $this->provide(new User());

        $this->assertSame('planner', $resource->id);
        $this->assertSame([
            ['outputTypeId' => self::WATER, 'outputQuantity' => 20, 'cycleSeconds' => 1800, 'inputs' => [['typeId' => self::AQUEOUS_LIQUIDS, 'quantity' => 3000]]],
        ], $resource->recipes);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'name' => 'Aqueous Liquids', 'tier' => 'P0', 'volume' => 0.01],
            ['typeId' => self::WATER, 'name' => 'Water', 'tier' => 'P1', 'volume' => null],
        ], $resource->types);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'sell' => 6.0, 'buy' => null],
            ['typeId' => self::WATER, 'sell' => 60.0, 'buy' => 50.0],
        ], $resource->prices);
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

    private function provide(?User $user, array $planetaryTypeTable = ['types' => [], 'attributes' => []]): PlanetaryPlannerResource
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
            new PlanetaryProductionCalculator($schematicRepository, $schematicTypeRepository, $invTypeRepository, $this->createStub(TypeNameResolver::class), $jitaMarketService),
            $jitaMarketService,
            new PlanetaryPlanetTypeCatalog($planetInvTypeRepository, $planetAttributeRepository),
        );

        return (new PlanetaryPlannerProvider($security, $catalog))->provide(new Get());
    }
}

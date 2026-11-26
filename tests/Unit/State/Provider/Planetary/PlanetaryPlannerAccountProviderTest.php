<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryPlannerAccountResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryPlannerAccountReader;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\State\Provider\Planetary\PlanetaryPlannerAccountProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryPlannerAccountProvider::class)]
#[Group('issue-1403')]
final class PlanetaryPlannerAccountProviderTest extends TestCase
{
    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int ALMA_EVE_ID = 90_000_101;
    private const int BOB_EVE_ID = 90_000_201;
    private const int SPECIALIZED_SCHEMATIC = 92_501;
    private const int SPECIALIZED_OUTPUT = 92_601;

    private User $almaUser;
    private User $bobUser;

    protected function setUp(): void
    {
        $this->almaUser = (new User())->addCharacter((new Character())->setEveCharacterId(self::ALMA_EVE_ID)->setName('Alma Voss'));
        $this->bobUser = (new User())->addCharacter((new Character())->setEveCharacterId(self::BOB_EVE_ID)->setName('Bob Kess'));
    }

    public function testTheResourceCarriesTheAccountOfTheConnectedUserUnderTheFixedIdentifier(): void
    {
        $resource = $this->provide($this->almaUser);

        $this->assertSame('account', $resource->id);
        $this->assertSame(1, $resource->usedPlanets);
        $this->assertSame([
            ['characterId' => self::ALMA_EVE_ID, 'characterName' => 'Alma Voss', 'usedPlanets' => 1, 'maxPlanets' => 3],
        ], $resource->characters);
        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 8_800.0, 'planets' => 1],
        ], $resource->extractionYields);
    }

    #[Group('issue-1406')]
    public function testTheResourceCarriesTheFactoriesOfTheConnectedUserByTierAndNothingOfAnotherUser(): void
    {
        $this->assertSame(['P1' => 0, 'P2' => 0, 'P3' => 2, 'P4' => 0], $this->provide($this->almaUser)->factories);
        $this->assertSame(['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0], $this->provide($this->bobUser)->factories);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null);
    }

    private function provide(?User $user): PlanetaryPlannerAccountResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $almaColonies = [$this->colonyWithExtractor($this->almaUser, 4400, 1800)];
        foreach ([2, 3] as $pinId) {
            $almaColonies[0]->addPin((new PlanetaryPin())->setPinId($pinId)->setTypeId(2473)->setSchematicId(self::SPECIALIZED_SCHEMATIC));
        }
        $bobColonies = [$this->colonyWithExtractor($this->bobUser, 1000, 900), $this->colonyWithExtractor($this->bobUser, 1000, 900)];
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturnCallback(
            fn (User $requested): array => $requested === $this->almaUser ? $almaColonies : $bobColonies,
        );
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturnCallback(
            static fn (int $eveCharacterId): array => $eveCharacterId === self::ALMA_EVE_ID ? [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 2] : [],
        );

        $extractorYield = $this->createStub(ExtractorYieldCalculator::class);
        $extractorYield->method('programDailyAverage')->willReturnCallback(
            static fn (int $quantityPerCycle, int $cycleTime): float => $quantityPerCycle * 86400 / $cycleTime,
        );

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            self::SPECIALIZED_SCHEMATIC => ['inputs' => [92_602 => 10], 'output' => ['typeId' => self::SPECIALIZED_OUTPUT, 'quantity' => 3]],
        ]);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnCallback(
            static fn (int $typeId): ?string => $typeId === self::SPECIALIZED_OUTPUT ? 'P3' : null,
        );

        return (new PlanetaryPlannerAccountProvider($security, new PlanetaryPlannerAccountReader($colonyRepository, $skillRepository, $extractorYield, $schematicTypeRepository, $productionCalculator)))->provide(new Get());
    }

    private function colonyWithExtractor(User $user, int $quantityPerCycle, int $cycleSeconds): PlanetaryColony
    {
        return (new PlanetaryColony())
            ->setCharacter($user->getCharacters()->first())
            ->setPlanetId(40_000_000)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538)
            ->addPin((new PlanetaryPin())
                ->setPinId(1)
                ->setTypeId(2848)
                ->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS)
                ->setExtractorQtyPerCycle($quantityPerCycle)
                ->setExtractorCycleTime($cycleSeconds));
    }
}

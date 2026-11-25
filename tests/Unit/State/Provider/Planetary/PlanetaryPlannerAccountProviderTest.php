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
use App\Service\Planetary\PlanetaryPlannerAccountReader;
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
        $bobColonies = [$this->colonyWithExtractor($this->bobUser, 1000, 900), $this->colonyWithExtractor($this->bobUser, 1000, 900)];
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturnCallback(
            fn (User $requested): array => $requested === $this->almaUser ? $almaColonies : $bobColonies,
        );
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturnCallback(
            static fn (int $eveCharacterId): array => $eveCharacterId === self::ALMA_EVE_ID ? [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 2] : [],
        );

        return (new PlanetaryPlannerAccountProvider($security, new PlanetaryPlannerAccountReader($colonyRepository, $skillRepository)))->provide(new Get());
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

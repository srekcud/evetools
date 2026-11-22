<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\CorporationStructureResource;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\State\Provider\Industry\CorporationStructureProvider;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #36: which corporation structures are offered is decided by
 * IndustryStructureConfigRepository::findCorporationSharedStructures (covered by
 * tests/Integration/Repository/IndustryStructureConfigRepositoryTest.php).
 * The provider only maps what the repository returns and skips locations the user
 * already configured.
 */
#[CoversClass(CorporationStructureProvider::class)]
class CorporationStructureProviderTest extends TestCase
{
    use StubsSdeStructureBonuses;

    private const int CORPORATION_ID = 98000001;
    private const int VISIBLE_LOCATION_ID = 1035466617947;

    private Security&Stub $security;
    private IndustryStructureConfigRepository&Stub $structureConfigRepository;
    private CorporationStructureProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);

        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureIds')->willReturn([]);

        $this->provider = new CorporationStructureProvider(
            $this->security,
            $this->structureConfigRepository,
            $cachedStructureRepository,
            $this->createStub(MapSolarSystemRepository::class),
            $this->sdeStructureBonusSummary(),
        );
    }

    public function testStructureDeletedByAnotherMemberIsStillListedForCurrentUser(): void
    {
        $currentUser = $this->createUser();
        $this->security->method('getUser')->willReturn($currentUser);
        $corpMate = $this->createUser();

        $structureHiddenByCorpMate = $this->createCorporationStructure($corpMate, self::VISIBLE_LOCATION_ID, 'Corp Azbel');
        // What DeleteStructureProcessor leaves behind when the owner deletes a corporation structure
        $structureHiddenByCorpMate->hideForUser((string) $corpMate->getId()?->toRfc4122());
        $structureHiddenByCorpMate->setIsDeleted(true);

        $this->structureConfigRepository->method('findCorporationSharedStructures')
            ->willReturn([self::VISIBLE_LOCATION_ID => $structureHiddenByCorpMate]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([self::VISIBLE_LOCATION_ID], $this->locationIdsOf($result->structures));
        $this->assertSame('Corp Azbel', $result->structures[0]->locationName);
    }

    public function testCorporationStructureAlreadyConfiguredByCurrentUserIsNotSuggestedAgain(): void
    {
        $currentUser = $this->createUser();
        $this->security->method('getUser')->willReturn($currentUser);
        $corpMate = $this->createUser();

        $sharedStructure = $this->createCorporationStructure($corpMate, self::VISIBLE_LOCATION_ID, 'Corp Azbel');
        $ownStructure = $this->createCorporationStructure($currentUser, self::VISIBLE_LOCATION_ID, 'My Azbel');

        $this->structureConfigRepository->method('findCorporationSharedStructures')
            ->willReturn([self::VISIBLE_LOCATION_ID => $sharedStructure]);
        $this->structureConfigRepository->method('findByUser')->willReturn([$ownStructure]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $this->locationIdsOf($result->structures));
    }

    public function testSharedConfigCarriesTheFacilityTaxEnteredByAnotherMember(): void
    {
        $sharedConfig = $this->sharedConfigOfStructureTaxedAt(4.0);

        $this->assertArrayHasKey('facilityTaxRate', $sharedConfig);
        $this->assertSame(4.0, $sharedConfig['facilityTaxRate']);
    }

    public function testSharedConfigOfAStructureWithoutFacilityTaxHasNoFacilityTax(): void
    {
        $sharedConfig = $this->sharedConfigOfStructureTaxedAt(null);

        $this->assertArrayHasKey('facilityTaxRate', $sharedConfig);
        $this->assertNull($sharedConfig['facilityTaxRate']);
    }

    #[Group('issue-1331')]
    public function testSharedConfigCarriesTheBonusesOfTheSdeAndTheRigsMissingFromIt(): void
    {
        $sharedStructure = $this->createCorporationStructure($this->createUser(), self::VISIBLE_LOCATION_ID, 'Corp Tatara');
        $sharedStructure->setStructureType('tatara');
        $sharedStructure->setRigs([self::SDE_COMPOSITE_REACTOR_ME_II, self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II, self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]);

        $sharedConfig = $this->sharedConfigOf($sharedStructure);

        $this->assertSame(0.0, $sharedConfig['manufacturingMaterialBonus']);
        $this->assertSame(2.64, $sharedConfig['reactionMaterialBonus']);
        $this->assertSame(0.0, $sharedConfig['manufacturingTimeBonus']);
        $this->assertSame(25.0, $sharedConfig['reactionTimeBonus']);
        $this->assertSame([self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II], $sharedConfig['unrecognisedRigs']);
    }

    #[Group('issue-1331')]
    public function testSharedConfigOfAStructureWithoutUnrecognisedRigListsNone(): void
    {
        $sharedConfig = $this->sharedConfigOfStructureTaxedAt(null);

        $this->assertArrayHasKey('unrecognisedRigs', $sharedConfig);
        $this->assertSame([], $sharedConfig['unrecognisedRigs']);
    }

    #[Group('issue-1343')]
    public function testSharedConfigOfALegacyTypeIsAHullToSpecifyWithUnknownTimeBonuses(): void
    {
        $sharedStructure = $this->createCorporationStructure($this->createUser(), self::VISIBLE_LOCATION_ID, 'Corp Complex');
        $sharedStructure->setRigs([self::SDE_AMMUNITION_ME_II]);

        $sharedConfig = $this->sharedConfigOf($sharedStructure);

        $this->assertArrayHasKey('hullToSpecify', $sharedConfig);
        $this->assertTrue($sharedConfig['hullToSpecify']);
        $this->assertArrayHasKey('manufacturingTimeBonus', $sharedConfig);
        $this->assertNull($sharedConfig['manufacturingTimeBonus']);
        $this->assertArrayHasKey('reactionTimeBonus', $sharedConfig);
        $this->assertNull($sharedConfig['reactionTimeBonus']);
        $this->assertSame(5.04, $sharedConfig['manufacturingMaterialBonus']);
    }

    #[Group('issue-1343')]
    public function testSharedConfigOfAKnownHullIsNotAHullToSpecifyAndKeepsNumericTimeBonuses(): void
    {
        $sharedStructure = $this->createCorporationStructure($this->createUser(), self::VISIBLE_LOCATION_ID, 'Corp Tatara');
        $sharedStructure->setStructureType('tatara');
        $sharedStructure->setRigs([]);

        $sharedConfig = $this->sharedConfigOf($sharedStructure);

        $this->assertArrayHasKey('hullToSpecify', $sharedConfig);
        $this->assertFalse($sharedConfig['hullToSpecify']);
        $this->assertSame(0.0, $sharedConfig['manufacturingTimeBonus']);
        $this->assertSame(25.0, $sharedConfig['reactionTimeBonus']);
    }

    private function sharedConfigOfStructureTaxedAt(?float $facilityTaxPercent): array
    {
        $sharedStructure = $this->createCorporationStructure($this->createUser(), self::VISIBLE_LOCATION_ID, 'Corp Raitaru');
        $sharedStructure->setFacilityTaxPercent($facilityTaxPercent);

        return $this->sharedConfigOf($sharedStructure);
    }

    private function sharedConfigOf(IndustryStructureConfig $sharedStructure): array
    {
        $currentUser = $this->createUser();
        $this->security->method('getUser')->willReturn($currentUser);

        $this->structureConfigRepository->method('findCorporationSharedStructures')
            ->willReturn([self::VISIBLE_LOCATION_ID => $sharedStructure]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertCount(1, $result->structures);

        $sharedConfig = $result->structures[0]->sharedConfig;
        $this->assertIsArray($sharedConfig);

        return $sharedConfig;
    }

    private function createUser(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v7());
        $user->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $user;
    }

    private function createCorporationStructure(User $owner, int $locationId, string $name): IndustryStructureConfig
    {
        $structure = new IndustryStructureConfig();
        $structure->setUser($owner);
        $structure->setName($name);
        $structure->setLocationId($locationId);
        $structure->setCorporationId(self::CORPORATION_ID);
        $structure->setIsCorporationStructure(true);
        $structure->setSecurityType('nullsec');
        $structure->setStructureType('engineering_complex');
        $structure->setRigs([]);

        return $structure;
    }

    /**
     * @param CorporationStructureResource[] $structures
     * @return int[]
     */
    private function locationIdsOf(array $structures): array
    {
        return array_values(array_map(fn (CorporationStructureResource $s) => $s->locationId, $structures));
    }
}

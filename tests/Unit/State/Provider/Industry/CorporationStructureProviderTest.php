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
use PHPUnit\Framework\Attributes\CoversClass;
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

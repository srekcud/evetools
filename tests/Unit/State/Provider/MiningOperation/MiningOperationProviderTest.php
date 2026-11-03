<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Provider\MiningOperation\MiningOperationProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningOperationProvider::class)]
#[Group('issue-262')]
final class MiningOperationProviderTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private MiningOperation $operation;
    private MiningOperationProvider $provider;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperation();
        $this->security = $this->createStub(Security::class);
        $this->provider = new MiningOperationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
        );
    }

    public function testOwnerReadsHerOperationWithItsJoinCode(): void
    {
        $this->actAs($this->aria);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame($this->operation->getId()->toRfc4122(), $resource->id);
        self::assertSame('owner', $resource->myRole);
        self::assertSame('accepted', $resource->myStatus);
        self::assertSame(self::JOIN_CODE, $resource->joinCode);
    }

    public function testAcceptedMemberReadsTheJoinCodeAndCountsOnlyAcceptedParticipantsAndTheirCharacters(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->actAs($this->brann);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame(self::JOIN_CODE, $resource->joinCode);
        self::assertSame(2, $resource->participantsCount);
        self::assertSame(4, $resource->charactersCount);
    }

    #[Group('issue-264')]
    public function testNewOperationIsValuedOnCompressedJitaBuyWithoutAnyCut(): void
    {
        $this->actAs($this->aria);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame(
            [
                'valuationSettings' => [
                    'valuationForm' => 'compressed',
                    'valuationMarket' => 'jita',
                    'valuationSide' => 'buy',
                    'structureId' => null,
                    'structureName' => null,
                    'reprocessYield' => null,
                    'boosterCutPercent' => 0,
                    'corporationCutPercent' => 0,
                    'boosterMemberId' => null,
                    'boosterCharacterName' => null,
                    'boostShipTypeId' => null,
                    'mountedCompressorKinds' => [],
                ],
                'sharesFrozenAt' => null,
            ],
            ['valuationSettings' => $resource->valuationSettings, 'sharesFrozenAt' => $resource->sharesFrozenAt],
        );
    }

    #[Group('issue-264')]
    public function testOperationCarriesItsBoosterAndCorporationCuts(): void
    {
        $brannMembership = $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, corporationCutPercent: 5, boosterMember: $brannMembership);
        $this->actAs($this->brann);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame(
            ['sell', 10, 5, $brannMembership->getId()->toRfc4122(), 'Brann Holt'],
            [
                $resource->valuationSettings['valuationSide'] ?? null,
                $resource->valuationSettings['boosterCutPercent'] ?? null,
                $resource->valuationSettings['corporationCutPercent'] ?? null,
                $resource->valuationSettings['boosterMemberId'] ?? null,
                $resource->valuationSettings['boosterCharacterName'] ?? null,
            ],
        );
    }

    public function testPendingMemberOfAnotherCorporationCannotRead(): void
    {
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->actAs($this->cyra);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->read($this->operation->getId()->toRfc4122());
    }

    public function testCorporationMemberWithoutMembershipReadsTheOperationWithoutItsJoinCode(): void
    {
        $this->actAs($this->elia);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame(self::SPODUMAIN_LABEL, $resource->label);
        self::assertNull($resource->myRole);
        self::assertNull($resource->myStatus);
        self::assertNull($resource->joinCode);
    }

    public function testPendingCorporationMemberReadsTheOperationWithoutItsJoinCode(): void
    {
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $this->actAs($this->elia);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame('pending', $resource->myStatus);
        self::assertNull($resource->joinCode);
    }

    public function testRemovedCorporationMemberReadsTheOperationWithoutMembership(): void
    {
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->actAs($this->elia);

        $resource = $this->read($this->operation->getId()->toRfc4122());

        self::assertSame(self::SPODUMAIN_LABEL, $resource->label);
        self::assertNull($resource->myStatus);
        self::assertNull($resource->joinCode);
    }

    public function testUnknownOperationIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Mining operation not found');

        $this->read(Uuid::v4()->toRfc4122());
    }

    public function testOperationIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Mining operation not found');

        $this->read(self::NOT_A_UUID);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function read(string $id): MiningOperationResource
    {
        return $this->provider->provide(new Get(), ['id' => $id]);
    }
}

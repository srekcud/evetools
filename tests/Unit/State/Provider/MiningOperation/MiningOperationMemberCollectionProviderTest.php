<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Provider\MiningOperation\MiningOperationMemberCollectionProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningOperationMemberCollectionProvider::class)]
#[Group('issue-262')]
final class MiningOperationMemberCollectionProviderTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private MiningOperation $operation;
    private MiningOperationMemberCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperation();
        $this->joinedAt($this->membershipOf($this->operation, $this->aria), '2026-11-03T18:00:00+00:00');
        $cyraMembership = $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->joinedAt($cyraMembership, '2026-11-04T09:30:00+00:00');
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $brannMembership = $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann II', 'Brann Holt']);
        $this->joinedAt($brannMembership, '2026-11-04T08:15:00+00:00');
        $this->security = $this->createStub(Security::class);
        $this->provider = new MiningOperationMemberCollectionProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(),
        );
    }

    public function testOwnerListsAcceptedAndPendingMembersOwnerFirstThenByJoiningTimeWithTheirCharactersByName(): void
    {
        $this->actAs($this->aria);

        self::assertSame(
            [
                ['Aria Solberg', 98262101, 'owner', 'accepted', ['Aria II', 'Aria Solberg']],
                ['Brann Holt', 98262101, 'member', 'accepted', ['Brann Holt', 'Brann II']],
                ['Cyra Lune', 98262102, 'member', 'pending', ['Cyra Lune']],
            ],
            array_map(static fn (MiningOperationMemberResource $member): array => [
                $member->characterName,
                $member->corporationId,
                $member->role,
                $member->status,
                array_map(static fn (array $character): string => $character['name'], $member->characters),
            ], $this->members()),
        );
    }

    #[Group('issue-263')]
    public function testParticipantWhoLeftEarlyCarriesItsDepartureTime(): void
    {
        $this->operation->start(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'), null);
        $this->membershipOf($this->operation, $this->brann)->depart(new \DateTimeImmutable('2026-11-05T21:05:00+02:00'));
        $this->actAs($this->aria);

        self::assertSame(
            ['Aria Solberg' => null, 'Brann Holt' => '2026-11-05T19:05:00+00:00', 'Cyra Lune' => null],
            array_combine(
                array_map(static fn (MiningOperationMemberResource $member): ?string => $member->characterName, $this->members()),
                array_map(static fn (MiningOperationMemberResource $member): ?string => $member->departedAt?->format(\DateTimeInterface::ATOM), $this->members()),
            ),
        );
    }

    public function testEachEnrolledCharacterCarriesItsIdentifierAndEveCharacterId(): void
    {
        $this->actAs($this->aria);

        $cyra = $this->members()[2] ?? null;

        self::assertNotNull($cyra);
        self::assertSame(
            [['id' => $this->idOf('Cyra Lune'), 'eveCharacterId' => $this->character('Cyra Lune')->getEveCharacterId(), 'name' => 'Cyra Lune']],
            $cyra->characters,
        );
    }

    public function testCorporationMemberWithoutMembershipCannotListTheMembers(): void
    {
        $this->actAs($this->pilot('Fenn Ostrova', self::C1, []));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->members();
    }

    public function testRemovedCorporationMemberCannotListTheMembers(): void
    {
        $this->actAs($this->elia);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->members();
    }

    public function testPendingMemberCannotListTheMembers(): void
    {
        $this->actAs($this->cyra);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->members();
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function members(): array
    {
        return $this->provider->provide(new GetCollection(), ['operationId' => $this->operation->getId()->toRfc4122()]);
    }

    private function joinedAt(?MiningOperationMember $member, string $joinedAt): void
    {
        (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable($joinedAt));
    }
}

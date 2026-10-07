<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Escalation;

use App\ApiResource\Escalation\EscalationResource;
use App\Entity\Escalation;
use App\Entity\User;
use App\Enum\EscalationBmStatus;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Repository\EscalationRepository;
use App\State\Provider\Escalation\EscalationCorpProvider;
use App\State\Provider\Escalation\EscalationDeleteProvider;
use App\State\Provider\Escalation\EscalationProvider;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(EscalationProvider::class)]
#[CoversClass(EscalationDeleteProvider::class)]
#[CoversClass(EscalationCorpProvider::class)]
class EscalationProviderTest extends TestCase
{
    // ===========================================
    // EscalationProvider — an escalation is only visible to the audience of its visibility
    // ===========================================

    private const int READER_CORPORATION_ID = 98000001;
    private const int OTHER_CORPORATION_ID = 98000002;
    private const int READER_ALLIANCE_ID = 99000001;
    private const int OTHER_ALLIANCE_ID = 99000002;

    public function testOwnerSeesHisPersoEscalation(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Perso);

        $result = $this->provideAs($owner, $escalation);

        $this->assertTrue($result->isOwner);
        $this->assertSame($this->idOf($escalation), $result->id);
    }

    public function testOwnerSeesHisCorpEscalationEvenAfterLeavingThatCorporation(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp, self::READER_CORPORATION_ID);

        $result = $this->provideAs($owner, $escalation);

        $this->assertTrue($result->isOwner);
        $this->assertSame($this->idOf($escalation), $result->id);
    }

    public function testPersoEscalationIsNotFoundForCorporationMate(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $corporationMate = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Perso, self::READER_CORPORATION_ID);

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($corporationMate, $escalation);
    }

    public function testCorpEscalationIsVisibleToMemberOfSameCorporation(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $corporationMate = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp, self::READER_CORPORATION_ID);

        $result = $this->provideAs($corporationMate, $escalation);

        $this->assertFalse($result->isOwner);
        $this->assertSame($this->idOf($escalation), $result->id);
    }

    public function testCorpEscalationIsNotFoundForMemberOfAnotherCorporation(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $outsider = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp, self::READER_CORPORATION_ID);

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($outsider, $escalation);
    }

    public function testCorpEscalationIsNotFoundForMemberOfAnotherCorporationInSameAlliance(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $allianceMate = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $escalation = $this->createEscalation(
            $owner,
            EscalationVisibility::Corp,
            self::READER_CORPORATION_ID,
            self::READER_ALLIANCE_ID,
        );

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($allianceMate, $escalation);
    }

    public function testCorpEscalationIsNotFoundForUserWithoutMainCharacter(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $userWithoutMainCharacter = $this->createUserStubWithCorpAndAlliance(null, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp, self::READER_CORPORATION_ID);

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($userWithoutMainCharacter, $escalation);
    }

    public function testAllianceEscalationIsVisibleToMemberOfAnotherCorporationInSameAlliance(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $allianceMate = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $escalation = $this->createEscalation(
            $owner,
            EscalationVisibility::Alliance,
            self::READER_CORPORATION_ID,
            self::READER_ALLIANCE_ID,
        );

        $result = $this->provideAs($allianceMate, $escalation);

        $this->assertFalse($result->isOwner);
        $this->assertSame($this->idOf($escalation), $result->id);
    }

    public function testAllianceEscalationIsNotFoundForMemberOfAnotherAlliance(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $outsider = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, self::OTHER_ALLIANCE_ID);
        $escalation = $this->createEscalation(
            $owner,
            EscalationVisibility::Alliance,
            self::READER_CORPORATION_ID,
            self::READER_ALLIANCE_ID,
        );

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($outsider, $escalation);
    }

    public function testAllianceEscalationWithoutAllianceIsNotFoundForUnalliedCorporationOutsider(): void
    {
        // Ni l'escalation ni le lecteur n'ont d'alliance : null ne doit pas valoir "même alliance".
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, null);
        $outsider = $this->createUserStubWithCorpAndAlliance(self::OTHER_CORPORATION_ID, null);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Alliance, self::READER_CORPORATION_ID, null);

        $this->expectSameNotFoundAsUnknownEscalation();
        $this->provideAs($outsider, $escalation);
    }

    public function testPublicEscalationIsVisibleToUserWithoutMainCharacter(): void
    {
        $owner = $this->createUserStubWithCorpAndAlliance(self::READER_CORPORATION_ID, self::READER_ALLIANCE_ID);
        $userWithoutMainCharacter = $this->createUserStubWithCorpAndAlliance(null, null);
        $escalation = $this->createEscalation(
            $owner,
            EscalationVisibility::Public,
            self::READER_CORPORATION_ID,
            self::READER_ALLIANCE_ID,
        );

        $result = $this->provideAs($userWithoutMainCharacter, $escalation);

        $this->assertFalse($result->isOwner);
        $this->assertSame($this->idOf($escalation), $result->id);
    }

    // ===========================================
    // EscalationProvider — not found / unauthorized
    // ===========================================

    public function testProviderThrowsNotFoundWhenEscalationDoesNotExist(): void
    {
        $user = $this->createUserStub(Uuid::v4());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);
        $repository->method('find')->willReturn(null);

        $provider = new EscalationProvider($security, $repository);

        $this->expectSameNotFoundAsUnknownEscalation();
        $provider->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    public function testProviderThrowsUnauthorizedWhenNoUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $repository = $this->createStub(EscalationRepository::class);

        $provider = new EscalationProvider($security, $repository);

        $this->expectException(UnauthorizedHttpException::class);
        $provider->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    // ===========================================
    // EscalationDeleteProvider — only owner can delete
    // ===========================================

    public function testDeleteProviderAllowsOwner(): void
    {
        $userId = Uuid::v4();
        $user = $this->createUserStub($userId);
        $escalation = $this->createEscalation($user, EscalationVisibility::Corp);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationDeleteProvider($security, $repository);
        $result = $provider->provide(new Delete(), ['id' => $escalationId->toRfc4122()]);

        $this->assertTrue($result->isOwner);
    }

    public function testDeleteProviderDeniesNonOwner(): void
    {
        $ownerId = Uuid::v4();
        $owner = $this->createUserStub($ownerId);
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp);

        $otherUserId = Uuid::v4();
        $otherUser = $this->createUserStub($otherUserId);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationDeleteProvider($security, $repository);

        $this->expectException(AccessDeniedHttpException::class);
        $provider->provide(new Delete(), ['id' => $escalationId->toRfc4122()]);
    }

    // ===========================================
    // EscalationCorpProvider — corp/alliance visibility
    // ===========================================

    public function testCorpProviderReturnsEmptyWhenNoCorporation(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getCorporationId')->willReturn(null);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);

        $provider = new EscalationCorpProvider($security, $repository);
        $result = $provider->provide(new GetCollection());

        $this->assertSame([], $result);
    }

    public function testCorpProviderReturnsCorporationEscalations(): void
    {
        $user = $this->createUserStubWithCorpAndAlliance(98000001, null);

        $owner = $this->createUserStub(Uuid::v4());
        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp, 98000001);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);
        $repository->method('findByCorporation')->willReturn([$escalation]);

        $provider = new EscalationCorpProvider($security, $repository);
        $result = $provider->provide(new GetCollection());

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]->isOwner);
    }

    public function testCorpProviderMergesAllianceEscalations(): void
    {
        $user = $this->createUserStubWithCorpAndAlliance(98000001, 99000001);

        $owner = $this->createUserStub(Uuid::v4());
        $corpEscalation = $this->createEscalation($owner, EscalationVisibility::Corp, 98000001);
        $allianceEscalation = $this->createEscalation($owner, EscalationVisibility::Alliance, 98000002, 99000001);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);
        $repository->method('findByCorporation')->willReturn([$corpEscalation]);
        $repository->method('findByAlliance')->willReturn([$allianceEscalation]);

        $provider = new EscalationCorpProvider($security, $repository);
        $result = $provider->provide(new GetCollection());

        $this->assertCount(2, $result);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function provideAs(User $reader, Escalation $escalation): EscalationResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($reader);

        $repository = $this->createStub(EscalationRepository::class);
        $repository->method('find')->willReturn($escalation);

        return (new EscalationProvider($security, $repository))
            ->provide(new Get(), ['id' => $this->idOf($escalation)]);
    }

    private function idOf(Escalation $escalation): string
    {
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);

        return $escalationId->toRfc4122();
    }

    /** Une escalation hors audience doit être indiscernable d'une escalation inexistante. */
    private function expectSameNotFoundAsUnknownEscalation(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Escalation not found');
    }

    private function createUserStub(Uuid $userId): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($userId);

        return $user;
    }

    private function createUserStubWithCorpAndAlliance(?int $corporationId, ?int $allianceId): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCorporationId')->willReturn($corporationId);
        $user->method('getAllianceId')->willReturn($allianceId);

        return $user;
    }

    private function createEscalation(
        User $owner,
        EscalationVisibility $visibility,
        int $corporationId = 98000001,
        ?int $allianceId = null,
    ): Escalation {
        $escalation = new Escalation();
        $escalation->setUser($owner);
        $escalation->setCharacterId(12345);
        $escalation->setCharacterName('TestChar');
        $escalation->setType('Crystal Quarry');
        $escalation->setSolarSystemId(30000142);
        $escalation->setSolarSystemName('Jita');
        $escalation->setSecurityStatus(0.9);
        $escalation->setPrice(100_000_000);
        $escalation->setVisibility($visibility);
        $escalation->setCorporationId($corporationId);
        $escalation->setAllianceId($allianceId);
        $escalation->setExpiresAt(new \DateTimeImmutable('+24 hours'));

        // Use reflection to set the ID so isOwnedBy works correctly
        $reflection = new \ReflectionClass($escalation);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($escalation, Uuid::v4());

        return $escalation;
    }
}

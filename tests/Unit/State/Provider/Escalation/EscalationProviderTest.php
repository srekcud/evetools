<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Escalation;

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
    // EscalationProvider — visibility access control
    // ===========================================

    public function testOwnerCanAccessPersoEscalation(): void
    {
        $userId = Uuid::v4();
        $user = $this->createUserStub($userId);
        $escalation = $this->createEscalation($user, EscalationVisibility::Perso);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationProvider($security, $repository);
        $result = $provider->provide(new Get(), ['id' => $escalationId->toRfc4122()]);

        $this->assertTrue($result->isOwner);
        $this->assertSame($escalationId->toRfc4122(), $result->id);
    }

    public function testNonOwnerCannotAccessPersoEscalation(): void
    {
        $ownerId = Uuid::v4();
        $owner = $this->createUserStub($ownerId);

        $otherUserId = Uuid::v4();
        $otherUser = $this->createUserStub($otherUserId);

        $escalation = $this->createEscalation($owner, EscalationVisibility::Perso);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationProvider($security, $repository);

        $this->expectException(AccessDeniedHttpException::class);
        $provider->provide(new Get(), ['id' => $escalationId->toRfc4122()]);
    }

    public function testNonOwnerCanAccessCorpEscalation(): void
    {
        $ownerId = Uuid::v4();
        $owner = $this->createUserStub($ownerId);

        $otherUserId = Uuid::v4();
        $otherUser = $this->createUserStub($otherUserId);

        $escalation = $this->createEscalation($owner, EscalationVisibility::Corp);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationProvider($security, $repository);
        $result = $provider->provide(new Get(), ['id' => $escalationId->toRfc4122()]);

        $this->assertFalse($result->isOwner);
    }

    public function testNonOwnerCanAccessAllianceEscalation(): void
    {
        $ownerId = Uuid::v4();
        $owner = $this->createUserStub($ownerId);

        $otherUserId = Uuid::v4();
        $otherUser = $this->createUserStub($otherUserId);

        $escalation = $this->createEscalation($owner, EscalationVisibility::Alliance);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationProvider($security, $repository);
        $result = $provider->provide(new Get(), ['id' => $escalationId->toRfc4122()]);

        $this->assertFalse($result->isOwner);
    }

    public function testNonOwnerCanAccessPublicEscalation(): void
    {
        $ownerId = Uuid::v4();
        $owner = $this->createUserStub($ownerId);

        $otherUserId = Uuid::v4();
        $otherUser = $this->createUserStub($otherUserId);

        $escalation = $this->createEscalation($owner, EscalationVisibility::Public);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repository = $this->createStub(EscalationRepository::class);
        $escalationId = $escalation->getId();
        $this->assertNotNull($escalationId);
        $repository->method('find')->willReturn($escalation);

        $provider = new EscalationProvider($security, $repository);
        $result = $provider->provide(new Get(), ['id' => $escalationId->toRfc4122()]);

        $this->assertFalse($result->isOwner);
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

        $this->expectException(NotFoundHttpException::class);
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

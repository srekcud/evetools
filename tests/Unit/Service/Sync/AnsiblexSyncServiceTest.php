<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\AnsiblexJumpGateRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AnsiblexSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(AnsiblexSyncService::class)]
final class AnsiblexSyncServiceTest extends TestCase
{
    private const STRUCTURES_SCOPE = 'esi-corporations.read_structures.v1';

    private AnsiblexSyncService $service;

    protected function setUp(): void
    {
        $this->service = new AnsiblexSyncService(
            $this->createStub(EsiClient::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnsiblexJumpGateRepository::class),
            $this->createStub(MapSolarSystemRepository::class),
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    public function testCanSyncRefusesARevokedMainCharacterEvenWithATokenAndTheStructuresScope(): void
    {
        $user = new User();
        $revokedMain = $this->characterOfWithToken($user, 2_113_000_001, 'Revoked Director', [self::STRUCTURES_SCOPE])
            ->markAuthInvalid();
        $user->setMainCharacter($revokedMain);

        self::assertFalse($this->service->canSync($revokedMain));
    }

    public function testCanSyncAcceptsAValidMainCharacterWhoseAltIsRevoked(): void
    {
        $user = new User();
        $validMain = $this->characterOfWithToken($user, 2_113_000_002, 'Valid Director', [self::STRUCTURES_SCOPE, 'esi-universe.read_structures.v1']);
        $user->setMainCharacter($validMain);
        $this->characterOfWithToken($user, 2_113_000_003, 'Revoked Alt', ['esi-search.search_structures.v1'])->markAuthInvalid();

        self::assertTrue($this->service->canSync($validMain));
    }

    /** @param list<string> $scopes */
    private function characterOfWithToken(User $user, int $eveCharacterId, string $name, array $scopes): Character
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $character->setEveToken((new EveToken())
            ->setAccessToken('access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes));
        $user->addCharacter($character);

        return $character;
    }
}

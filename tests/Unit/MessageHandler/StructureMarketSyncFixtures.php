<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Shared builders for the structure market sync handlers: the real StructureMarketService
 * is used, only ESI, the cache, the database and the Mercure hub are doubled.
 */
trait StructureMarketSyncFixtures
{
    private const string STRUCTURE_MARKET_SCOPE = 'esi-markets.structure_markets.v1';

    /** @var list<string> topics of every Mercure update published by the market service */
    private array $publishedTopics = [];

    private function createStructureMarketService(EsiClient $esiClient): StructureMarketService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedTopics = [...$this->publishedTopics, ...$update->getTopics()];

            return 'urn:uuid:' . Uuid::v4()->toRfc4122();
        });

        return new StructureMarketService(
            $esiClient,
            new ArrayAdapter(),
            new NullLogger(),
            new MercurePublisherService($hub, new NullLogger()),
            new StructureMarketSnapshotService($this->createStub(Connection::class), new NullLogger()),
        );
    }

    /**
     * A pilot of a user, with a valid token carrying the given scopes.
     *
     * @param list<string> $scopes
     */
    private function createPilot(string $name, array $scopes = [self::STRUCTURE_MARKET_SCOPE]): Character
    {
        $user = new User();
        $this->forceId($user, Uuid::v4());

        $character = (new Character())
            ->setEveCharacterId(random_int(90_000_000, 99_999_999))
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $this->forceId($character, Uuid::v4());
        $user->addCharacter($character);

        $token = (new EveToken())
            ->setAccessToken('access-' . $name)
            ->setRefreshTokenEncrypted('refresh-' . $name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);

        return $character;
    }

    private function userIdOf(Character $character): string
    {
        return $character->getUser()->getId()->toRfc4122();
    }

    private function marketStructureTopic(Character $character): string
    {
        return sprintf('/user/%s/sync/market-structure', $this->userIdOf($character));
    }

    private function forceId(object $entity, Uuid $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}

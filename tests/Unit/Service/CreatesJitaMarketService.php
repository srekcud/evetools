<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue #26: wires JitaMarketService on whatever constructor it currently has (its own
 * HttpClientInterface today, an EsiClient named "esiClient" after the switch), with ESI
 * simulated at the HTTP boundary. Behavior tests stay green before and after the switch.
 */
trait CreatesJitaMarketService
{
    private function createJitaMarketService(
        HttpClientInterface $esiHttpClient,
        string $esiBaseUrl,
        CacheItemPoolInterface $marketCache,
    ): JitaMarketService {
        $connection = $this->createStub(Connection::class);

        if (in_array(HttpClientInterface::class, $this->jitaMarketServiceConstructorParameterTypes(), true)) {
            return new JitaMarketService($esiHttpClient, $marketCache, $connection, new NullLogger());
        }

        $esiClient = new EsiClient(
            $esiHttpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(TokenManager::class),
            $esiBaseUrl,
            new NullLogger(),
        );

        return new JitaMarketService(
            esiClient: $esiClient,
            cache: $marketCache,
            connection: $connection,
            logger: new NullLogger(),
        );
    }

    /**
     * @return list<string>
     */
    private function jitaMarketServiceConstructorParameterTypes(): array
    {
        $constructor = (new \ReflectionClass(JitaMarketService::class))->getConstructor();
        $this->assertNotNull($constructor);

        return array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonChunk;

use App\Entity\Character;
use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Service\ESI\CharacterService;
use App\Service\JitaMarketService;
use App\Tests\Integration\MoonChunk\SeedsMoonChunkMarket;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

trait DrivesMoonChunkApi
{
    use ClockSensitiveTrait;
    use SeedsMoonChunkMarket;

    private const int ILYRA_CORPORATION_ID = 98_262_101;
    private const int CYRA_CORPORATION_ID = 98_262_102;
    private const int KNOWN_VALUE_MOON_ID = 40_301_001;
    private const int NO_PRICE_MOON_ID = 40_301_002;
    private const int NO_SCAN_MOON_ID = 40_301_003;
    private const int EXPLODED_MOON_ID = 40_301_004;
    private const int ZERO_PRICE_MOON_ID = 40_301_005;
    private const int CYRA_ONLY_MOON_ID = 40_302_001;
    private const int MOON_WITHOUT_EXTRACTION_ID = 40_999_999;
    private const string NOW = '2026-11-05T19:00:00+00:00';
    private const string COMPOSITION_RECORDED_AT = '2026-11-04T09:30:00+00:00';
    private const string NO_PRICE_COMPOSITION_RECORDED_AT = '2026-11-04T10:15:00+00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private ?string $actingUserId = null;
    private int $buyPriceCalls = 0;
    private ?CharacterService $characterServiceStub = null;
    private int $nextEveCharacterId = 91_000_000;

    private function seedMoonChunkWorld(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->jitaBuyPrices[self::COMPRESSED_ZEOLITES] = 0.0;
        $this->replaceCountingJitaMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedMoonOreSde($this->em);
        $this->aria = $this->persistPilotOf('Aria Solberg', self::ILYRA_CORPORATION_ID);
        $this->brann = $this->persistPilotOf('Brann Holt', self::CYRA_CORPORATION_ID);

        $this->persistExtraction(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, '2026-11-04T04:00:00+00:00', '2026-11-05T20:00:00+00:00', '2026-11-08T20:00:00+00:00');
        $this->persistExtraction(self::ILYRA_CORPORATION_ID, self::NO_PRICE_MOON_ID, '2026-11-03T20:00:00+00:00', '2026-11-06T20:00:00+00:00', '2026-11-09T20:00:00+00:00');
        $this->persistExtraction(self::ILYRA_CORPORATION_ID, self::NO_SCAN_MOON_ID, '2026-11-05T08:00:00+00:00', '2026-11-07T08:00:00+00:00', '2026-11-10T08:00:00+00:00');
        $this->persistExtraction(self::ILYRA_CORPORATION_ID, self::EXPLODED_MOON_ID, '2026-10-28T20:00:00+00:00', '2026-11-03T20:00:00+00:00', '2026-11-04T20:00:00+00:00');
        $this->persistExtraction(self::ILYRA_CORPORATION_ID, self::ZERO_PRICE_MOON_ID, '2026-11-03T17:00:00+00:00', '2026-11-05T17:00:00+00:00', '2026-11-05T20:00:00+00:00');
        $this->persistExtraction(self::CYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID, '2026-11-04T04:00:00+00:00', '2026-11-05T20:00:00+00:00', '2026-11-08T20:00:00+00:00');
        $this->persistExtraction(self::CYRA_CORPORATION_ID, self::CYRA_ONLY_MOON_ID, '2026-11-04T04:00:00+00:00', '2026-11-05T20:00:00+00:00', '2026-11-08T20:00:00+00:00');

        $this->persistComposition(self::KNOWN_VALUE_MOON_ID, self::COMPOSITION_RECORDED_AT, [self::BITUMENS => 0.5, self::CHROMITE => 0.5]);
        $this->persistComposition(self::NO_PRICE_MOON_ID, self::NO_PRICE_COMPOSITION_RECORDED_AT, [self::BITUMENS => 0.5, self::SYLVITE => 0.5]);
        $this->persistComposition(self::EXPLODED_MOON_ID, self::COMPOSITION_RECORDED_AT, [self::BITUMENS => 1.0]);
        $this->persistComposition(self::ZERO_PRICE_MOON_ID, self::COMPOSITION_RECORDED_AT, [self::BITUMENS => 0.5, self::ZEOLITES => 0.5]);
        $this->em->flush();
    }

    private function persistPilotOf(string $name, int $corporationId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        $user->addCharacter($character);
        $this->em->persist($character);

        return $user;
    }

    private function persistExtraction(int $corporationId, int $moonId, string $start, string $arrival, string $decay): void
    {
        $this->em->persist(
            (new CorporationMiningExtraction())
                ->setCorporationId($corporationId)
                ->setStructureId(1_035_000_000_000 + $corporationId % 1000)
                ->setStructureName('Athanor Ilyra')
                ->setMoonId($moonId)
                ->setMoonName(sprintf('Ilyra IV - Moon %d', $moonId % 10))
                ->setExtractionStartTime(new \DateTimeImmutable($start))
                ->setChunkArrivalTime(new \DateTimeImmutable($arrival))
                ->setNaturalDecayTime(new \DateTimeImmutable($decay)),
        );
    }

    private function persistComposition(int $moonId, string $recordedAt, array $sharesByTypeId): void
    {
        $this->em->persist(
            (new MoonComposition())
                ->setCorporationId(self::ILYRA_CORPORATION_ID)
                ->setMoonId($moonId)
                ->setRecordedBy($this->aria)
                ->setRecordedAt(new \DateTimeImmutable($recordedAt))
                ->setLines(array_map(static fn (int $typeId, float $share): array => ['typeId' => $typeId, 'share' => $share], array_keys($sharesByTypeId), $sharesByTypeId)),
        );
    }

    private function replaceCountingJitaMarket(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getBuyPrices')->willReturnCallback(function (array $typeIds): array {
            ++$this->buyPriceCalls;

            return array_combine($typeIds, array_map(fn (int $typeId): ?float => $this->jitaBuyPrices[$typeId] ?? null, $typeIds));
        });
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
        if ($this->characterServiceStub !== null) {
            self::getContainer()->set(CharacterService::class, $this->characterServiceStub);
        }
        self::getContainer()->set('logger', new NullLogger());
    }

    private function as(?User $user): void
    {
        $this->actingUserId = $user === null ? null : (string) $user->getId();
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        if ($this->actingUserId !== null) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->disableReboot();
            $this->replaceCountingJitaMarket();
            $this->em = self::getContainer()->get(EntityManagerInterface::class);
            $this->client->loginUser($this->em->find(User::class, $this->actingUserId), 'api');
        }
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = $content === '' ? [] : json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function storedCompositionCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moon_compositions');
    }
}

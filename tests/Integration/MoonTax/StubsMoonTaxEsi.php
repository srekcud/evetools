<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

trait StubsMoonTaxEsi
{
    protected const string MINING_SCOPE = 'esi-industry.read_corporation_mining.v1';
    protected const string WALLET_SCOPE = 'esi-wallet.read_corporation_wallets.v1';
    protected const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    protected const array ALL_SCOPES = [self::MINING_SCOPE, self::WALLET_SCOPE, self::ROLES_SCOPE];
    protected const string ACCESS_TOKEN_PREFIX = 'access-token-';

    protected array $esiResponses = [];

    protected array $esiFailures = [];

    protected array $esiCalls = [];

    protected array $publishedUpdates = [];

    protected function replaceEsi(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        foreach (['get', 'getPaginated', 'getWithCache'] as $method) {
            $esiClient->method($method)->willReturnCallback(fn (string $endpoint, ?EveToken $token = null): array => $this->answerEsi($endpoint, $token));
        }
        $esiClient->method('post')->willReturnCallback(fn (string $endpoint, array $body, ?EveToken $token = null): array => $this->answerEsi('POST ' . $endpoint, $token));
        self::getContainer()->set(EsiClient::class, $esiClient);
        self::getContainer()->set('logger', new NullLogger());
    }

    protected function replaceMercure(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    protected function answerEsi(string $endpoint, ?EveToken $token): array
    {
        $eveCharacterId = $token === null ? null : (int) substr($token->getAccessToken(), strlen(self::ACCESS_TOKEN_PREFIX));
        $this->esiCalls[] = [$endpoint, $eveCharacterId];

        $failures = $this->esiFailures[$endpoint] ?? [];
        $failure = $failures[$eveCharacterId ?? 'any'] ?? $failures['any'] ?? null;
        if ($failure !== null) {
            throw $failure;
        }
        if (!array_key_exists($endpoint, $this->esiResponses)) {
            throw EsiApiException::notFound('No ESI answer prepared for ' . $endpoint, $endpoint);
        }

        return $this->esiResponses[$endpoint];
    }

    protected function failEsi(string $endpoint, int $statusCode, ?int $eveCharacterId = null): void
    {
        $this->esiFailures[$endpoint][$eveCharacterId ?? 'any'] = EsiApiException::fromResponse($statusCode, 'ESI ' . $statusCode, $endpoint);
    }

    protected static function observersEndpoint(int $corporationId): string
    {
        return sprintf('/corporation/%d/mining/observers/', $corporationId);
    }

    protected static function observerLedgerEndpoint(int $corporationId, int $observerId): string
    {
        return sprintf('/corporation/%d/mining/observers/%d/', $corporationId, $observerId);
    }

    protected static function journalEndpoint(int $corporationId, int $division): string
    {
        return sprintf('/corporations/%d/wallets/%d/journal/', $corporationId, $division);
    }

    protected static function characterEndpoint(int $eveCharacterId): string
    {
        return sprintf('/characters/%d/', $eveCharacterId);
    }

    protected static function rolesEndpoint(int $eveCharacterId): string
    {
        return sprintf('/characters/%d/roles/', $eveCharacterId);
    }

    protected static function corporationEndpoint(int $corporationId): string
    {
        return sprintf('/corporations/%d/', $corporationId);
    }

    protected function respondWithObservers(int $corporationId, array $observerIds): void
    {
        $this->esiResponses[self::observersEndpoint($corporationId)] = array_map(
            static fn (int $observerId): array => ['observer_id' => $observerId, 'observer_type' => 'structure', 'last_updated' => '2026-11-09'],
            $observerIds,
        );
    }

    protected function respondWithLedger(int $corporationId, int $observerId, array $entries): void
    {
        $this->esiResponses[self::observerLedgerEndpoint($corporationId, $observerId)] = $entries;
    }

    protected function respondWithJournal(int $corporationId, int $division, array $entries): void
    {
        $this->esiResponses[self::journalEndpoint($corporationId, $division)] = $entries;
    }

    protected static function allianceEndpoint(int $allianceId): string
    {
        return sprintf('/alliances/%d/', $allianceId);
    }

    protected function respondWithCorporation(int $corporationId, ?int $allianceId, ?string $name = null): void
    {
        $this->esiResponses[self::corporationEndpoint($corporationId)] = ['name' => $name ?? 'Corporation ' . $corporationId] + ($allianceId === null ? [] : ['alliance_id' => $allianceId]);
    }

    protected function respondWithAlliance(int $allianceId, string $name): void
    {
        $this->esiResponses[self::allianceEndpoint($allianceId)] = ['name' => $name];
    }

    protected function respondWithCharacterNames(array $namesByCharacterId): void
    {
        $this->esiResponses['POST /universe/names/'] = array_map(
            static fn (int $characterId, string $name): array => ['id' => $characterId, 'name' => $name, 'category' => 'character'],
            array_keys($namesByCharacterId),
            array_values($namesByCharacterId),
        );
    }

    protected static function ledgerEntry(int $characterId, int $typeId, int $quantity, string $lastUpdated, int $recordedCorporationId): array
    {
        return ['character_id' => $characterId, 'last_updated' => $lastUpdated, 'quantity' => $quantity, 'recorded_corporation_id' => $recordedCorporationId, 'type_id' => $typeId];
    }

    protected static function journalDonation(int $journalId, int $firstPartyId, int $secondPartyId, float $amount, string $date, ?string $reason = null): array
    {
        return ['id' => $journalId, 'date' => $date, 'ref_type' => 'player_donation', 'amount' => $amount, 'first_party_id' => $firstPartyId, 'second_party_id' => $secondPartyId] + ($reason === null ? [] : ['reason' => $reason]);
    }

    protected function esiCallsTo(string $endpoint): array
    {
        return array_values(array_filter($this->esiCalls, static fn (array $call): bool => $call[0] === $endpoint));
    }

    protected function charactersReading(string $endpoint): array
    {
        return array_map(static fn (array $call): ?int => $call[1], $this->esiCallsTo($endpoint));
    }

    protected function publishedSyncEvents(): array
    {
        $events = [];
        foreach ($this->publishedUpdates as $update) {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            foreach ($update->getTopics() as $topic) {
                $events[] = [$topic, $payload['status']];
            }
        }
        sort($events);

        return $events;
    }
}

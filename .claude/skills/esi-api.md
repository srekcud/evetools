# EVE Online ESI API - Usage Guide

This skill covers how to interact with the EVE Online ESI (EVE Swagger Interface) API within this project. It documents the client architecture, authentication, common patterns, sync services, and Mercure integration.

---

## 1. Architecture Overview

```
EsiClient (HTTP layer)
    |
    +-- TokenManager (OAuth2 token refresh, encryption)
    |
    +-- ESI Service (PlanetaryService, AssetsService, CharacterService, etc.)
    |       |
    |       +-- Sync Service (PlanetarySyncService, AssetsSyncService, etc.)
    |               |
    |               +-- MercurePublisherService (real-time progress)
    |               +-- EntityManager (persistence)
    |
    +-- Message + MessageHandler (async via Symfony Messenger / RabbitMQ)
    |
    +-- SyncScheduler (periodic triggers)
```

**Key files:**
- `src/Service/ESI/EsiClient.php` - Base HTTP client (all ESI calls go through here)
- `src/Service/ESI/TokenManager.php` - OAuth2 token management
- `src/Service/ESI/*.php` - Domain-specific ESI services
- `src/Service/Sync/*.php` - Sync orchestration services
- `src/Message/*.php` - Messenger messages (async dispatch)
- `src/MessageHandler/*.php` - Messenger handlers (async processing)
- `src/Scheduler/SyncScheduler.php` - Cron-like schedule definitions
- `src/Exception/EsiApiException.php` - Custom exception for ESI errors

---

## 2. ESI Client (`EsiClient`)

### Configuration

Defined in `config/services.yaml`:
```yaml
App\Service\ESI\EsiClient:
    arguments:
        $esiCache: '@esi.cache'
        $baseUrl: '%esi_base_url%'  # https://esi.evetech.net/latest
```

### Available Methods

#### `get(string $endpoint, ?EveToken $token = null, array $extraHeaders = []): array`
Simple GET request. Returns decoded JSON array. Handles error responses and rate limiting automatically.

```php
// Public endpoint (no auth)
$data = $this->esiClient->get("/universe/stations/{$stationId}/");

// Authenticated endpoint
$data = $this->esiClient->get("/characters/{$characterId}/planets/", $token);
```

#### `getScalar(string $endpoint, ?EveToken $token = null, int $timeout = 30): mixed`
GET request that returns a scalar value (number, string) rather than an array. Uses `json_decode` without associative flag.

```php
$walletBalance = $this->esiClient->getScalar("/characters/{$characterId}/wallet/", $token);
```

#### `getScalarBatch(array $requests, int $timeout = 10): array`
Concurrent GET requests for scalar values. Non-blocking; all requests start in parallel then results are collected. Returns `null` for failed requests.

```php
$requests = [
    'char1' => ['endpoint' => "/characters/123/wallet/", 'token' => $token1],
    'char2' => ['endpoint' => "/characters/456/wallet/", 'token' => $token2],
];
$results = $this->esiClient->getScalarBatch($requests);
// $results = ['char1' => 1234567.89, 'char2' => null]
```

#### `getWithCache(string $endpoint, ?EveToken $token = null): array`
GET with Redis cache + ETag support. Uses `If-None-Match` header for conditional requests. On 304 Not Modified, returns cached data. Falls back to cache on network errors.

```php
// Good for public, slowly-changing data
$corpInfo = $this->esiClient->getWithCache("/corporations/{$corporationId}/");
$charInfo = $this->esiClient->getWithCache("/characters/{$characterId}/");
```

Cache key format: `esi_<md5(endpoint)>[_<characterUuid>]`
Cache TTL: derived from `Expires` header, defaults to 5 minutes.

#### `getPaginated(string $endpoint, ?EveToken $token = null): array`
Handles paginated ESI endpoints automatically. Reads `X-Pages` header, fetches all pages, and merges results into a single array. Throttles between pages.

```php
// Returns ALL assets across all pages
$rawAssets = $this->esiClient->getPaginated("/characters/{$characterId}/assets/", $token);
```

#### `post(string $endpoint, array $body, ?EveToken $token = null): array`
POST request with JSON body. Used for endpoints like `POST /universe/names/`.

```php
// Resolve type IDs to names (max 1000 IDs per request)
$names = $this->esiClient->post('/universe/names/', $typeIds);
```

### Rate Limiting (Built-in)

The client automatically handles ESI error budget via `x-esi-error-limit-remain` and `x-esi-error-limit-reset` headers:

| Remaining Errors | Action |
|---|---|
| < 5 | **Hard pause**: `sleep($resetSeconds)` |
| < 20 | **Soft throttle**: `usleep((20 - remain) * 100ms)` |
| >= 20 | No throttle |

**420 Error Limited**: Automatically retried once after sleeping for the reset period.

### Error Handling

All methods throw `EsiApiException` with:
- `$statusCode` - HTTP status code (0 for network errors)
- `$endpoint` - The ESI endpoint that failed
- `$message` - Human-readable error

Static factory methods available:
```php
EsiApiException::fromResponse(int $statusCode, string $message, ?string $endpoint)
EsiApiException::unauthorized(string $message)
EsiApiException::forbidden(string $message)
EsiApiException::notFound(string $message)
EsiApiException::rateLimited(string $message)
```

---

## 3. Authentication & Tokens

### OAuth2 Flow

1. **Authorization URL** (`AuthenticationService::getAuthorizationUrl`): redirects user to EVE SSO with all required scopes
2. **Code Exchange** (`AuthenticationService::exchangeCodeForToken`): exchanges authorization code for access + refresh tokens
3. **Token Verification** (`AuthenticationService::verifyToken`): validates access token and extracts character ID

EVE SSO endpoints:
- Authorize: `https://login.eveonline.com/v2/oauth/authorize`
- Token: `https://login.eveonline.com/v2/oauth/token`
- Verify: `https://login.eveonline.com/oauth/verify`

### Token Storage (`EveToken` Entity)

```php
EveToken {
    id: Uuid
    character: Character (OneToOne)
    accessToken: string (text)
    refreshTokenEncrypted: string (sodium secretbox, base64)
    accessTokenExpiresAt: DateTimeImmutable
    scopes: array<string> (JSON)
}
```

- Refresh tokens are encrypted with **libsodium** (`sodium_crypto_secretbox`)
- Scopes are stored as JSON array and checked with `$token->hasScope('scope-name')`

### Getting a Valid Token

The `TokenManager` handles token refresh transparently:

```php
// In EsiClient::buildHeaders() - called automatically:
$accessToken = $this->tokenManager->getValidAccessToken($token);
// This checks if token expires within 5 minutes and refreshes if needed
```

**You never need to manually refresh tokens.** Just pass the `EveToken` entity to `EsiClient` methods.

### Checking Scopes

Always check scope availability before calling authenticated endpoints:

```php
$token = $character->getEveToken();
if ($token === null || !$token->hasScope('esi-planets.manage_planets.v1')) {
    continue; // Skip this character
}

// Or check multiple scopes at once:
if (!$token->hasAllScopes(['esi-assets.read_assets.v1', 'esi-universe.read_structures.v1'])) {
    return [];
}
```

---

## 4. Creating a New ESI Service

### Naming Convention

`src/Service/ESI/{Domain}Service.php` - e.g., `PlanetaryService`, `AssetsService`, `CharacterService`

### Template

```php
<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;

class NewDomainService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /**
     * Fetch data requiring authentication.
     *
     * @return array<array<string, mixed>>
     */
    public function fetchSomething(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();

        return $this->esiClient->get("/characters/{$characterId}/something/", $token);
    }

    /**
     * Fetch paginated data.
     *
     * @return array<array<string, mixed>>
     */
    public function fetchPaginatedData(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();

        return $this->esiClient->getPaginated("/characters/{$characterId}/paginated-thing/", $token);
    }

    /**
     * Public endpoint (no auth needed).
     *
     * @return array<string, mixed>
     */
    public function fetchPublicInfo(int $entityId): array
    {
        return $this->esiClient->get("/universe/entities/{$entityId}/");
    }
}
```

### Key Patterns

1. **Always check for null token** before making authenticated calls
2. **Use `getPaginated()`** for endpoints that return paginated lists (assets, market orders, etc.)
3. **Use `getWithCache()`** for public, slowly-changing data (character info, corporation info, alliance info)
4. **Use `get()`** for authenticated data that changes frequently (PI colonies, industry jobs)
5. **Use `post()`** for `POST /universe/names/` and similar batch-resolve endpoints
6. **Keep ESI services focused**: they only call ESI and return raw/light DTOs. Business logic goes in Sync services.

---

## 5. Sync Service Pattern

Sync services orchestrate the full data pipeline: ESI fetch, data processing, database persistence, and Mercure notifications.

### Template

```php
<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Service\ESI\NewDomainService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NewDomainSyncService
{
    public function __construct(
        private readonly NewDomainService $domainService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncCharacterData(Character $character): int
    {
        $userId = $character->getUser()?->getId()?->toRfc4122();

        // 1. Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'new-domain', 'Fetching data...');
        }

        try {
            // 2. Fetch from ESI
            $data = $this->domainService->fetchSomething($character);

            // 3. Report progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'new-domain', 50, 'Processing...');
            }

            // 4. Process and persist
            // ... entity creation/update logic ...
            $this->entityManager->flush();

            // 5. Notify completion
            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted($userId, 'new-domain', 'Done', [
                    'total' => count($data),
                ]);
            }

            return count($data);
        } catch (\Throwable $e) {
            // 6. ALWAYS publish syncError (prevents frontend stuck in spinner)
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'new-domain', $e->getMessage());
            }
            throw $e;
        }
    }
}
```

### Mercure Integration (MANDATORY for new modules)

Every sync service **must** follow this lifecycle:

```
syncStarted(userId, syncType, message)      // progress = 0
    |
syncProgress(userId, syncType, %, message)  // progress = 20..90
    |
syncCompleted(userId, syncType, msg, data)  // progress = 100
    |
--- OR on failure ---
    |
syncError(userId, syncType, errorMessage)   // progress = null
```

**Critical**: Always wrap the main sync logic in a try/catch that calls `syncError`. If you forget this, the frontend will be stuck with a spinner forever.

**Registering the topic**: Add the new syncType to `MercurePublisherService::getTopicsForUser()`:
```php
public static function getTopicsForUser(string $userId): array
{
    return [
        // ... existing topics ...
        sprintf('/user/%s/sync/new-domain', $userId),  // ADD THIS
    ];
}
```

### Throttling Between Calls

When making multiple ESI calls in a loop (e.g., per-colony detail fetch), add delays:

```php
foreach ($items as $index => $item) {
    $detail = $this->esiService->fetchDetail($character, $item['id']);
    // ... process ...

    // Throttle between calls to avoid rate limits
    if ($index < count($items) - 1) {
        usleep(500_000); // 500ms
    }
}
```

---

## 6. Messenger (Async) Pattern

### Message Class

```php
// src/Message/SyncNewDomainData.php
<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncNewDomainData
{
}
```

Messages are simple value objects. Most are empty (no properties). For user-triggered syncs, the handler resolves the data from the database.

### Handler Class

```php
// src/MessageHandler/SyncNewDomainDataHandler.php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncNewDomainData;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\NewDomainSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncNewDomainDataHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private NewDomainSyncService $syncService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncNewDomainData $message): void
    {
        $this->syncTracker->start('new-domain');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $synced = 0;

            foreach ($characters as $character) {
                $token = $character->getEveToken();
                if ($token === null || !$token->hasScope('esi-required.scope.v1')) {
                    continue;
                }

                try {
                    $this->syncService->syncCharacterData($character);
                    $synced++;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to sync new domain data', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                    ]);
                }

                usleep(500_000); // Throttle between characters
            }

            $this->syncTracker->complete('new-domain', "{$synced}/" . count($characters) . ' chars synced');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('new-domain', $e->getMessage());
            throw $e;
        }
    }
}
```

### Trigger Pattern (Scheduler -> Trigger -> Actual Sync)

For scheduled syncs, use a two-level message pattern:

1. `TriggerNewDomainSync` - dispatched by scheduler, handler dispatches the actual sync message
2. `SyncNewDomainData` - handler loops through characters and calls the sync service

```php
// Scheduler dispatches:
RecurringMessage::every('30 minutes', new TriggerNewDomainSync())

// TriggerNewDomainSyncHandler dispatches:
$this->messageBus->dispatch(new SyncNewDomainData());
```

### API Platform Processor (User-Triggered Sync)

```php
// src/State/Processor/NewDomain/SyncNewDomainProcessor.php
<?php

declare(strict_types=1);

namespace App\State\Processor\NewDomain;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Message\TriggerNewDomainSync;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<object, void>
 */
class SyncNewDomainProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->messageBus->dispatch(new TriggerNewDomainSync());
    }
}
```

### Scheduler Registration

Add to `src/Scheduler/SyncScheduler.php`:

```php
->add(
    RecurringMessage::every('30 minutes', new TriggerNewDomainSync())
)
```

Current schedule intervals:
| Task | Interval |
|---|---|
| Ansiblex sync | 12 hours |
| Structure market | 2 hours |
| Jita market | 2 hours |
| PVE data | 1 hour |
| Industry jobs | 30 minutes |
| Mining ledger | 1 hour |
| Wallet transactions | 1 hour |
| Planetary colonies | 30 minutes |

---

## 7. Batch/Parallel Request Pattern

For fetching prices or data for many type IDs concurrently (as in `JitaMarketService`):

```php
$batchSize = 20;
$batches = array_chunk($typeIds, $batchSize);

foreach ($batches as $batch) {
    $responses = [];

    // Start all requests in parallel (non-blocking)
    foreach ($batch as $typeId) {
        $url = sprintf('%s/markets/%d/orders/?order_type=sell&type_id=%d',
            $esiBaseUrl, $regionId, $typeId);
        $responses[$typeId] = $this->httpClient->request('GET', $url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    // Collect results (Symfony HttpClient processes concurrently)
    foreach ($responses as $typeId => $response) {
        try {
            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                // process $data...
            }
        } catch (\Throwable) {
            // Skip failed requests
        }
    }

    unset($responses); // Free memory
    usleep(100_000);   // 100ms between batches
}
```

**Note**: For large batch operations like JitaMarketService, the service uses `HttpClientInterface` directly (not EsiClient) to avoid the overhead of token management and rate limit tracking on public endpoints.

---

## 8. SDE Integration (Static Data Lookups)

### Type Name Resolution (`InvTypeRepository`)

```php
// Single type
$type = $this->invTypeRepository->find($typeId);
$name = $type?->getTypeName() ?? "Type #{$typeId}";

// Batch resolution
$typeIds = [34, 35, 36]; // Tritanium, Pyerite, Mexallon
$types = $this->invTypeRepository->findByTypeIds($typeIds);
foreach ($types as $typeId => $type) {
    $names[$typeId] = $type->getTypeName();
}
```

### Solar System Lookups (`MapSolarSystemRepository`)

```php
$solarSystem = $this->solarSystemRepository->findBySolarSystemId($systemId);
$name = $solarSystem?->getSolarSystemName();
$security = $solarSystem?->getSecurity();
```

### ESI Name Resolution (Dynamic)

For IDs that are not in the SDE (player names, custom item names):

```php
// POST /universe/names/ - resolves up to 1000 IDs at once
$data = $this->esiClient->post('/universe/names/', $ids);
// Returns: [{"id": 123, "name": "Some Name", "category": "character"}, ...]
```

### Station vs Structure Resolution

```php
// Station IDs are < 1_000_000_000_000 (NPC stations, in SDE)
if ($locationId < 1_000_000_000_000) {
    $data = $this->esiClient->get("/universe/stations/{$locationId}/");
} else {
    // Player structure - requires auth with esi-universe.read_structures.v1
    $data = $this->esiClient->get("/universe/structures/{$locationId}/", $token);
}
```

---

## 9. ESI Endpoints Quick Reference

### Base URL
```
https://esi.evetech.net/latest
```

### Common Endpoints

| Method | Endpoint | Auth | Paginated | Description |
|---|---|---|---|---|
| `GET` | `/characters/{id}/` | No | No | Character public info |
| `GET` | `/characters/{id}/assets/` | Yes | Yes | Personal assets |
| `GET` | `/characters/{id}/planets/` | Yes | No | PI colony list |
| `GET` | `/characters/{id}/planets/{planet_id}/` | Yes | No | PI colony detail |
| `GET` | `/characters/{id}/industry/jobs/` | Yes | No | Industry jobs |
| `GET` | `/characters/{id}/mining/` | Yes | No | Mining ledger |
| `GET` | `/characters/{id}/wallet/` | Yes | No | Wallet balance (scalar) |
| `GET` | `/characters/{id}/wallet/journal/` | Yes | Yes | Wallet journal |
| `GET` | `/characters/{id}/wallet/transactions/` | Yes | No | Wallet transactions |
| `GET` | `/characters/{id}/blueprints/` | Yes | Yes | Blueprints |
| `GET` | `/characters/{id}/contracts/` | Yes | Yes | Contracts |
| `GET` | `/characters/{id}/killmails/recent/` | Yes | No | Recent killmails |
| `GET` | `/characters/{id}/skills/` | Yes | No | Skills |
| `GET` | `/characters/{id}/roles/` | Yes | No | Corporation roles |
| `GET` | `/characters/{id}/notifications/` | Yes | No | Notifications |
| `GET` | `/characters/{id}/location/` | Yes | No | Current location |
| `GET` | `/characters/{id}/ship/` | Yes | No | Current ship |
| `GET` | `/characters/{id}/online/` | Yes | No | Online status |
| `GET` | `/corporations/{id}/` | No | No | Corporation public info |
| `GET` | `/corporations/{id}/assets/` | Yes | Yes | Corporation assets |
| `GET` | `/corporations/{id}/divisions/` | Yes | No | Hangar divisions |
| `GET` | `/corporations/{id}/structures/` | Yes | Yes | Corp structures |
| `GET` | `/corporations/{id}/industry/jobs/` | Yes | Yes | Corp industry jobs |
| `GET` | `/alliances/{id}/` | No | No | Alliance public info |
| `GET` | `/markets/{region_id}/orders/` | No | Yes | Regional market orders |
| `GET` | `/markets/structures/{id}/` | Yes | Yes | Structure market orders |
| `GET` | `/universe/stations/{id}/` | No | No | NPC station info |
| `GET` | `/universe/structures/{id}/` | Yes | No | Player structure info |
| `GET` | `/universe/planets/{id}/` | No | No | Planet info |
| `GET` | `/universe/systems/{id}/` | No | No | Solar system info |
| `POST` | `/universe/names/` | No | No | Bulk ID-to-name resolve |

### Response Headers

| Header | Description |
|---|---|
| `X-Pages` | Total pages for paginated endpoints |
| `X-Esi-Error-Limit-Remain` | Remaining error budget (starts at 100) |
| `X-Esi-Error-Limit-Reset` | Seconds until error budget resets |
| `ETag` | For conditional requests (`If-None-Match`) |
| `Expires` | Cache expiry time |
| `Last-Modified` | When data was last updated |

### Error Codes

| Code | Meaning | Action |
|---|---|---|
| 304 | Not Modified | Use cached data (handled by `getWithCache`) |
| 400 | Bad Request | Check parameters |
| 401 | Unauthorized | Token invalid/expired (auto-refreshed by EsiClient) |
| 403 | Forbidden | Missing scope or missing in-game role |
| 404 | Not Found | Entity does not exist |
| 420 | Error Limited | Sleep and retry (handled by EsiClient, 1 retry) |
| 429 | Rate Limited | Too many requests (different from 420) |
| 500 | Internal Server Error | ESI bug |
| 502 | Bad Gateway | ESI proxy issue |
| 503 | Service Unavailable | ESI down for maintenance |
| 504 | Gateway Timeout | ESI took too long |

---

## 10. OAuth2 Scopes Reference

All 27 scopes requested during authentication (defined in `AuthenticationService::REQUIRED_SCOPES`):

### Assets & Corporation
| Scope | Endpoint | Description |
|---|---|---|
| `esi-assets.read_assets.v1` | `/characters/{id}/assets/` | Personal inventory |
| `esi-assets.read_corporation_assets.v1` | `/corporations/{id}/assets/` | Corporation inventory |
| `esi-characters.read_corporation_roles.v1` | `/characters/{id}/roles/` | Check in-game roles |
| `esi-corporations.read_divisions.v1` | `/corporations/{id}/divisions/` | Hangar division names |
| `esi-corporations.read_structures.v1` | `/corporations/{id}/structures/` | Corp structures (Ansiblex) |

### Wallet & Contracts
| Scope | Endpoint | Description |
|---|---|---|
| `esi-wallet.read_character_wallet.v1` | `/characters/{id}/wallet/` | Balance + journal + transactions |
| `esi-contracts.read_character_contracts.v1` | `/characters/{id}/contracts/` | Personal contracts |

### Industry & Mining
| Scope | Endpoint | Description |
|---|---|---|
| `esi-industry.read_character_jobs.v1` | `/characters/{id}/industry/jobs/` | Personal industry jobs |
| `esi-industry.read_corporation_jobs.v1` | `/corporations/{id}/industry/jobs/` | Corp industry jobs |
| `esi-industry.read_character_mining.v1` | `/characters/{id}/mining/` | Personal mining ledger |
| `esi-industry.read_corporation_mining.v1` | `/corporations/{id}/mining/observers/` | Corp mining observers |
| `esi-characters.read_blueprints.v1` | `/characters/{id}/blueprints/` | Personal blueprints |
| `esi-corporations.read_blueprints.v1` | `/corporations/{id}/blueprints/` | Corp blueprints |

### Skills
| Scope | Endpoint | Description |
|---|---|---|
| `esi-skills.read_skills.v1` | `/characters/{id}/skills/` | Skills (ME/TE calculations) |
| `esi-skills.read_skillqueue.v1` | `/characters/{id}/skillqueue/` | Skill training queue |

### Location & Fleet
| Scope | Endpoint | Description |
|---|---|---|
| `esi-location.read_location.v1` | `/characters/{id}/location/` | Current system/station |
| `esi-location.read_ship_type.v1` | `/characters/{id}/ship/` | Current ship |
| `esi-location.read_online.v1` | `/characters/{id}/online/` | Online status |
| `esi-fleets.read_fleet.v1` | `/fleets/{id}/` | Fleet info |

### Universe & Search
| Scope | Endpoint | Description |
|---|---|---|
| `esi-universe.read_structures.v1` | `/universe/structures/{id}/` | Player structure info |
| `esi-search.search_structures.v1` | `/characters/{id}/search/` | Search structures by name |

### Intel
| Scope | Endpoint | Description |
|---|---|---|
| `esi-characters.read_notifications.v1` | `/characters/{id}/notifications/` | In-game notifications |
| `esi-killmails.read_killmails.v1` | `/characters/{id}/killmails/recent/` | Recent killmails |

### Market
| Scope | Endpoint | Description |
|---|---|---|
| `esi-markets.structure_markets.v1` | `/markets/structures/{id}/` | Structure market orders |

### UI
| Scope | Endpoint | Description |
|---|---|---|
| `esi-ui.open_window.v1` | `POST /ui/openwindow/marketdetails/` | Open in-game window |

### Corporation Projects
| Scope | Endpoint | Description |
|---|---|---|
| `esi-corporations.read_projects.v1` | `/corporations/{id}/projects/` | Corp project contributions |

### Planetary Interaction
| Scope | Endpoint | Description |
|---|---|---|
| `esi-planets.manage_planets.v1` | `/characters/{id}/planets/` | PI colonies and pins |

---

## 11. Common Patterns and Gotchas

### Chunking for POST /universe/names/
ESI limits to 1000 IDs per request:
```php
foreach (array_chunk($itemIds, 1000) as $chunk) {
    $data = $this->esiClient->post('/universe/names/', $chunk);
    // ...
}
```

### Corporation Endpoints Require In-Game Roles
Having the ESI scope is not enough for corporation endpoints. The character also needs in-game roles:
```php
// Check Director role before accessing corp assets
if (!$this->characterService->hasRole($character, 'Director')) {
    return [];
}
```

### Structure Resolution Strategy (Fallback Chain)
1. Check in-memory cache (`$this->structureCache`)
2. Check database cache (`CachedStructure` entity)
3. Try with primary token
4. Try with all other available tokens
5. Cache as "unresolved" to avoid repeated failures

### Consuming Response Body on Error
When not using the response body (e.g., error responses), always consume it to prevent curl handle leaks:
```php
$response->getContent(false); // false = don't throw on error status
```

### Memory Management for Large Syncs
Free response arrays between batches:
```php
unset($responses); // Helps PHP GC reclaim memory
```

### ESI Cache Times
- Market orders: ~5 minutes
- Character assets: ~1 hour
- Character info: ~24 hours (use `getWithCache`)
- Corporation info: ~24 hours (use `getWithCache`)
- Universe data (stations, systems): ~24 hours

### Never Block the Web Process
All ESI sync operations must go through Messenger (async). The API Platform processor dispatches a message; the worker handles the actual ESI calls:
```php
// Processor (web process) - fast, non-blocking
$this->messageBus->dispatch(new TriggerSomethingSync());

// Handler (worker process) - slow, does the actual work
// SyncSomethingHandler -> calls SyncService -> calls ESI Service
```

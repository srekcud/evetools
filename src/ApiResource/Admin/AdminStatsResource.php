<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Admin\AccessCheckProvider;
use App\State\Provider\Admin\AdminStatsProvider;

#[ApiResource(
    shortName: 'AdminStats',
    description: 'Admin statistics and access check',
    operations: [
        new Get(
            uriTemplate: '/admin/access',
            provider: AccessCheckProvider::class,
            output: AccessCheckResource::class,
            openapi: new Model\Operation(summary: 'Check if current user has admin access', tags: ['Administration']),
        ),
        new Get(
            uriTemplate: '/admin/stats',
            provider: AdminStatsProvider::class,
            openapi: new Model\Operation(summary: 'Get admin statistics', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AdminStatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'stats';

    public UserStatsDto $users;

    public CharacterStatsDto $characters;

    public TokenStatsDto $tokens;

    public AssetStatsDto $assets;

    public IndustryStatsDto $industry;

    public IndustryJobsStatsDto $industryJobs;

    public SyncStatsDto $syncs;

    public PveStatsDto $pve;

    public NotificationStatsDto $notifications;

    public MarketStatsDto $market;

    /** @var SchedulerHealthEntryDto[] */
    public array $schedulerHealth = [];

    public EsiErrorBudgetDto $esiErrorBudget;

    public MercureCookieDto $mercureCookie;

    public CharactersToRepairDto $charactersToRepair;

    public AdminCharactersDto $adminCharacters;

    public function __construct()
    {
        $this->charactersToRepair = new CharactersToRepairDto();
        $this->adminCharacters = new AdminCharactersDto();
        $this->esiErrorBudget = new EsiErrorBudgetDto();
        $this->mercureCookie = new MercureCookieDto();
        $this->users = new UserStatsDto();
        $this->characters = new CharacterStatsDto();
        $this->tokens = new TokenStatsDto();
        $this->assets = new AssetStatsDto();
        $this->industry = new IndustryStatsDto();
        $this->industryJobs = new IndustryJobsStatsDto();
        $this->syncs = new SyncStatsDto();
        $this->pve = new PveStatsDto();
        $this->notifications = new NotificationStatsDto();
        $this->market = new MarketStatsDto();
    }
}

class UserStatsDto
{
    public int $total = 0;
    public int $valid = 0;
    public int $invalid = 0;
    public int $activeLastWeek = 0;
    public int $activeLastMonth = 0;
}

class CharacterStatsDto
{
    public int $total = 0;
    public int $withValidTokens = 0;
    public int $needingSync = 0;
    public int $activeSyncScope = 0;
}

class TokenStatsDto
{
    public int $total = 0;
    public int $expired = 0;
    public int $expiring24h = 0;
    public int $healthy = 0;
}

class AssetStatsDto
{
    public int $totalItems = 0;
    public int $personalAssets = 0;
    public int $corporationAssets = 0;
}

class IndustryStatsDto
{
    public int $activeProjects = 0;
    public int $completedProjects = 0;
}

class IndustryJobsStatsDto
{
    public int $activeJobs = 0;
    public int $completedRecently = 0;
    public ?string $lastSync = null;
}

class SyncStatsDto
{
    public ?string $lastAssetSync = null;
    public ?string $lastIndustrySync = null;
    public int $structuresCached = 0;
    public int $ansiblexCount = 0;
    public int $walletTransactionCount = 0;
    public ?string $lastWalletSync = null;
    public ?string $lastMiningSync = null;
}

class PveStatsDto
{
    public float $totalIncome30d = 0.0;

    /** @var PveCorporationStatsDto[] */
    public array $byCorporation = [];
}

class PveCorporationStatsDto
{
    public int $corporationId;
    public string $corporationName;
    public float $total;

    public function __construct(int $corporationId = 0, string $corporationName = '', float $total = 0.0)
    {
        $this->corporationId = $corporationId;
        $this->corporationName = $corporationName;
        $this->total = $total;
    }
}

class NotificationStatsDto
{
    public int $total = 0;
    public int $unread = 0;
    public int $pushSubscriptions = 0;
    public int $preferences = 0;
}

class MarketStatsDto
{
    public int $historyTypes = 0;
    public int $historyEntries = 0;
    public int $alertsActive = 0;
    public int $alertsTriggered = 0;
    public int $favorites = 0;
}

class SchedulerHealthEntryDto
{
    public string $type;
    public string $status;
    public string $health;
    public ?string $startedAt = null;
    public ?string $completedAt = null;
    public ?string $message = null;
    /** Null for on-demand syncs, which are not scheduled. */
    public ?int $expectedInterval;
    public bool $onDemand;
    public bool $triggerable;
    /** @var SchedulerHealthRunDto[] */
    public array $runs = [];
    public ?int $successRate24h = null;

    public function __construct(
        string $type = '',
        string $status = 'unknown',
        string $health = 'unknown',
        ?string $startedAt = null,
        ?string $completedAt = null,
        ?string $message = null,
        ?int $expectedInterval = null,
        bool $onDemand = false,
        bool $triggerable = false,
    ) {
        $this->type = $type;
        $this->status = $status;
        $this->health = $health;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
        $this->message = $message;
        $this->expectedInterval = $expectedInterval;
        $this->onDemand = $onDemand;
        $this->triggerable = $triggerable;
    }
}

class SchedulerHealthRunDto
{
    public function __construct(
        public string $startedAt,
        public ?string $completedAt,
        public string $status,
        public ?string $message,
        public bool $stuck,
    ) {
    }
}

class EsiErrorBudgetDto
{
    public ?int $remain = null;
    public ?int $resetSeconds = null;
    public ?string $observedAt = null;
    public ?string $windowEndsAt = null;
    public string $level = 'unknown';
    public int $limited420 = 0;
    public int $limited429 = 0;

    /** @var EsiLimitedFamilyDto[] */
    public array $byFamily = [];

    /** @var EsiLimitedHourDto[] */
    public array $hourly = [];
}

class EsiLimitedFamilyDto
{
    public function __construct(
        public string $family = '',
        public int $limited420 = 0,
        public int $limited429 = 0,
    ) {
    }
}

class EsiLimitedHourDto
{
    public function __construct(
        public string $hour = '',
        public int $limited420 = 0,
        public int $limited429 = 0,
    ) {
    }
}

class MercureCookieDto
{
    public int $limitBytes = 0;
    public int $warningThresholdBytes = 0;
    public int $nearLimitCount = 0;

    /** @var MercureCookieUserDto[] */
    public array $users = [];
}

class MercureCookieUserDto
{
    public function __construct(
        public string $userId = '',
        public ?string $mainCharacterName = null,
        public int $cookieBytes = 0,
        public int $topicCount = 0,
    ) {
    }
}

class CharactersToRepairDto
{
    public int $count = 0;

    /** @var CharacterToRepairDto[] */
    public array $characters = [];
}

class CharacterToRepairDto
{
    /**
     * @param list<string> $missingScopes
     */
    public function __construct(
        public int $eveCharacterId = 0,
        public string $characterName = '',
        public ?string $userId = null,
        public ?string $userLastLoginAt = null,
        public string $state = '',
        public array $missingScopes = [],
        public string $sinceAt = '',
    ) {
    }
}

class AdminCharactersDto
{
    public int $count = 0;

    /** @var AdminCharacterDto[] */
    public array $characters = [];
}

class AdminCharacterDto
{
    public function __construct(
        public int $eveCharacterId = 0,
        public ?string $characterName = null,
        public bool $hasUser = false,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
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
            openapiContext: [
                'summary' => 'Check if current user has admin access',
            ],
        ),
        new Get(
            uriTemplate: '/admin/stats',
            provider: AdminStatsProvider::class,
            openapiContext: [
                'summary' => 'Get admin statistics',
            ],
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

    public function __construct()
    {
        $this->users = new UserStatsDto();
        $this->characters = new CharacterStatsDto();
        $this->tokens = new TokenStatsDto();
        $this->assets = new AssetStatsDto();
        $this->industry = new IndustryStatsDto();
        $this->industryJobs = new IndustryJobsStatsDto();
        $this->syncs = new SyncStatsDto();
        $this->pve = new PveStatsDto();
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

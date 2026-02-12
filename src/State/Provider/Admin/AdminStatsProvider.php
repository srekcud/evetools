<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AdminStatsResource;
use App\ApiResource\Admin\PveCorporationStatsDto;
use App\Entity\User;
use App\Service\Admin\AdminService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AdminStatsResource>
 */
class AdminStatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminService $adminService,
        private readonly array $adminCharacterNames,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminStatsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $stats = $this->adminService->getStats();

        $resource = new AdminStatsResource();

        // Users
        $resource->users->total = $stats['users']['total'] ?? 0;
        $resource->users->valid = $stats['users']['valid'] ?? 0;
        $resource->users->invalid = $stats['users']['invalid'] ?? 0;
        $resource->users->activeLastWeek = $stats['users']['activeLastWeek'] ?? 0;
        $resource->users->activeLastMonth = $stats['users']['activeLastMonth'] ?? 0;

        // Characters
        $resource->characters->total = $stats['characters']['total'] ?? 0;
        $resource->characters->withValidTokens = $stats['characters']['withValidTokens'] ?? 0;
        $resource->characters->needingSync = $stats['characters']['needingSync'] ?? 0;
        $resource->characters->activeSyncScope = $stats['characters']['activeSyncScope'] ?? 0;

        // Tokens
        $resource->tokens->total = $stats['tokens']['total'] ?? 0;
        $resource->tokens->expired = $stats['tokens']['expired'] ?? 0;
        $resource->tokens->expiring24h = $stats['tokens']['expiring24h'] ?? 0;
        $resource->tokens->healthy = $stats['tokens']['healthy'] ?? 0;

        // Assets
        $resource->assets->totalItems = $stats['assets']['totalItems'] ?? 0;
        $resource->assets->personalAssets = $stats['assets']['personalAssets'] ?? 0;
        $resource->assets->corporationAssets = $stats['assets']['corporationAssets'] ?? 0;

        // Industry
        $resource->industry->activeProjects = $stats['industry']['activeProjects'] ?? 0;
        $resource->industry->completedProjects = $stats['industry']['completedProjects'] ?? 0;

        // Industry Jobs
        $resource->industryJobs->activeJobs = $stats['industryJobs']['activeJobs'] ?? 0;
        $resource->industryJobs->completedRecently = $stats['industryJobs']['completedRecently'] ?? 0;
        $resource->industryJobs->lastSync = $stats['industryJobs']['lastSync'] ?? null;

        // Syncs
        $resource->syncs->lastAssetSync = $stats['syncs']['lastAssetSync'] ?? null;
        $resource->syncs->lastIndustrySync = $stats['syncs']['lastIndustrySync'] ?? null;
        $resource->syncs->structuresCached = $stats['syncs']['structuresCached'] ?? 0;
        $resource->syncs->ansiblexCount = $stats['syncs']['ansiblexCount'] ?? 0;
        $resource->syncs->walletTransactionCount = $stats['syncs']['walletTransactionCount'] ?? 0;
        $resource->syncs->lastWalletSync = $stats['syncs']['lastWalletSync'] ?? null;
        $resource->syncs->lastMiningSync = $stats['syncs']['lastMiningSync'] ?? null;

        // PVE
        $resource->pve->totalIncome30d = $stats['pve']['totalIncome30d'] ?? 0.0;
        $resource->pve->byCorporation = array_map(
            fn(array $corp) => new PveCorporationStatsDto(
                $corp['corporationId'] ?? 0,
                $corp['corporationName'] ?? '',
                $corp['total'] ?? 0.0
            ),
            $stats['pve']['byCorporation'] ?? []
        );

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        $mainChar = $user->getMainCharacter();
        if (!$mainChar) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $mainCharName = strtolower($mainChar->getName());
        $isAdmin = false;
        foreach ($this->adminCharacterNames as $adminName) {
            if (strtolower($adminName) === $mainCharName) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}

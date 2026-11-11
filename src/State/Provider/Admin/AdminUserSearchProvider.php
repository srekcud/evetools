<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AdminUserSearchResource;
use App\Security\AdminAccessGuard;
use App\Service\Admin\UserSearchHit;
use App\Service\Admin\UserSearcher;
use Psr\Log\LoggerInterface;

/**
 * @implements ProviderInterface<AdminUserSearchResource>
 */
class AdminUserSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        private readonly UserSearcher $searcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<AdminUserSearchResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $admin = $this->accessGuard->requireAdmin();

        $hits = $this->searcher->search($context['request']?->query->getString('search') ?? '');
        $this->logger->info('Admin searched users', ['adminId' => $admin->getId()?->toRfc4122(), 'resultCount' => count($hits)]);

        return array_map(static function (UserSearchHit $hit): AdminUserSearchResource {
            $resource = new AdminUserSearchResource();
            $resource->userId = $hit->userId;
            $resource->mainCharacterName = $hit->mainCharacterName;
            $resource->mainEveCharacterId = $hit->mainEveCharacterId;
            $resource->characterCount = $hit->characterCount;
            $resource->lastLoginAt = $hit->lastLoginAt;

            return $resource;
        }, $hits);
    }
}

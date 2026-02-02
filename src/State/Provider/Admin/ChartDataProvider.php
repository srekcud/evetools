<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\ChartResource;
use App\Entity\User;
use App\Service\Admin\AdminService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ChartResource>
 */
class ChartDataProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminService $adminService,
        private readonly array $adminCharacterNames,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ChartResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $chartData = $this->adminService->getChartData();

        $resource = new ChartResource();
        $resource->registrations = $chartData['registrations'] ?? [];
        $resource->activity = $chartData['activity'] ?? [];
        $resource->assetDistribution = $chartData['assetDistribution'] ?? [];

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

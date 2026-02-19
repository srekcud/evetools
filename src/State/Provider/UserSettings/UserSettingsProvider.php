<?php

declare(strict_types=1);

namespace App\State\Provider\UserSettings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserSettings\UserSettingsResource;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<UserSettingsResource>
 */
class UserSettingsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly int $defaultMarketStructureId,
        private readonly string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new UserSettingsResource();
        $resource->preferredMarketStructureId = $user->getPreferredMarketStructureId();
        $resource->preferredMarketStructureName = $user->getPreferredMarketStructureName();
        $resource->defaultMarketStructureId = $this->defaultMarketStructureId;
        $resource->defaultMarketStructureName = $this->defaultMarketStructureName;
        $resource->effectiveMarketStructureId = $user->getPreferredMarketStructureId() ?? $this->defaultMarketStructureId;
        $resource->effectiveMarketStructureName = $user->getPreferredMarketStructureName() ?? $this->defaultMarketStructureName;

        return $resource;
    }
}

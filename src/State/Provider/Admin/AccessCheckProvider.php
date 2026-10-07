<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AccessCheckResource;
use App\Entity\User;
use App\Security\AdminChecker;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<AccessCheckResource>
 */
class AccessCheckProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminChecker $adminChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccessCheckResource
    {
        $user = $this->security->getUser();

        $resource = new AccessCheckResource();
        $resource->hasAccess = false;
        $resource->characterName = null;

        if (!$user instanceof User) {
            return $resource;
        }

        $resource->characterName = $user->getMainCharacter()?->getName();
        $resource->hasAccess = $this->adminChecker->isAdmin($user);

        return $resource;
    }
}

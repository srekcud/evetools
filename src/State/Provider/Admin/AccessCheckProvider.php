<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AccessCheckResource;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<AccessCheckResource>
 */
class AccessCheckProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        /** @var list<string> */
        private readonly array $adminCharacterNames,
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

        $mainChar = $user->getMainCharacter();
        if ($mainChar) {
            $resource->characterName = $mainChar->getName();
            $mainCharName = strtolower($mainChar->getName());

            foreach ($this->adminCharacterNames as $adminName) {
                if (strtolower($adminName) === $mainCharName) {
                    $resource->hasAccess = true;
                    break;
                }
            }
        }

        return $resource;
    }
}

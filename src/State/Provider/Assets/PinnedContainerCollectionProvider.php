<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\PinnedContainerResource;
use App\Entity\User;
use App\Service\Assets\PinnedContainerCatalog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PinnedContainerResource>
 */
class PinnedContainerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PinnedContainerCatalog $catalog,
    ) {
    }

    /**
     * @return list<PinnedContainerResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(PinnedContainerResourceMapper::of(...), $this->catalog->entriesOf($user));
    }
}

<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\PinnedContainerResource;
use App\Entity\User;
use App\Service\Assets\PinnedContainerCatalog;
use App\Service\Assets\PinnedContainerEntry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PinnedContainerResource>
 */
class PinnedContainerItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PinnedContainerCatalog $catalog,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PinnedContainerResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $requestedId = (string) ($uriVariables['id'] ?? '');
        $entries = $this->catalog->entriesOf($user);
        $byKey = array_values(array_filter($entries, static fn (PinnedContainerEntry $entry): bool => $entry->key === $requestedId));
        $byItemId = array_values(array_filter($entries, static fn (PinnedContainerEntry $entry): bool => $entry->itemId !== null && (string) $entry->itemId === $requestedId));
        $entry = $byKey[0] ?? $byItemId[0] ?? null;

        if ($entry === null) {
            throw new NotFoundHttpException('Pinned container not found');
        }

        return PinnedContainerResourceMapper::of($entry);
    }
}

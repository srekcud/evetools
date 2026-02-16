<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketFavoriteResource;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MarketFavoriteResource>
 */
class MarketFavoriteDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketFavoriteRepository $favoriteRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketFavoriteResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $typeId = (int) ($uriVariables['typeId'] ?? 0);
        $favorite = $this->favoriteRepository->findByUserAndType($user, $typeId);

        if ($favorite === null) {
            throw new NotFoundHttpException('Favorite not found');
        }

        $resource = new MarketFavoriteResource();
        $resource->typeId = $favorite->getTypeId();
        $resource->typeName = "Type #{$favorite->getTypeId()}";
        $resource->createdAt = $favorite->getCreatedAt()->format('c');

        return $resource;
    }
}

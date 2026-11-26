<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPendingStockResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryPendingStockCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryPendingStockResource>
 */
class PlanetaryPendingStockProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryPendingStockCalculator $pendingStockCalculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPendingStockResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $pending = $this->pendingStockCalculator->calculate($this->colonyRepository->findByUser($user));

        $resource = new PlanetaryPendingStockResource();
        $resource->storages = $pending['storages'];
        $resource->pendingOutputs = $pending['pendingOutputs'];
        $resource->complete = $pending['complete'];

        return $resource;
    }
}

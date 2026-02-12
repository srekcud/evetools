<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryProductionResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryProductionCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryProductionResource>
 */
class PlanetaryProductionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryProductionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $colonies = $this->colonyRepository->findByUser($user);
        $production = $this->productionCalculator->calculateProduction($colonies);

        $resource = new PlanetaryProductionResource();
        $resource->tiers = $production['tiers'];
        $resource->totalDailyIsk = $production['totalDailyIsk'];
        $resource->totalMonthlyIsk = $production['totalMonthlyIsk'];

        return $resource;
    }
}

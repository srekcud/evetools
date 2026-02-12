<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryStatsResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryProductionCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryStatsResource>
 */
class PlanetaryStatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryStatsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $colonies = $this->colonyRepository->findByUser($user);
        $now = new \DateTimeImmutable();

        $resource = new PlanetaryStatsResource();
        $resource->totalColonies = count($colonies);

        $nearestExpiry = null;

        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                if ($pin->isExtractor()) {
                    $resource->totalExtractors++;
                    $expiry = $pin->getExpiryTime();

                    if ($expiry === null) {
                        continue;
                    }

                    if ($expiry < $now) {
                        $resource->expiredExtractors++;
                    } elseif ($pin->isExpiringSoon(24)) {
                        $resource->expiringExtractors++;
                        $resource->activeExtractors++;
                        if ($nearestExpiry === null || $expiry < $nearestExpiry) {
                            $nearestExpiry = $expiry;
                        }
                    } else {
                        $resource->activeExtractors++;
                        if ($nearestExpiry === null || $expiry < $nearestExpiry) {
                            $nearestExpiry = $expiry;
                        }
                    }
                } elseif ($pin->isFactory()) {
                    $resource->totalFactories++;
                }
            }
        }

        $resource->nearestExpiry = $nearestExpiry?->format('c');
        $resource->estimatedDailyIsk = $this->productionCalculator->calculateTotalDailyIsk($colonies);

        return $resource;
    }
}

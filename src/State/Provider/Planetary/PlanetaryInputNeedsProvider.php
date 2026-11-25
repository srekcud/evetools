<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryInputNeedsResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryInputNeedsResource>
 */
class PlanetaryInputNeedsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryInputNeedsCalculator $inputNeedsCalculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryInputNeedsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $needs = $this->inputNeedsCalculator->calculate($this->colonyRepository->findByUser($user));

        $resource = new PlanetaryInputNeedsResource();
        $resource->colonies = $needs['colonies'];
        $resource->launchpads = $needs['launchpads'];
        $resource->extractions = $needs['extractions'];
        $resource->weekly = $needs['weekly'];
        $resource->complete = $needs['complete'];

        return $resource;
    }
}

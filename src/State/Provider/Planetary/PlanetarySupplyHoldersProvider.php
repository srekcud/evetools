<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySupplyHoldersResource;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetarySupplyHoldersResource>
 */
final readonly class PlanetarySupplyHoldersProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private AssetBrowseRepository $assetBrowseRepository,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetarySupplyHoldersResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        return new PlanetarySupplyHoldersResource();
    }
}

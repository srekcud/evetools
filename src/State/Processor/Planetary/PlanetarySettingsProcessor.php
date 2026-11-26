<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryHomeLocationCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PlanetarySettingsResource, PlanetarySettingsResource>
 */
final readonly class PlanetarySettingsProcessor implements ProcessorInterface
{
    public const int MIN_COVERAGE_WEEKS = 1;
    public const int MAX_COVERAGE_WEEKS = 52;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private PlanetaryHomeLocationCatalog $catalog,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetarySettingsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        assert($data instanceof PlanetarySettingsResource);

        return $data;
    }
}

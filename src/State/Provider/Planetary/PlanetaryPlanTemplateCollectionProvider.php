<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Repository\PlanetaryPlanTemplateRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<PlanetaryPlanTemplateResource>
 */
final readonly class PlanetaryPlanTemplateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlanTemplateRepository $templateRepository,
    ) {
    }

    /**
     * @return list<PlanetaryPlanTemplateResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return [];
    }
}

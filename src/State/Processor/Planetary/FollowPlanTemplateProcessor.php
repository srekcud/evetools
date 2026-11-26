<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Planetary\FollowPlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Repository\PlanetaryPlanTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<FollowPlanTemplateInput, PlanetaryPlanTemplateResource>
 */
final readonly class FollowPlanTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlanTemplateResource
    {
        return new PlanetaryPlanTemplateResource();
    }
}

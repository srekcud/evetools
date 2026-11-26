<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Planetary\CreatePlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @implements ProcessorInterface<CreatePlanTemplateInput, PlanetaryPlanTemplateResource>
 */
final readonly class CreatePlanTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlanTemplateResource
    {
        return new PlanetaryPlanTemplateResource();
    }
}

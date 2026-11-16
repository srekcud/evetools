<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StructureListExportResource;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StructureListExportResource>
 */
class StructureListExportProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureListExportResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new StructureListExportResource();
        $resource->structures = array_values(array_map(
            static fn ($structure): array => [
                'name' => $structure->getName(),
                'locationId' => $structure->getLocationId(),
                'solarSystemId' => $structure->getSolarSystemId(),
                'structureType' => $structure->getStructureType(),
                'securityType' => $structure->getSecurityType(),
                'rigs' => array_values($structure->getRigs()),
                'facilityTaxRate' => $structure->getFacilityTaxPercent(),
            ],
            $this->structureConfigRepository->findByUser($user),
        ));

        return $resource;
    }
}

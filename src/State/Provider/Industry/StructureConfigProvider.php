<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StructureConfigResource;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<StructureConfigResource>
 */
class StructureConfigProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureConfigResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $structure = $this->structureRepository->find(Uuid::fromString($uriVariables['id']));

        if ($structure === null || $structure->getUser() !== $user) {
            throw new NotFoundHttpException('Structure not found');
        }

        $resource = new StructureConfigResource();
        $resource->id = $structure->getId()->toRfc4122();
        $resource->name = $structure->getName();
        $resource->locationId = $structure->getLocationId();
        $resource->securityType = $structure->getSecurityType();
        $resource->structureType = $structure->getStructureType();
        $resource->rigs = $structure->getRigs();
        $resource->isDefault = $structure->isDefault();
        $resource->isCorporationStructure = $structure->isCorporationStructure();
        $resource->manufacturingMaterialBonus = $structure->getManufacturingMaterialBonus();
        $resource->reactionMaterialBonus = $structure->getReactionMaterialBonus();
        $resource->manufacturingTimeBonus = $structure->getManufacturingTimeBonus();
        $resource->reactionTimeBonus = $structure->getReactionTimeBonus();
        $resource->createdAt = $structure->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}

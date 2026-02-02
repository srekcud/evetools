<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateStructureInput, StructureConfigResource>
 */
class CreateStructureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureConfigResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof CreateStructureInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        if ($data->isDefault) {
            $this->structureConfigRepository->clearDefaultForUser($user);
        }

        $structure = new IndustryStructureConfig();
        $structure->setUser($user);
        $structure->setName($data->name);
        $structure->setSecurityType($data->securityType);
        $structure->setStructureType($data->structureType);
        $structure->setRigs($data->rigs);
        $structure->setIsDefault($data->isDefault);

        if ($data->locationId !== null && $data->locationId > 0) {
            $structure->setLocationId($data->locationId);
            $corporationId = $user->getCorporationId();
            if ($corporationId !== null) {
                $structure->setCorporationId($corporationId);

                $cachedStructure = $this->cachedStructureRepository->findByStructureId($data->locationId);
                if ($cachedStructure !== null && $cachedStructure->getOwnerCorporationId() === $corporationId) {
                    $structure->setIsCorporationStructure(true);
                }
            }
        }

        $this->entityManager->persist($structure);
        $this->entityManager->flush();

        return $this->toResource($structure);
    }

    private function toResource(IndustryStructureConfig $structure): StructureConfigResource
    {
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
        $resource->createdAt = $structure->getCreatedAt()->format('c');

        return $resource;
    }
}

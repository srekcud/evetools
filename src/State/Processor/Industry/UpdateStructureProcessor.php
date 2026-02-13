<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateStructureInput, StructureConfigResource>
 */
class UpdateStructureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureConfigResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $structure = $this->structureConfigRepository->find(Uuid::fromString($uriVariables['id']));

        if ($structure === null || $structure->getUser() !== $user) {
            throw new NotFoundHttpException('Structure not found');
        }

        assert($data instanceof UpdateStructureInput);

        if ($data->name !== null) {
            $name = trim($data->name);
            if ($name === '') {
                throw new BadRequestHttpException('Name cannot be empty');
            }
            $structure->setName($name);
        }

        if ($data->securityType !== null) {
            $structure->setSecurityType($data->securityType);
        }

        if ($data->structureType !== null) {
            $structure->setStructureType($data->structureType);
        }

        if ($data->rigs !== null) {
            $structure->setRigs($data->rigs);
        }

        if ($data->isDefault !== null) {
            if ($data->isDefault && !$structure->isDefault()) {
                $this->structureConfigRepository->clearDefaultForUser($user);
            }
            $structure->setIsDefault($data->isDefault);
        }

        if ($data->isCorporationStructure !== null) {
            $structure->setIsCorporationStructure($data->isCorporationStructure);
        }

        if ($data->solarSystemId !== null) {
            $structure->setSolarSystemId($data->solarSystemId > 0 ? $data->solarSystemId : null);
        }

        $this->entityManager->flush();

        return $this->mapper->structureToResource($structure);
    }
}

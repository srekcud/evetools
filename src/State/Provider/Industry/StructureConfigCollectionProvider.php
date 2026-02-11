<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StructureConfigListResource;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StructureConfigListResource>
 */
class StructureConfigCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly RigOptionsProvider $rigOptionsProvider,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureConfigListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $structures = $this->structureConfigRepository->findByUser($user);

        $resource = new StructureConfigListResource();
        $resource->structures = array_map(
            fn ($s) => $this->mapper->structureToResource($s),
            $structures
        );
        $resource->rigOptions = $this->rigOptionsProvider->getRigOptionsArray();

        return $resource;
    }
}

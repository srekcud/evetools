<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\CopyCostsResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\InventionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<CopyCostsResource>
 */
class CopyCostsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly InventionService $inventionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CopyCostsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $data = $this->inventionService->getProjectCopyCosts($project);

        $resource = new CopyCostsResource();
        $resource->id = $uriVariables['id'];
        $resource->copies = $data['copies'];
        $resource->totalCopyCost = $data['totalCopyCost'];

        return $resource;
    }
}

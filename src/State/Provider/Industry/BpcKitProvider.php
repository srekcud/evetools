<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BpcKitResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\InventionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<BpcKitResource>
 */
class BpcKitProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly InventionService $inventionService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BpcKitResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $desiredBpcCount = 1;
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $queryParam = $request->query->getInt('desired_bpc_count', 1);

            if ($queryParam >= 1) {
                $desiredBpcCount = $queryParam;
            }
        }

        $breakdown = $this->inventionService->getBpcKitBreakdown($project, $desiredBpcCount);

        $resource = new BpcKitResource();
        $resource->id = $uriVariables['id'];
        $resource->isT2 = $breakdown['isT2'];
        $resource->inventions = $breakdown['inventions'];
        $resource->summary = $breakdown['summary'];

        return $resource;
    }
}

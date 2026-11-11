<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSellOrdersResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\SellOrders\ProjectSellOrder;
use App\Service\Industry\SellOrders\ProjectSellOrdersReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectSellOrdersResource>
 */
class ProjectSellOrdersProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectSellOrdersReader $sellOrdersReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectSellOrdersResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $sellOrders = $this->sellOrdersReader->of($project, $user);

        $resource = new ProjectSellOrdersResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->orders = array_map(static fn (ProjectSellOrder $order): array => $order->toArray(), $sellOrders->orders);
        $resource->charactersToReconnect = $sellOrders->charactersToReconnect;
        $resource->syncedAt = $sellOrders->syncedAt?->format(\DATE_ATOM);

        return $resource;
    }
}

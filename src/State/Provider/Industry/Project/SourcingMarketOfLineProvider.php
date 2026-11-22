<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectBomLineSourcingMarketResource;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\SourcingMarketAccess;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectBomLineSourcingMarketResource>
 */
final readonly class SourcingMarketOfLineProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private SourcingMarketAccess $access,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectBomLineSourcingMarketResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->access->assertMayChoose($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'] ?? null, 'BOM line not found'));
        if (null === $line || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        if (null === $line->getSourcingMarket()) {
            throw new NotFoundHttpException('No market chosen for this line');
        }

        return ProjectBomLineSourcingMarketResource::fromLine($line);
    }
}

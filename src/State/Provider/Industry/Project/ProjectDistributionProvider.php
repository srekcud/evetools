<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectDistributionResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ParticipantDistribution;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectDistributionResource>
 */
class ProjectDistributionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectDistributionService $distributionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectDistributionResource
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

        $result = $this->distributionService->calculateDistribution($project);

        return $this->mapToResource($projectId, $result);
    }

    private function mapToResource(string $projectId, DistributionResult $result): ProjectDistributionResource
    {
        $resource = new ProjectDistributionResource();
        $resource->id = $projectId;
        $resource->totalRevenue = $result->totalRevenue;
        $resource->brokerFee = $result->brokerFee;
        $resource->salesTax = $result->salesTax;
        $resource->netRevenue = $result->netRevenue;
        $resource->totalProjectCost = $result->totalProjectCost;
        $resource->marginPercent = $result->marginPercent;
        $resource->members = array_values(array_map(
            fn (ParticipantDistribution $member) => [
                'memberId' => $member->memberId,
                'characterName' => $member->characterName,
                'totalCostsEngaged' => $member->totalCostsEngaged,
                'materialCosts' => $member->materialCosts,
                'jobInstallCosts' => $member->jobInstallCosts,
                'bpcCosts' => $member->bpcCosts,
                'lineRentalCosts' => $member->lineRentalCosts,
                'sharePercent' => $member->sharePercent,
                'profitPart' => $member->profitPart,
                'payoutTotal' => $member->payoutTotal,
            ],
            $result->members,
        ));

        return $resource;
    }
}

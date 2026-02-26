<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryDistributionResource;
use App\Entity\User;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\DistributionResult;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\GroupIndustry\MemberDistribution;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<GroupIndustryDistributionResource>
 */
class GroupDistributionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryDistributionService $distributionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryDistributionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->assertUserCanAccessProject($user, $project);

        $result = $this->distributionService->calculateDistribution($project);

        return $this->mapToResource($projectId, $result);
    }

    private function assertUserCanAccessProject(User $user, \App\Entity\GroupIndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]);
        if ($member !== null) {
            return;
        }

        $userCorpId = $user->getCorporationId();
        $ownerCorpId = $project->getOwner()->getCorporationId();
        if ($userCorpId !== null && $userCorpId === $ownerCorpId) {
            return;
        }

        throw new AccessDeniedHttpException('You do not have access to this project');
    }

    private function mapToResource(string $projectId, DistributionResult $result): GroupIndustryDistributionResource
    {
        $resource = new GroupIndustryDistributionResource();
        $resource->id = $projectId;
        $resource->totalRevenue = $result->totalRevenue;
        $resource->brokerFee = $result->brokerFee;
        $resource->salesTax = $result->salesTax;
        $resource->netRevenue = $result->netRevenue;
        $resource->totalProjectCost = $result->totalProjectCost;
        $resource->marginPercent = $result->marginPercent;
        $resource->members = array_map(
            fn (MemberDistribution $member) => [
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
        );

        return $resource;
    }
}

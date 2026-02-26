<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateGroupProjectInput, GroupIndustryProjectResource>
 */
class CreateGroupProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectService $projectService,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateGroupProjectInput);

        if (empty($data->items)) {
            throw new BadRequestHttpException('At least one item is required');
        }

        try {
            $projectData = new CreateProjectData(
                name: $data->name ?? '',
                items: $data->items,
                blacklistGroupIds: $data->blacklistGroupIds,
                blacklistTypeIds: $data->blacklistTypeIds,
                containerName: $data->containerName,
                lineRentalRatesOverride: $data->lineRentalRatesOverride,
                brokerFeePercent: $data->brokerFeePercent ?? 3.6,
                salesTaxPercent: $data->salesTaxPercent ?? 3.6,
            );

            $project = $this->projectService->createProject($user, $projectData);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Find the owner membership to pass to the mapper
        $ownerMembership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'role' => GroupMemberRole::Owner,
        ]);

        return $this->mapper->projectToResource($project, $ownerMembership);
    }
}

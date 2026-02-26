<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\Input\GroupIndustry\SubmitContributionInput;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryBomItemRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<SubmitContributionInput, GroupIndustryContributionResource>
 */
class SubmitContributionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryBomItemRepository $bomItemRepository,
        private readonly GroupIndustryContributionService $contributionService,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryContributionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof SubmitContributionInput);

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        // User must be an accepted member of the project
        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]);
        if ($member === null) {
            throw new AccessDeniedHttpException('You must be an accepted member to contribute');
        }

        // Resolve BOM item
        if ($data->bomItemId === null) {
            throw new BadRequestHttpException('bomItemId is required');
        }

        $bomItem = $this->bomItemRepository->find(Uuid::fromString($data->bomItemId));
        if ($bomItem === null || $bomItem->getProject() !== $project) {
            throw new NotFoundHttpException('BOM item not found');
        }

        // Resolve contribution type
        $type = ContributionType::tryFrom($data->type);
        if ($type === null) {
            throw new BadRequestHttpException(sprintf('Invalid contribution type "%s"', $data->type));
        }

        try {
            $contribution = $this->contributionService->submit(
                $member,
                $bomItem,
                $type,
                $data->quantity,
                $data->estimatedValue,
                $data->note,
            );
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'contribution_submitted',
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'type' => $contribution->getType()->value,
                'characterName' => $member->getUser()->getMainCharacter()?->getName() ?? 'Unknown',
                'bomItemName' => $contribution->getBomItem()?->getTypeName(),
                'quantity' => $contribution->getQuantity(),
                'value' => $contribution->getEstimatedValue(),
            ],
        );

        return $this->mapper->contributionToResource($contribution);
    }
}

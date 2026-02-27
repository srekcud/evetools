<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class LeaveGroupProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryContributionRepository $contributionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
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

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]);

        if ($member === null) {
            throw new NotFoundHttpException('You are not a member of this project');
        }

        if ($member->getRole() === GroupMemberRole::Owner) {
            throw new BadRequestHttpException('Owner cannot leave the project');
        }

        $contributionCount = $this->contributionRepository->countByMember($member);
        if ($contributionCount > 0) {
            throw new BadRequestHttpException('Cannot leave: you have contributions in this project');
        }

        // Publish before remove so we still have access to member data
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'member_left',
            [
                'memberId' => $member->getId()->toRfc4122(),
                'characterName' => $member->getUser()->getMainCharacter()?->getName() ?? 'Unknown',
            ],
        );

        $this->entityManager->remove($member);
        $this->entityManager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, GroupIndustryProjectResource>
 */
class JoinGroupProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $shortLinkCode = $uriVariables['shortLinkCode'] ?? null;
        if ($shortLinkCode === null) {
            throw new BadRequestHttpException('Short link code is required');
        }

        $project = $this->projectRepository->findByShortLinkCode($shortLinkCode);

        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        // Only allow joining published or in_progress projects
        if (!in_array($project->getStatus(), [GroupProjectStatus::Published, GroupProjectStatus::InProgress], true)) {
            throw new BadRequestHttpException('This project is no longer accepting new members');
        }

        // Check if user is already a member
        $existingMembership = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
        ]);

        if ($existingMembership !== null) {
            throw new ConflictHttpException('You are already a member of this project');
        }

        // Create membership with status depending on corporation match
        $member = new GroupIndustryProjectMember();
        $member->setUser($user);

        $userCorpId = $user->getCorporationId();
        $ownerCorpId = $project->getOwner()->getCorporationId();
        $isSameCorp = $userCorpId !== null && $ownerCorpId !== null && $userCorpId === $ownerCorpId;

        $member->setStatus($isSameCorp ? GroupMemberStatus::Accepted : GroupMemberStatus::Pending);
        $project->addMember($member);

        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'member_joined',
            [
                'memberId' => $member->getId()->toRfc4122(),
                'characterName' => $user->getMainCharacter()?->getName() ?? 'Unknown',
                'role' => $member->getRole()->value,
                'status' => $member->getStatus()->value,
            ],
        );

        return $this->mapper->projectToResource($project, $member);
    }
}

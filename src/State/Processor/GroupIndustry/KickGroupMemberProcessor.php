<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class KickGroupMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupProjectAccessChecker $accessChecker,
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
        $memberId = $uriVariables['id'] ?? null;

        if ($projectId === null || $memberId === null) {
            throw new NotFoundHttpException('Member not found');
        }

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        $member = $this->memberRepository->find(Uuid::fromString($memberId));
        if ($member === null || $member->getProject() !== $project) {
            throw new NotFoundHttpException('Member not found');
        }

        // Cannot kick the owner
        if ($member->getRole() === GroupMemberRole::Owner) {
            throw new BadRequestHttpException('Cannot kick the project owner');
        }

        // Cannot kick yourself
        if ($member->getUser() === $user) {
            throw new BadRequestHttpException('Cannot kick yourself, use leave instead');
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

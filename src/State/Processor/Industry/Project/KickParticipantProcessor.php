<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class KickParticipantProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectAccessChecker $accessChecker,
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

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        $member = $this->memberRepository->find(Uuid::fromString($memberId));
        if ($member === null || $member->getProject() !== $project) {
            throw new NotFoundHttpException('Member not found');
        }

        // Cannot kick the owner
        if ($member->getRole() === ParticipantRole::Owner) {
            throw new BadRequestHttpException('Cannot kick the project owner');
        }

        // Cannot kick yourself
        if ($member->getUser() === $user) {
            throw new BadRequestHttpException('Cannot kick yourself, use leave instead');
        }

        $this->accessChecker->assertCanManageMember($user, $project, $member);

        // Soft removal: the row is kept so the member's contributions (CASCADE on member) survive
        $member->setStatus(ParticipantStatus::Removed);
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'member_left',
            [
                'memberId' => $member->getId()->toRfc4122(),
                'characterName' => PilotName::of($member->getUser()),
            ],
        );
    }
}

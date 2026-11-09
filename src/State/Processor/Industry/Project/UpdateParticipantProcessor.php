<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectParticipantResource;
use App\ApiResource\Input\Industry\Project\UpdateParticipantInput;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateParticipantInput, ProjectParticipantResource>
 */
class UpdateParticipantProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectResourceMapper $mapper,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectParticipantResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpdateParticipantInput);

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

        // Cannot modify the owner
        if ($member->getRole() === ParticipantRole::Owner) {
            throw new BadRequestHttpException('Cannot modify the project owner');
        }

        $this->accessChecker->assertCanManageMember($user, $project, $member);

        if ($data->role !== null) {
            $newRole = ParticipantRole::tryFrom($data->role);
            if ($newRole === null || $newRole === ParticipantRole::Owner) {
                throw new BadRequestHttpException(sprintf('Invalid role "%s"', $data->role));
            }

            // Only owner can promote to admin
            if ($newRole === ParticipantRole::Admin && $project->getOwner() !== $user) {
                throw new AccessDeniedHttpException('Only the project owner can promote members to admin');
            }

            $member->setRole($newRole);
        }

        $accepted = false;
        if ($data->status !== null) {
            $newStatus = ParticipantStatus::tryFrom($data->status);
            if ($newStatus !== ParticipantStatus::Accepted) {
                throw new BadRequestHttpException('Status can only be changed to "accepted"');
            }

            if ($member->getStatus() !== ParticipantStatus::Pending) {
                throw new BadRequestHttpException('Only pending members can be accepted');
            }

            $member->setStatus(ParticipantStatus::Accepted);
            $accepted = true;
        }

        $this->entityManager->flush();

        if ($accepted) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $project->getId()->toRfc4122(),
                'member_accepted',
                [
                    'memberId' => $member->getId()->toRfc4122(),
                    'characterName' => PilotName::of($member->getUser()),
                ],
            );
        }

        return $this->mapper->memberToResource($member);
    }
}

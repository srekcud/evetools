<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\ApiResource\Input\GroupIndustry\UpdateGroupMemberInput;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateGroupMemberInput, GroupIndustryMemberResource>
 */
class UpdateGroupMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly GroupIndustryResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryMemberResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpdateGroupMemberInput);

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

        // Cannot modify the owner
        if ($member->getRole() === GroupMemberRole::Owner) {
            throw new BadRequestHttpException('Cannot modify the project owner');
        }

        if ($data->role !== null) {
            $newRole = GroupMemberRole::tryFrom($data->role);
            if ($newRole === null || $newRole === GroupMemberRole::Owner) {
                throw new BadRequestHttpException(sprintf('Invalid role "%s"', $data->role));
            }

            // Only owner can promote to admin
            if ($newRole === GroupMemberRole::Admin && $project->getOwner() !== $user) {
                throw new AccessDeniedHttpException('Only the project owner can promote members to admin');
            }

            $member->setRole($newRole);
        }

        if ($data->status !== null) {
            $newStatus = GroupMemberStatus::tryFrom($data->status);
            if ($newStatus !== GroupMemberStatus::Accepted) {
                throw new BadRequestHttpException('Status can only be changed to "accepted"');
            }

            if ($member->getStatus() !== GroupMemberStatus::Pending) {
                throw new BadRequestHttpException('Only pending members can be accepted');
            }

            $member->setStatus(GroupMemberStatus::Accepted);
        }

        $this->entityManager->flush();

        return $this->mapper->memberToResource($member);
    }
}

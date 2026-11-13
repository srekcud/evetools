<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectParticipant>
 */
class IndustryProjectParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectParticipant::class);
    }

    /**
     * @return string[]
     */
    public function findAcceptedProjectIds(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.project) AS projectId')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'projectId');
    }

    /**
     * @return array<string, list<string>> accepted project UUIDs, keyed by user UUID
     */
    public function findAcceptedProjectIdsByUser(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.user) AS userId', 'IDENTITY(m.project) AS projectId')
            ->andWhere('m.status = :status')
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->getQuery()
            ->getScalarResult();

        $projectIdsByUser = [];
        foreach ($rows as $row) {
            $projectIdsByUser[$row['userId']][] = $row['projectId'];
        }

        return $projectIdsByUser;
    }

    /**
     * Accepted memberships of a user, most recent first, with everything the project listing maps:
     * the project, its owner and the owner's main character are fetch-joined, the project items and
     * BOM items are batch-loaded (one query per collection, to avoid a row explosion).
     *
     * @return IndustryProjectParticipant[]
     */
    public function findAcceptedWithProjectsByUser(User $user): array
    {
        /** @var IndustryProjectParticipant[] $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->addSelect('p', 'owner', 'ownerCharacter', 'ownerToken')
            ->join('m.project', 'p')
            ->join('p.owner', 'owner')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('owner.mainCharacter', 'ownerCharacter')
            ->leftJoin('ownerCharacter.eveToken', 'ownerToken')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->orderBy('m.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $this->loadListedCollections(
            array_map(static fn (IndustryProjectParticipant $m): IndustryProject => $m->getProject(), $memberships),
        );

        return $memberships;
    }

    /**
     * @return IndustryProjectParticipant[]
     */
    public function findAcceptedOnProjectsCompletedBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var IndustryProjectParticipant[] $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->addSelect('p')
            ->join('m.project', 'p')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->andWhere('p.status = :projectStatus')
            ->andWhere('p.completedAt >= :from')
            ->andWhere('p.completedAt <= :to')
            ->setParameter('user', $user)
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->setParameter('projectStatus', ProjectStatus::Completed->value)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.completedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $this->loadListedCollections(
            array_map(static fn (IndustryProjectParticipant $m): IndustryProject => $m->getProject(), $memberships),
        );

        return $memberships;
    }

    /**
     * Initializes the items, the BOM items, the members and the containers of the given projects: one query per collection,
     * whatever the number of projects (a single fetch join would multiply the rows).
     *
     * @param IndustryProject[] $projects
     */
    public function loadListedCollections(array $projects): void
    {
        if ($projects === []) {
            return;
        }

        $this->loadProjectCollection($projects, 'items');
        $this->loadProjectCollection($projects, 'bomItems');
        $this->loadProjectCollection($projects, 'members');
        $this->loadProjectCollection($projects, 'containers');
    }

    /**
     * @param IndustryProject[] $projects
     *
     * @return array<string, list<IndustryProjectParticipant>>
     */
    public function findAcceptedGroupedByProject(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        /** @var IndustryProjectParticipant[] $participants */
        $participants = $this->createQueryBuilder('m')
            ->addSelect('u', 'mainCharacter', 'mainCharacterToken')
            ->join('m.user', 'u')
            ->leftJoin('u.mainCharacter', 'mainCharacter')
            ->leftJoin('mainCharacter.eveToken', 'mainCharacterToken')
            ->andWhere('m.project IN (:projects)')
            ->andWhere('m.status = :status')
            ->setParameter('projects', $projects)
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($participants as $participant) {
            $grouped[$participant->getProject()->getId()->toRfc4122()][] = $participant;
        }

        return $grouped;
    }

    /**
     * Initializes, in a single query, the user of each given membership and the user's main character.
     *
     * @param IndustryProjectParticipant[] $memberships
     */
    public function loadUsersWithMainCharacter(array $memberships): void
    {
        if ($memberships === []) {
            return;
        }

        $this->createQueryBuilder('m')
            ->addSelect('u', 'mainCharacter', 'mainCharacterToken')
            ->join('m.user', 'u')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('u.mainCharacter', 'mainCharacter')
            ->leftJoin('mainCharacter.eveToken', 'mainCharacterToken')
            ->andWhere('m IN (:memberships)')
            ->setParameter('memberships', $memberships)
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of accepted members per project, keyed by project UUID (RFC 4122); 0 for a project without any.
     *
     * @param IndustryProject[] $projects
     *
     * @return array<string, int>
     */
    public function countAcceptedByProject(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.project) AS projectId', 'COUNT(m.id) AS membersCount')
            ->andWhere('m.project IN (:projects)')
            ->andWhere('m.status = :status')
            ->setParameter('projects', $projects)
            ->setParameter('status', ParticipantStatus::Accepted->value)
            ->groupBy('m.project')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($projects as $project) {
            $counts[(string) $project->getId()] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['projectId']] = (int) $row['membersCount'];
        }

        return $counts;
    }

    public function findOwnershipSuccessor(IndustryProject $project, User $leavingOwner): ?IndustryProjectParticipant
    {
        return $this->createQueryBuilder('m')
            ->addSelect('CASE WHEN m.role = :admin THEN 0 ELSE 1 END AS HIDDEN rolePriority')
            ->andWhere('m.project = :project')
            ->andWhere('m.status = :accepted')
            ->andWhere('m.user IS NOT NULL')
            ->andWhere('m.user != :leavingOwner')
            ->setParameter('admin', ParticipantRole::Admin->value)
            ->setParameter('project', $project)
            ->setParameter('accepted', ParticipantStatus::Accepted->value)
            ->setParameter('leavingOwner', $leavingOwner)
            ->orderBy('rolePriority', 'ASC')
            ->addOrderBy('m.joinedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Initializes a collection of the given projects in a single fetch-join query.
     *
     * @param IndustryProject[] $projects
     */
    private function loadProjectCollection(array $projects, string $collection): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->select('p', 'c')
            ->from(IndustryProject::class, 'p')
            ->leftJoin('p.'.$collection, 'c')
            ->andWhere('p IN (:projects)')
            ->setParameter('projects', $projects)
            ->getQuery()
            ->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningOperationRecurrence;
use App\Entity\User;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningOperation>
 */
class MiningOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperation::class);
    }

    public function findLatestOf(MiningOperationRecurrence $recurrence): ?MiningOperation
    {
        return $this->findOneBy(['recurrence' => $recurrence], ['plannedStartAt' => 'DESC']);
    }

    public function findOneByJoinCode(string $joinCode): ?MiningOperation
    {
        return $this->findOneBy(['joinCode' => $joinCode]);
    }

    /**
     * @return list<MiningOperation>
     */
    public function findAvailableForCorporation(int $corporationId, User $user): array
    {
        $anyMembershipOfUser = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(MiningOperationMember::class, 'membership')
            ->where('membership.operation = operation')
            ->andWhere('membership.user = :user')
            ->getDQL();

        return $this->withOwner($this->createQueryBuilder('operation'))
            ->where('operation.visibility = :visibility')
            ->andWhere('operation.corporationId = :corporationId')
            ->andWhere('operation.status IN (:statuses)')
            ->andWhere('operation.owner != :user')
            ->andWhere(sprintf('NOT EXISTS (%s)', $anyMembershipOfUser))
            ->setParameter('visibility', MiningOperationVisibility::Corporation)
            ->setParameter('corporationId', $corporationId)
            ->setParameter('statuses', array_filter(MiningOperationStatus::cases(), static fn (MiningOperationStatus $status): bool => $status->acceptsParticipants()))
            ->setParameter('user', $user)
            ->orderBy('operation.plannedStartAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MiningOperation>
     */
    public function findForParticipant(User $user): array
    {
        $activeMembershipOfUser = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(MiningOperationMember::class, 'membership')
            ->where('membership.operation = operation')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.status IN (:memberStatuses)')
            ->getDQL();

        return $this->withOwner($this->createQueryBuilder('operation'))
            ->where(sprintf('operation.owner = :user OR EXISTS (%s)', $activeMembershipOfUser))
            ->andWhere('operation.status NOT IN (:archivedStatuses)')
            ->setParameter('user', $user)
            ->setParameter('memberStatuses', [MiningOperationMemberStatus::Pending, MiningOperationMemberStatus::Accepted])
            ->setParameter('archivedStatuses', $this->archivedStatuses())
            ->orderBy('operation.plannedStartAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MiningOperation>
     */
    public function findArchivedReadableBy(User $user): array
    {
        $acceptedMembershipOfUser = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(MiningOperationMember::class, 'membership')
            ->where('membership.operation = operation')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.status = :acceptedStatus')
            ->getDQL();

        $queryBuilder = $this->withOwner($this->createQueryBuilder('operation'))
            ->where('operation.status IN (:archivedStatuses)')
            ->setParameter('archivedStatuses', $this->archivedStatuses())
            ->setParameter('user', $user)
            ->setParameter('acceptedStatus', MiningOperationMemberStatus::Accepted)
            ->orderBy('operation.archivedAt', 'DESC');

        $readableBy = ['operation.owner = :user', sprintf('EXISTS (%s)', $acceptedMembershipOfUser)];
        $corporationId = $user->getCorporationId();
        if ($corporationId !== null) {
            $readableBy[] = '(operation.visibility = :corporationVisibility AND operation.corporationId = :corporationId)';
            $queryBuilder
                ->setParameter('corporationVisibility', MiningOperationVisibility::Corporation)
                ->setParameter('corporationId', $corporationId);
        }

        return $queryBuilder
            ->andWhere(implode(' OR ', $readableBy))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MiningOperationStatus>
     */
    private function archivedStatuses(): array
    {
        return array_values(array_filter(MiningOperationStatus::cases(), static fn (MiningOperationStatus $status): bool => $status->isArchived()));
    }

    private function withOwner(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->addSelect('owner', 'ownerMainCharacter')
            ->join('operation.owner', 'owner')
            ->leftJoin('owner.mainCharacter', 'ownerMainCharacter');
    }
}

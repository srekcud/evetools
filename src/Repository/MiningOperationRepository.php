<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
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
            ->setParameter('user', $user)
            ->setParameter('memberStatuses', [MiningOperationMemberStatus::Pending, MiningOperationMemberStatus::Accepted])
            ->orderBy('operation.plannedStartAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function withOwner(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->addSelect('owner', 'ownerMainCharacter')
            ->join('operation.owner', 'owner')
            ->leftJoin('owner.mainCharacter', 'ownerMainCharacter');
    }
}

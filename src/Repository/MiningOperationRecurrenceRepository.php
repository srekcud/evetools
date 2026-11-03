<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperationRecurrence;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MiningOperationRecurrence>
 */
class MiningOperationRecurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperationRecurrence::class);
    }

    /**
     * @return list<MiningOperationRecurrence>
     */
    public function findActive(): array
    {
        return $this->findBy(['stoppedAt' => null]);
    }

    /**
     * @return list<MiningOperationRecurrence>
     */
    public function findActiveOwnedBy(User $owner): array
    {
        return $this->findBy(['owner' => $owner, 'stoppedAt' => null], ['createdAt' => 'ASC']);
    }

    public function findActiveOwnedById(User $owner, Uuid $id): ?MiningOperationRecurrence
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner, 'stoppedAt' => null]);
    }
}

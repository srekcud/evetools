<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MiningOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperation::class);
    }

    public function findOneByJoinCode(string $joinCode): ?MiningOperation
    {
        return null;
    }

    public function findAvailableForCorporation(int $corporationId, User $user): array
    {
        return [];
    }

    public function findForParticipant(User $user): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MiningOperationCharacterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperationCharacter::class);
    }

    public function findOpenEnrolmentsOf(array $characters): array
    {
        return [];
    }
}

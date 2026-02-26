<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryScannerFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryScannerFavorite>
 */
class IndustryScannerFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryScannerFavorite::class);
    }

    /**
     * @return IndustryScannerFavorite[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function findByUserAndTypeId(User $user, int $typeId): ?IndustryScannerFavorite
    {
        return $this->findOneBy(['user' => $user, 'typeId' => $typeId]);
    }
}

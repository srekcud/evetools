<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryActivity>
 */
class IndustryActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryActivity::class);
    }

    /**
     * @return IndustryActivity[]
     */
    public function findByTypeId(int $typeId): array
    {
        return $this->findBy(['typeId' => $typeId]);
    }
}

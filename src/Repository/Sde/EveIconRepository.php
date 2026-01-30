<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\EveIcon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveIcon>
 */
class EveIconRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveIcon::class);
    }
}

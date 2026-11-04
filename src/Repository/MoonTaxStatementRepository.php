<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonTaxStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonTaxStatement>
 */
class MoonTaxStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonTaxStatement::class);
    }
}

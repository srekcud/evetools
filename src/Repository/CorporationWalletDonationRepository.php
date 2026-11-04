<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporationWalletDonation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporationWalletDonation>
 */
class CorporationWalletDonationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporationWalletDonation::class);
    }
}

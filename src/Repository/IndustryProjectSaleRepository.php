<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectSale;
use App\Entity\IndustryProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectSale>
 */
class IndustryProjectSaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectSale::class);
    }

    /**
     * @return IndustryProjectSale[]
     */
    public function findByProjectForListing(IndustryProject $project): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('recorder', 'recorderCharacter', 'recorderToken', 'seller', 'sellerToken')
            ->leftJoin('s.recordedBy', 'recorder')
            ->leftJoin('recorder.mainCharacter', 'recorderCharacter')
            ->leftJoin('recorderCharacter.eveToken', 'recorderToken')
            ->leftJoin('s.seller', 'seller')
            ->leftJoin('seller.eveToken', 'sellerToken')
            ->andWhere('s.project = :project')
            ->setParameter('project', $project)
            ->orderBy('s.soldAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

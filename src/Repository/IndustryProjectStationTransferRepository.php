<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStationTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<IndustryProjectStationTransfer>
 */
class IndustryProjectStationTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectStationTransfer::class);
    }

    /**
     * @return list<IndustryProjectStationTransfer>
     */
    public function findByProject(IndustryProject $project): array
    {
        return array_values($this->createQueryBuilder('transfer')
            ->andWhere('transfer.project = :project')
            ->setParameter('project', $project)
            ->orderBy('transfer.declaredAt', 'ASC')
            ->addOrderBy('transfer.routeId', 'ASC')
            ->addOrderBy('transfer.legIndex', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function findOneByProjectAndId(IndustryProject $project, Uuid $id): ?IndustryProjectStationTransfer
    {
        return $this->findOneBy(['project' => $project, 'id' => $id]);
    }

    /**
     * @return list<IndustryProjectStationTransfer>
     */
    public function findByRoute(Uuid $routeId): array
    {
        return array_values($this->createQueryBuilder('transfer')
            ->andWhere('transfer.routeId = :routeId')
            ->setParameter('routeId', $routeId, UuidType::NAME)
            ->orderBy('transfer.legIndex', 'ASC')
            ->getQuery()
            ->getResult());
    }
}

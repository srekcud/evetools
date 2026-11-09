<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectLineReservation>
 */
class IndustryProjectLineReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectLineReservation::class);
    }

    public function findOneByLineAndParticipant(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): ?IndustryProjectLineReservation
    {
        return $this->findOneBy(['bomLine' => $line, 'participant' => $participant]);
    }

    public function sumQuantityOfOthers(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.quantity), 0)')
            ->andWhere('reservation.bomLine = :line')
            ->andWhere('reservation.participant != :participant')
            ->setParameter('line', $line)
            ->setParameter('participant', $participant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return IndustryProjectLineReservation[]
     */
    public function findByProject(IndustryProject $project): array
    {
        return $this->createQueryBuilder('reservation')
            ->innerJoin('reservation.bomLine', 'line')
            ->andWhere('line.project = :project')
            ->setParameter('project', $project)
            ->orderBy('reservation.reservedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteByLine(IndustryProjectBomLine $line): void
    {
        $this->createQueryBuilder('reservation')
            ->delete()
            ->andWhere('reservation.bomLine = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->execute();
    }

    public function deleteByParticipant(IndustryProjectParticipant $participant): void
    {
        $this->createQueryBuilder('reservation')
            ->delete()
            ->andWhere('reservation.participant = :participant')
            ->setParameter('participant', $participant)
            ->getQuery()
            ->execute();
    }

    public function deleteByProject(IndustryProject $project): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM '.IndustryProjectLineReservation::class.' reservation WHERE reservation.bomLine IN (SELECT line.id FROM '.IndustryProjectBomLine::class.' line WHERE line.project = :project)',
        )
            ->setParameter('project', $project)
            ->execute();
    }
}

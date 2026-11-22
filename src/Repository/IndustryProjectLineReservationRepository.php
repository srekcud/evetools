<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;

/**
 * @extends ServiceEntityRepository<IndustryProjectLineReservation>
 */
class IndustryProjectLineReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ClockInterface $clock)
    {
        parent::__construct($registry, IndustryProjectLineReservation::class);
    }

    public function findOneByLineAndParticipant(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): ?IndustryProjectLineReservation
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.bomLine = :line')
            ->andWhere('reservation.participant = :participant')
            ->andWhere('reservation.expiresAt > :now')
            ->setParameter('line', $line)
            ->setParameter('participant', $participant)
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneIncludingExpiredByLineAndParticipant(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): ?IndustryProjectLineReservation
    {
        return $this->findOneBy(['bomLine' => $line, 'participant' => $participant]);
    }

    /**
     * @return list<IndustryProjectLineReservation>
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->getResult();
    }

    public function sumQuantityOfOthers(IndustryProjectBomLine $line, IndustryProjectParticipant $participant): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.quantity), 0)')
            ->andWhere('reservation.bomLine = :line')
            ->andWhere('reservation.participant != :participant')
            ->andWhere('reservation.expiresAt > :now')
            ->setParameter('line', $line)
            ->setParameter('participant', $participant)
            ->setParameter('now', $this->clock->now())
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
            ->andWhere('reservation.expiresAt > :now')
            ->setParameter('project', $project)
            ->setParameter('now', $this->clock->now())
            ->orderBy('reservation.reservedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return IndustryProjectLineReservation[]
     */
    public function findByProjectAndParticipant(IndustryProject $project, IndustryProjectParticipant $participant): array
    {
        return $this->createQueryBuilder('reservation')
            ->innerJoin('reservation.bomLine', 'line')
            ->andWhere('line.project = :project')
            ->andWhere('reservation.participant = :participant')
            ->andWhere('reservation.expiresAt > :now')
            ->setParameter('project', $project)
            ->setParameter('participant', $participant)
            ->setParameter('now', $this->clock->now())
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

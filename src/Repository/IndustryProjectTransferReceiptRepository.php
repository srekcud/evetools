<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectTransferReceipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectTransferReceipt>
 */
class IndustryProjectTransferReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectTransferReceipt::class);
    }

    /**
     * @return list<IndustryProjectTransferReceipt>
     */
    public function findByProject(IndustryProject $project): array
    {
        return $this->findBy(['project' => $project]);
    }

    public function findOneByTransfer(
        IndustryProject $project,
        IndustryProjectParticipant $from,
        IndustryProjectParticipant $to,
    ): ?IndustryProjectTransferReceipt {
        return $this->findOneBy(['project' => $project, 'fromParticipant' => $from, 'toParticipant' => $to]);
    }
}

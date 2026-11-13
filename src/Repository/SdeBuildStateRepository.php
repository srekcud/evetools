<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SdeBuildState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SdeBuildState>
 */
class SdeBuildStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SdeBuildState::class);
    }

    public function current(): SdeBuildState
    {
        $state = $this->find(SdeBuildState::SINGLE_ROW_ID);
        if ($state !== null) {
            return $state;
        }

        $state = new SdeBuildState();
        $this->save($state);

        return $state;
    }

    public function save(SdeBuildState $state): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($state);
        $entityManager->flush();
    }
}

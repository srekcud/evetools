<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectLineBlueprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<IndustryProjectLineBlueprint>
 */
class IndustryProjectLineBlueprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectLineBlueprint::class);
    }

    /**
     * @return list<IndustryProjectLineBlueprint>
     */
    public function findByProject(IndustryProject $project): array
    {
        return array_values($this->createQueryBuilder('blueprint')
            ->andWhere('blueprint.project = :project')
            ->setParameter('project', $project)
            ->orderBy('blueprint.attachedAt', 'ASC')
            ->addOrderBy('blueprint.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function findOneByProjectAndId(IndustryProject $project, Uuid $id): ?IndustryProjectLineBlueprint
    {
        return $this->findOneBy(['project' => $project, 'id' => $id]);
    }

    public function findOneByProjectAndItemId(IndustryProject $project, int $itemId): ?IndustryProjectLineBlueprint
    {
        return $this->findOneBy(['project' => $project, 'itemId' => $itemId]);
    }
}

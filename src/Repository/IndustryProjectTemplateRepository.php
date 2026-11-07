<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectTemplate>
 */
class IndustryProjectTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectTemplate::class);
    }

    /** @return list<IndustryProjectTemplate> */
    public function findVisibleTo(User $user): array
    {
        $queryBuilder = $this->createQueryBuilder('template')
            ->addSelect('owner', 'mainCharacter', 'ownerToken')
            ->join('template.owner', 'owner')
            ->leftJoin('owner.mainCharacter', 'mainCharacter')
            ->leftJoin('mainCharacter.eveToken', 'ownerToken')
            ->orderBy('template.updatedAt', 'DESC')
            ->setParameter('user', $user);

        $corporationId = $user->getCorporationId();
        if ($corporationId === null) {
            $queryBuilder->where('template.owner = :user');
        } else {
            $queryBuilder
                ->where('template.owner = :user OR (template.sharedWithCorporation = true AND mainCharacter.corporationId = :corporationId)')
                ->setParameter('corporationId', $corporationId);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalStockContainer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PersonalStockContainer>
 */
class PersonalStockContainerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalStockContainer::class);
    }

    /**
     * @return list<PersonalStockContainer>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('container')
            ->where('container.user = :user')
            ->setParameter('user', $user)
            ->orderBy('container.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByItemId(int $itemId): ?PersonalStockContainer
    {
        return $this->findOneBy(['itemId' => $itemId]);
    }

    public function findOfUser(User $user, Uuid $id): ?PersonalStockContainer
    {
        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }
}

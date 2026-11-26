<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDiscordWebhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDiscordWebhook>
 */
class UserDiscordWebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDiscordWebhook::class);
    }

    public function findByUser(User $user): ?UserDiscordWebhook
    {
        return $this->findOneBy(['user' => $user]);
    }
}

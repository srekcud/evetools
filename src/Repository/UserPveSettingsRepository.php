<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPveSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPveSettings>
 */
class UserPveSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPveSettings::class);
    }

    public function findByUser(User $user): ?UserPveSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getOrCreate(User $user): UserPveSettings
    {
        $settings = $this->findByUser($user);

        if ($settings === null) {
            $settings = new UserPveSettings();
            $settings->setUser($user);
        }

        return $settings;
    }
}

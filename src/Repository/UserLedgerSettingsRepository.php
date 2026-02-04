<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserLedgerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLedgerSettings>
 */
class UserLedgerSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLedgerSettings::class);
    }

    public function findByUser(User $user): ?UserLedgerSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getOrCreate(User $user): UserLedgerSettings
    {
        $settings = $this->findByUser($user);

        if ($settings === null) {
            $settings = new UserLedgerSettings();
            $settings->setUser($user);
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}

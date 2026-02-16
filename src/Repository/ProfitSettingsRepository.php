<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfitSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfitSettings>
 */
class ProfitSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfitSettings::class);
    }

    public function findByUser(User $user): ?ProfitSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getOrCreate(User $user): ProfitSettings
    {
        $settings = $this->findByUser($user);

        if ($settings === null) {
            $settings = new ProfitSettings();
            $settings->setUser($user);
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}

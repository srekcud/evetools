<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryUserSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryUserSettings>
 */
class IndustryUserSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryUserSettings::class);
    }

    public function findOrCreateForUser(User $user): IndustryUserSettings
    {
        $settings = $this->findOneBy(['user' => $user]);
        if ($settings === null) {
            $settings = new IndustryUserSettings();
            $settings->setUser($user);
        }
        return $settings;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserNotificationPreference>
 */
class UserNotificationPreferenceRepository extends ServiceEntityRepository
{
    private const ALL_CATEGORIES = [
        Notification::CATEGORY_PLANETARY,
        Notification::CATEGORY_INDUSTRY,
        Notification::CATEGORY_ESCALATION,
        Notification::CATEGORY_ESI,
        Notification::CATEGORY_PRICE,
    ];

    private const DEFAULT_THRESHOLDS = [
        Notification::CATEGORY_PLANETARY => 120,
        Notification::CATEGORY_ESCALATION => 60,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserNotificationPreference::class);
    }

    /**
     * @return UserNotificationPreference[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndCategory(User $user, string $category): ?UserNotificationPreference
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->andWhere('p.category = :category')
            ->setParameter('user', $user)
            ->setParameter('category', $category)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all preferences for a user, creating defaults for missing categories.
     *
     * @return UserNotificationPreference[]
     */
    public function getOrCreateAll(User $user): array
    {
        $existing = $this->findByUser($user);
        $existingCategories = array_map(fn(UserNotificationPreference $p) => $p->getCategory(), $existing);

        $em = $this->getEntityManager();
        $created = false;

        foreach (self::ALL_CATEGORIES as $category) {
            if (!in_array($category, $existingCategories, true)) {
                $pref = new UserNotificationPreference();
                $pref->setUser($user);
                $pref->setCategory($category);
                $pref->setEnabled(true);
                $pref->setPushEnabled(false);

                if (isset(self::DEFAULT_THRESHOLDS[$category])) {
                    $pref->setThresholdMinutes(self::DEFAULT_THRESHOLDS[$category]);
                }

                $em->persist($pref);
                $existing[] = $pref;
                $created = true;
            }
        }

        if ($created) {
            $em->flush();
        }

        return $existing;
    }
}

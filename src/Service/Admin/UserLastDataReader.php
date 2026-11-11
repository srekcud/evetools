<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\CachedAsset;
use App\Entity\CachedCharacterOrder;
use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\OwnedBlueprint;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Entity\UserPveSettings;
use Doctrine\ORM\EntityManagerInterface;

class UserLastDataReader
{
    private const OWNED_BY_CHARACTER = 'character';
    private const OWNED_BY_USER = 'user';

    private const TIMESTAMPS_BY_TYPE = [
        'industry' => [CachedIndustryJob::class, 'cachedAt', self::OWNED_BY_CHARACTER],
        'blueprints' => [OwnedBlueprint::class, 'syncedAt', self::OWNED_BY_CHARACTER],
        'character-orders' => [CachedCharacterOrder::class, 'syncedAt', self::OWNED_BY_CHARACTER],
        'pve' => [UserPveSettings::class, 'lastSyncAt', self::OWNED_BY_USER],
        'mining' => [UserLedgerSettings::class, 'lastMiningSyncAt', self::OWNED_BY_USER],
        'assets' => [CachedAsset::class, 'cachedAt', self::OWNED_BY_CHARACTER],
        'wallet' => [CachedWalletTransaction::class, 'cachedAt', self::OWNED_BY_CHARACTER],
        'planetary' => [PlanetaryColony::class, 'cachedAt', self::OWNED_BY_CHARACTER],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, ?\DateTimeImmutable>
     */
    public function lastDataByType(User $user): array
    {
        $lastData = [];
        foreach (self::TIMESTAMPS_BY_TYPE as $type => [$entityClass, $field, $owner]) {
            $dql = $owner === self::OWNED_BY_CHARACTER
                ? sprintf('SELECT MAX(entity.%s) FROM %s entity JOIN entity.character owner WHERE owner.user = :user', $field, $entityClass)
                : sprintf('SELECT MAX(entity.%s) FROM %s entity WHERE entity.user = :user', $field, $entityClass);
            $latest = $this->entityManager->createQuery($dql)->setParameter('user', $user)->getSingleScalarResult();
            $lastData[$type] = $latest === null ? null : new \DateTimeImmutable((string) $latest);
        }

        return $lastData;
    }
}

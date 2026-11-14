<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TypePackagedVolume;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TypePackagedVolume>
 */
class TypePackagedVolumeRepository extends ServiceEntityRepository
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TypePackagedVolume::class);
    }

    public function upsert(int $typeId, float $packagedVolume, \DateTimeImmutable $refreshedAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO type_packaged_volumes (type_id, packaged_volume, refreshed_at) VALUES (:typeId, :packagedVolume, :refreshedAt) '
            .'ON CONFLICT (type_id) DO UPDATE SET packaged_volume = EXCLUDED.packaged_volume, refreshed_at = EXCLUDED.refreshed_at',
            [
                'typeId' => $typeId,
                'packagedVolume' => $packagedVolume,
                'refreshedAt' => $refreshedAt->format(self::TIMESTAMP_FORMAT),
            ],
        );
    }

    /**
     * @return array<int, float>
     */
    public function findAllByTypeId(): array
    {
        $volumes = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllNumeric('SELECT type_id, packaged_volume FROM type_packaged_volumes') as [$typeId, $packagedVolume]) {
            $volumes[(int) $typeId] = (float) $packagedVolume;
        }

        return $volumes;
    }

    public function findLatestRefreshedAt(): ?\DateTimeImmutable
    {
        $latest = $this->getEntityManager()->getConnection()->fetchOne('SELECT MAX(refreshed_at) FROM type_packaged_volumes');

        return is_string($latest) ? new \DateTimeImmutable($latest) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CharacterWalletReading>
 */
class CharacterWalletReadingRepository extends ServiceEntityRepository
{
    private const string DATABASE_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterWalletReading::class);
    }

    public function record(Character $character, ?\DateTimeImmutable $readAt, \DateTimeImmutable $nextReadAt): void
    {
        $characterId = $character->getId();
        if ($characterId === null) {
            throw new \LogicException('The character must be persisted before recording its wallet reading.');
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<SQL
                INSERT INTO character_wallet_reading (id, character_id, read_at, next_read_at)
                VALUES (:id, :characterId, :readAt, :nextReadAt)
                ON CONFLICT (character_id) DO UPDATE SET
                    read_at = COALESCE(EXCLUDED.read_at, character_wallet_reading.read_at),
                    next_read_at = EXCLUDED.next_read_at
            SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'characterId' => $characterId->toRfc4122(),
                'readAt' => $readAt?->format(self::DATABASE_DATE_FORMAT),
                'nextReadAt' => $nextReadAt->format(self::DATABASE_DATE_FORMAT),
            ],
        );
    }

    public function findOneByCharacter(Character $character): ?CharacterWalletReading
    {
        return $this->findOneBy(['character' => $character]);
    }

    /**
     * @param list<Character> $characters
     * @return list<CharacterWalletReading>
     */
    public function findByCharacters(array $characters): array
    {
        if ($characters === []) {
            return [];
        }

        return $this->findBy(['character' => $characters]);
    }
}

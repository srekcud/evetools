<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonTaxStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonTaxStatement>
 */
class MoonTaxStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonTaxStatement::class);
    }

    /**
     * @return list<MoonTaxStatement>
     */
    public function findOfCorporation(int $corporationId): array
    {
        return $this->findBy(['corporationId' => $corporationId], ['periodStart' => 'ASC']);
    }

    /**
     * @param list<int> $characterIds
     *
     * @return list<MoonTaxStatement>
     */
    public function findOfCharacters(array $characterIds): array
    {
        return $this->findBy(['characterId' => $characterIds], ['periodStart' => 'ASC']);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->findOneBy(['reference' => $reference]) !== null;
    }

    /**
     * @return array<string, true> keyed by key(structureId, periodStart, characterId)
     */
    public function findKeysOfCorporation(int $corporationId): array
    {
        $keys = [];
        foreach ($this->findOfCorporation($corporationId) as $statement) {
            $keys[self::key($statement->getStructureId(), $statement->getPeriodStart(), $statement->getCharacterId())] = true;
        }

        return $keys;
    }

    public static function key(int $structureId, \DateTimeImmutable $periodStart, int $characterId): string
    {
        return sprintf('%d|%d|%d', $structureId, $periodStart->getTimestamp(), $characterId);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvType>
 */
class InvTypeRepository extends ServiceEntityRepository
{
    private const int ASTEROID_CATEGORY_ID = 25;
    private const int HARVESTABLE_CLOUD_GROUP_ID = 711;
    private const int COMPRESSED_GAS_GROUP_ID = 4168;
    private const string COMPRESSED_TYPE_NAME_PATTERN = 'Compressed %';
    private const int REPROCESSING_SKILL_TYPE_ATTRIBUTE_ID = 790;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvType::class);
    }

    public function findByTypeId(int $typeId): ?InvType
    {
        return $this->find($typeId);
    }

    /**
     * @param int[] $typeIds
     * @return array<int, InvType>
     */
    public function findByTypeIds(array $typeIds): array
    {
        $types = $this->createQueryBuilder('t')
            ->leftJoin('t.group', 'g')
            ->addSelect('g')
            ->leftJoin('g.category', 'c')
            ->addSelect('c')
            ->where('t.typeId IN (:typeIds)')
            ->setParameter('typeIds', $typeIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($types as $type) {
            $indexed[$type->getTypeId()] = $type;
        }

        return $indexed;
    }

    /**
     * Resolves type names in one query (served by the LOWER(type_name) functional index).
     * For each requested name, a type with exactly that name wins over a type whose name
     * differs only by case. Unpublished types are returned: filtering them is up to the caller.
     *
     * @param list<string> $names
     * @return array<string, InvType> keyed by the requested name; unknown names are absent
     */
    public function findByNames(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $lowerNames = array_values(array_unique(array_map(mb_strtolower(...), $names)));

        /** @var list<InvType> $candidates */
        $candidates = $this->createQueryBuilder('t')
            ->where('LOWER(t.typeName) IN (:lowerNames)')
            ->setParameter('lowerNames', $lowerNames)
            ->orderBy('t.typeId', 'ASC')
            ->getQuery()
            ->getResult();

        $candidatesByLowerName = [];
        foreach ($candidates as $candidate) {
            $candidatesByLowerName[mb_strtolower($candidate->getTypeName())][] = $candidate;
        }

        $resolved = [];
        foreach ($names as $name) {
            $sameLowerName = $candidatesByLowerName[mb_strtolower($name)] ?? [];
            if ($sameLowerName === []) {
                continue;
            }
            $exactMatches = array_filter($sameLowerName, static fn (InvType $type): bool => $type->getTypeName() === $name);
            $resolved[$name] = $exactMatches === [] ? $sameLowerName[0] : reset($exactMatches);
        }

        return $resolved;
    }

    /**
     * Find a single published type by exact name (case-insensitive).
     * Returns null if no match is found.
     */
    public function findOneByName(string $name): ?InvType
    {
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.typeName) = LOWER(:name)')
            ->andWhere('t.published = :published')
            ->setParameter('name', $name)
            ->setParameter('published', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InvType[]
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        // Prioritize: exact match > starts with > contains
        // Also exclude SKINs from results to show actual items first
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.typeName) LIKE LOWER(:query)')
            ->andWhere('t.published = :published')
            ->setParameter('query', '%' . $query . '%')
            ->setParameter('published', true)
            ->orderBy('CASE
                WHEN LOWER(t.typeName) = LOWER(:exactQuery) THEN 0
                WHEN LOWER(t.typeName) LIKE LOWER(:startsWithQuery) THEN 1
                WHEN LOWER(t.typeName) LIKE :skinPattern THEN 3
                ELSE 2
            END', 'ASC')
            ->addOrderBy('t.typeName', 'ASC')
            ->setParameter('exactQuery', $query)
            ->setParameter('startsWithQuery', $query . '%')
            ->setParameter('skinPattern', '% SKIN')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InvType[]
     */
    public function findMineableCandidates(): array
    {
        return $this->mineableCandidatesQuery($this->createQueryBuilder('t'), 't')
            ->addSelect('g')
            ->addSelect('c')
            ->orderBy('t.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $typeIds
     * @return list<InvType>
     */
    public function findCompressedMineableTypes(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return $this->asteroidCategoryOrGroupQuery($this->createQueryBuilder('t'), 't', self::COMPRESSED_GAS_GROUP_ID)
            ->addSelect('g')
            ->addSelect('c')
            ->andWhere('t.typeId IN (:typeIds)')
            ->andWhere('t.typeName LIKE :compressedTypeNamePattern')
            ->setParameter('typeIds', $typeIds)
            ->setParameter('compressedTypeNamePattern', self::COMPRESSED_TYPE_NAME_PATTERN)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array{typeId: int, typeName: string}>
     */
    public function findOreProcessingSkills(): array
    {
        $queryBuilder = $this->createQueryBuilder('ore')
            ->select('skill.typeId AS typeId', 'skill.typeName AS typeName')
            ->distinct()
            ->innerJoin(DgmTypeAttribute::class, 'attribute', 'WITH', 'attribute.typeId = ore.typeId AND attribute.attributeId = :reprocessingSkillAttributeId')
            ->innerJoin(InvType::class, 'skill', 'WITH', 'skill.typeId = attribute.valueInt')
            ->setParameter('reprocessingSkillAttributeId', self::REPROCESSING_SKILL_TYPE_ATTRIBUTE_ID)
            ->orderBy('skill.typeName', 'ASC');

        /** @var list<array{typeId: int, typeName: string}> $skills */
        $skills = $this->mineableCandidatesQuery($queryBuilder, 'ore')
            ->getQuery()
            ->getArrayResult();

        return $skills;
    }

    private function mineableCandidatesQuery(QueryBuilder $queryBuilder, string $typeAlias): QueryBuilder
    {
        return $this->asteroidCategoryOrGroupQuery($queryBuilder, $typeAlias, self::HARVESTABLE_CLOUD_GROUP_ID)
            ->andWhere($typeAlias . '.marketGroup IS NOT NULL');
    }

    private function asteroidCategoryOrGroupQuery(QueryBuilder $queryBuilder, string $typeAlias, int $groupId): QueryBuilder
    {
        return $queryBuilder
            ->innerJoin($typeAlias . '.group', 'g')
            ->innerJoin('g.category', 'c')
            ->where($typeAlias . '.published = :published')
            ->andWhere('c.categoryId = :asteroidCategoryId OR g.groupId = :groupId')
            ->setParameter('published', true)
            ->setParameter('asteroidCategoryId', self::ASTEROID_CATEGORY_ID)
            ->setParameter('groupId', $groupId);
    }
}

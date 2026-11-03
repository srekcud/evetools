<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningOperationCharacter>
 */
class MiningOperationCharacterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperationCharacter::class);
    }

    /**
     * @param list<Character> $characters
     *
     * @return array<string, MiningOperation> open operation holding each character, by character id
     */
    public function findOpenEnrolmentsOf(array $characters): array
    {
        if ($characters === []) {
            return [];
        }

        $enrolments = $this->createQueryBuilder('enrolment')
            ->addSelect('membership', 'operation')
            ->join('enrolment.member', 'membership')
            ->join('membership.operation', 'operation')
            ->where('enrolment.character IN (:characters)')
            ->andWhere('membership.status IN (:memberStatuses)')
            ->andWhere('operation.status IN (:operationStatuses)')
            ->setParameter('characters', $characters)
            ->setParameter('memberStatuses', [MiningOperationMemberStatus::Pending, MiningOperationMemberStatus::Accepted])
            ->setParameter('operationStatuses', array_filter(MiningOperationStatus::cases(), static fn (MiningOperationStatus $status): bool => $status->isOpen()))
            ->getQuery()
            ->getResult();

        $held = [];
        foreach ($enrolments as $enrolment) {
            $characterId = $enrolment->getCharacter()->getId() ?? throw new \LogicException('The enrolled character has no identifier');
            $held[$characterId->toRfc4122()] = $enrolment->getMember()->getOperation();
        }

        return $held;
    }
}

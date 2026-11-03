<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Repository\MiningOperationCharacterRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MiningOperationEnrolment
{
    public function __construct(
        private MiningOperationCharacterRepository $characterRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $owner, CreateMiningOperationInput $input): MiningOperation
    {
        return new MiningOperation();
    }

    public function join(User $user, MiningOperation $operation, array $characterIds): MiningOperationMember
    {
        return new MiningOperationMember();
    }

    public function eligibilityOf(User $user): array
    {
        return [];
    }

    public function autoAccepts(User $user, MiningOperation $operation): bool
    {
        return false;
    }
}

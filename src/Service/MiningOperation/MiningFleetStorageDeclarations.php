<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MiningFleetStorageDelivery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MiningFleetStorageDeclarations
{
    use LocksMiningOperation;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function assign(MiningOperation $operation, ?MiningOperationMember $recipient, ?string $containerName): void
    {
    }

    /**
     * @param array<int, int>|null $quantitiesByTypeId
     */
    public function declare(MiningOperationMember $member, MiningFleetStorageDelivery $delivery, ?array $quantitiesByTypeId): void
    {
    }
}

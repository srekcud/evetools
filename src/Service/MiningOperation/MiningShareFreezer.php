<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class MiningShareFreezer
{
    public function __construct(
        private MiningOperationValuator $valuator,
        private TypeNameResolver $typeNameResolver,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function freeze(MiningOperation $operation): void
    {
    }
}

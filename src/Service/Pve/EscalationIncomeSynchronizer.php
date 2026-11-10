<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Escalation;
use App\Repository\PveIncomeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EscalationIncomeSynchronizer
{
    public function __construct(
        private PveIncomeRepository $incomeRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function synchronize(Escalation $escalation): void
    {
    }
}

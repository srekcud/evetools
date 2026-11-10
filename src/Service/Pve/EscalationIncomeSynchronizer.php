<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Escalation;
use App\Entity\PveIncome;
use App\Enum\EscalationSaleStatus;
use App\Enum\PveIncomeType;
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
        $income = $this->incomeRepository->findOneBy(['escalation' => $escalation]);

        if ($escalation->getSaleStatus() !== EscalationSaleStatus::Vendu) {
            if ($income !== null) {
                $this->entityManager->remove($income);
            }

            return;
        }

        $soldAt = $escalation->getSoldAt();
        if ($soldAt === null) {
            throw new \LogicException('A sold escalation must carry its sale date');
        }

        if ($income === null) {
            $income = (new PveIncome())
                ->setUser($escalation->getUser())
                ->setType(PveIncomeType::Escalation)
                ->setEscalation($escalation);
            $this->entityManager->persist($income);
        }

        $income
            ->setDescription(sprintf('%s - %s', $escalation->getType(), $escalation->getSolarSystemName()))
            ->setAmount((float) $escalation->getPrice())
            ->setDate($soldAt);
    }
}

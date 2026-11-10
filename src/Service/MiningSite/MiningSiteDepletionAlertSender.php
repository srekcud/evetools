<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\MiningOperationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class MiningSiteDepletionAlertSender
{
    private const string DEPLETION_LEAD = '10 minutes';

    public function __construct(
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteLedgerEtaReader $ledgerEtaReader,
        private MiningOperationNotifier $notifier,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EveDowntime $downtime,
    ) {
    }

    public function sendDueAlerts(): void
    {
        $now = $this->clock->now();
        $nextDowntime = $this->downtime->nextAfter($now);

        foreach ($this->siteRepository->findActiveOfRunningOperations() as $site) {
            $eta = $this->etaOf($site);
            if ($eta === null) {
                continue;
            }

            $this->alertDepletion($site, $eta, $now);
            $this->alertDowntime($site, $eta, $nextDowntime, $now);
        }
    }

    private function etaOf(MiningSite $site): ?\DateTimeImmutable
    {
        $lastScan = $this->scanRepository->findLastOf($site);
        if ($lastScan === null) {
            return null;
        }

        return $lastScan->getTrendEta() ?? $this->ledgerEtaReader->read($site)?->eta;
    }

    private function alertDepletion(MiningSite $site, \DateTimeImmutable $eta, \DateTimeImmutable $now): void
    {
        if ($site->getDepletionAlertedAt() !== null || $eta <= $now || $eta > $now->modify('+' . self::DEPLETION_LEAD)) {
            return;
        }

        $this->notifier->siteNearlyDepleted($this->operationOf($site), $site, $eta);
        $site->markDepletionAlerted($now);
        $this->entityManager->flush();
    }

    private function alertDowntime(MiningSite $site, \DateTimeImmutable $eta, \DateTimeImmutable $nextDowntime, \DateTimeImmutable $now): void
    {
        if ($site->getDowntimeAlertedAt() !== null || $eta <= $nextDowntime) {
            return;
        }

        $this->notifier->siteBeyondDowntime($this->operationOf($site), $site, $eta, $nextDowntime);
        $site->markDowntimeAlerted($now);
        $this->entityManager->flush();
    }

    private function operationOf(MiningSite $site): MiningOperation
    {
        return $site->getOperation() ?? throw new \LogicException('An alerted site belongs to an operation');
    }
}

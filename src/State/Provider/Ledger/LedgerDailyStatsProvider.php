<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\LedgerDailyStatsResource;
use App\ApiResource\Ledger\LedgerDayResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LedgerDailyStatsResource>
 */
class LedgerDailyStatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $pveIncomeRepository,
        private readonly PveExpenseRepository $pveExpenseRepository,
        private readonly UserLedgerSettingsRepository $ledgerSettingsRepository,
        private readonly MiningBestValueCalculator $miningBestValueCalculator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LedgerDailyStatsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Get settings
        $ledgerSettings = $this->ledgerSettingsRepository->findByUser($user);
        $corpProjectAccounting = $ledgerSettings?->getCorpProjectAccounting() ?? UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE;

        // Get PVE daily totals by type
        $pveDailyByType = $this->pveIncomeRepository->getDailyTotalsByType($user, $from, $to);

        // Get expense daily totals
        $expenseDaily = $this->pveExpenseRepository->getDailyTotals($user, $from, $to);

        // Get mining daily totals using best-price strategy
        $excludeMiningUsages = $corpProjectAccounting === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE
            ? [MiningEntry::USAGE_CORP_PROJECT]
            : null;
        $miningDailyBestValues = $this->miningBestValueCalculator->getDailyBestValues($user, $from, $to, $excludeMiningUsages);

        // Build combined daily stats
        $daily = [];
        $current = clone $from;

        while ($current <= $to) {
            $dateStr = $current->format('Y-m-d');

            $day = new LedgerDayResource();
            $day->date = $dateStr;

            // PVE (bounties + lootSales)
            $pveDay = $pveDailyByType[$dateStr] ?? ['bounties' => 0.0, 'lootSales' => 0.0];
            $day->pve = $pveDay['bounties'] + $pveDay['lootSales'];

            // Mining (best-price strategy)
            $day->mining = $miningDailyBestValues[$dateStr] ?? 0.0;

            // Expenses
            $expenseDay = $expenseDaily[$dateStr] ?? ['total' => 0.0];
            $day->expenses = $expenseDay['total'];

            // Total and profit
            $day->total = $day->pve + $day->mining;
            $day->profit = $day->total - $day->expenses;

            $daily[] = $day;
            $current = $current->modify('+1 day');
        }

        $resource = new LedgerDailyStatsResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->daily = $daily;

        return $resource;
    }
}

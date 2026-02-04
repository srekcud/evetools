<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\DailyStatsResource;
use App\ApiResource\Pve\StatsDailyResource;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StatsDailyResource>
 */
class StatsDailyProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly UserLedgerSettingsRepository $ledgerSettingsRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StatsDailyResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Check ledger settings for corp project accounting
        $ledgerSettings = $this->ledgerSettingsRepository->findByUser($user);
        $excludeCorpProject = $ledgerSettings?->getCorpProjectAccounting() === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING;

        $dailyData = [];
        $current = $from;
        while ($current <= $to) {
            $dateKey = $current->format('Y-m-d');
            $dailyData[$dateKey] = [
                'date' => $dateKey,
                'income' => 0.0,
                'bounties' => 0.0,
                'lootSales' => 0.0,
                'expenses' => 0.0,
                'profit' => 0.0,
            ];
            $current = $current->modify('+1 day');
        }

        $incomeDailyTotals = $this->incomeRepository->getDailyTotalsByType($user, $from, $to, $excludeCorpProject);
        foreach ($incomeDailyTotals as $dateKey => $data) {
            if (isset($dailyData[$dateKey])) {
                $dailyData[$dateKey]['bounties'] = $data['bounties'];
                $dailyData[$dateKey]['lootSales'] = $data['lootSales'];
            }
        }

        $expenseDailyTotals = $this->expenseRepository->getDailyTotals($user, $from, $to);
        foreach ($expenseDailyTotals as $dateKey => $data) {
            if (isset($dailyData[$dateKey])) {
                $dailyData[$dateKey]['expenses'] = $data['total'];
            }
        }

        foreach ($dailyData as &$day) {
            $day['income'] = $day['bounties'] + $day['lootSales'];
            $day['profit'] = $day['income'] - $day['expenses'];
        }
        unset($day);

        $resource = new StatsDailyResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->daily = array_map(function ($data) {
            $dayResource = new DailyStatsResource();
            $dayResource->date = $data['date'];
            $dayResource->income = $data['income'];
            $dayResource->bounties = $data['bounties'];
            $dayResource->lootSales = $data['lootSales'];
            $dayResource->expenses = $data['expenses'];
            $dayResource->profit = $data['profit'];

            return $dayResource;
        }, array_values($dailyData));

        return $resource;
    }
}

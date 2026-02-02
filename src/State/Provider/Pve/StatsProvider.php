<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\StatsResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StatsResource>
 */
class StatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StatsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        $incomeByType = $this->incomeRepository->getTotalsByType($user, $from, $to);
        $bountyTotal = $incomeByType[PveIncome::TYPE_BOUNTY] ?? 0.0;
        $essTotal = $incomeByType[PveIncome::TYPE_ESS] ?? 0.0;
        $missionTotal = $incomeByType[PveIncome::TYPE_MISSION] ?? 0.0;
        $lootSalesTotal = ($incomeByType[PveIncome::TYPE_LOOT_SALE] ?? 0.0)
            + ($incomeByType[PveIncome::TYPE_LOOT_CONTRACT] ?? 0.0)
            + ($incomeByType[PveIncome::TYPE_CORP_PROJECT] ?? 0.0);

        $expensesTotal = $this->expenseRepository->getTotalByUserAndDateRange($user, $from, $to);
        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        $totalIncome = $bountyTotal + $essTotal + $missionTotal + $lootSalesTotal;
        $profit = $totalIncome - $expensesTotal;
        $iskPerDay = $days > 0 ? $profit / $days : 0;

        $resource = new StatsResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->totals = [
            'income' => $totalIncome,
            'bounties' => $bountyTotal,
            'ess' => $essTotal,
            'missions' => $missionTotal,
            'lootSales' => $lootSalesTotal,
            'expenses' => $expensesTotal,
            'profit' => $profit,
        ];
        $resource->expensesByType = $expensesByType;
        $resource->iskPerDay = $iskPerDay;

        return $resource;
    }
}

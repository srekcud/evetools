<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\LedgerDashboardResource;
use App\Entity\MiningEntry;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Entity\UserLedgerSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\MiningBestValueCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LedgerDashboardResource>
 */
class LedgerDashboardProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $pveIncomeRepository,
        private readonly PveExpenseRepository $pveExpenseRepository,
        private readonly UserLedgerSettingsRepository $ledgerSettingsRepository,
        private readonly UserPveSettingsRepository $pveSettingsRepository,
        private readonly MiningBestValueCalculator $miningBestValueCalculator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LedgerDashboardResource
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
        $pveSettings = $this->pveSettingsRepository->findByUser($user);

        $corpProjectAccounting = $ledgerSettings?->getCorpProjectAccounting() ?? UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE;

        // Get PVE income by type
        $pveIncomeByType = $this->pveIncomeRepository->getTotalsByType($user, $from, $to);

        $bountyTotal = $pveIncomeByType[PveIncomeType::Bounty->value] ?? 0.0;
        $essTotal = $pveIncomeByType[PveIncomeType::Ess->value] ?? 0.0;
        $missionTotal = $pveIncomeByType[PveIncomeType::Mission->value] ?? 0.0;
        $lootSalesTotal = ($pveIncomeByType[PveIncomeType::LootSale->value] ?? 0.0)
            + ($pveIncomeByType[PveIncomeType::LootContract->value] ?? 0.0);
        $corpProjectPveTotal = $pveIncomeByType[PveIncomeType::CorpProject->value] ?? 0.0;

        // PVE total: include corp projects if corpProjectAccounting is 'pve'
        $pveTotal = $bountyTotal + $essTotal + $missionTotal + $lootSalesTotal;
        if ($corpProjectAccounting === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE) {
            $pveTotal += $corpProjectPveTotal;
        }

        // Get expenses
        $expensesTotal = $this->pveExpenseRepository->getTotalByUserAndDateRange($user, $from, $to);
        $expensesByType = $this->pveExpenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        // Get mining totals
        /** @var list<string>|null $excludeMiningUsages */
        $excludeMiningUsages = $corpProjectAccounting === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE
            ? [MiningEntry::USAGE_CORP_PROJECT]
            : null;

        $miningValue = $this->miningBestValueCalculator->getTotalBestValue($user, $from, $to, $excludeMiningUsages);
        $miningTotal = $miningValue['totalValue'];
        $miningByUsage = $this->miningBestValueCalculator->getTotalsByUsageBestValue($user, $from, $to);

        $totalIncome = null;
        $profit = null;
        $iskPerDay = null;
        $pvePercent = null;
        $miningPercent = null;
        if ($miningTotal !== null) {
            $totalIncome = $pveTotal + $miningTotal;
            $profit = $totalIncome - $expensesTotal;
            $iskPerDay = $days > 0 ? $profit / $days : 0.0;
            $pvePercent = $totalIncome > 0 ? ($pveTotal / $totalIncome) * 100 : 0.0;
            $miningPercent = $totalIncome > 0 ? ($miningTotal / $totalIncome) * 100 : 0.0;
        }

        // Build resource
        $resource = new LedgerDashboardResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->totals = [
            'total' => $totalIncome,
            'pve' => $pveTotal,
            'mining' => $miningTotal,
            'expenses' => $expensesTotal,
            'profit' => $profit,
        ];
        $resource->pveBreakdown = [
            'bounties' => $bountyTotal,
            'ess' => $essTotal,
            'missions' => $missionTotal,
            'lootSales' => $lootSalesTotal,
            'corpProjects' => $corpProjectAccounting === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE ? $corpProjectPveTotal : 0.0,
        ];
        $resource->miningBreakdown = [
            'sold' => $this->usageValue($miningByUsage, MiningEntry::USAGE_SOLD),
            'corpProject' => $corpProjectAccounting === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING
                ? $this->usageValue($miningByUsage, MiningEntry::USAGE_CORP_PROJECT)
                : 0.0,
            'industry' => $this->usageValue($miningByUsage, MiningEntry::USAGE_INDUSTRY),
            'unknown' => $this->usageValue($miningByUsage, MiningEntry::USAGE_UNKNOWN),
        ];
        $resource->missingPriceTypeIds = $miningValue['missingPriceTypeIds'];
        $resource->expensesByType = $expensesByType;
        $resource->iskPerDay = $iskPerDay;
        $resource->pvePercent = $pvePercent;
        $resource->miningPercent = $miningPercent;
        $resource->lastSync = [
            'pve' => $pveSettings?->getLastSyncAt()?->format('c'),
            'mining' => $ledgerSettings?->getLastMiningSyncAt()?->format('c'),
        ];
        $resource->settings = [
            'corpProjectAccounting' => $corpProjectAccounting,
        ];

        return $resource;
    }

    /**
     * @param array<string, array{totalValue: float|null, totalQuantity: int, missingPriceTypeIds: list<int>}> $miningByUsage
     */
    private function usageValue(array $miningByUsage, string $usage): ?float
    {
        return array_key_exists($usage, $miningByUsage) ? $miningByUsage[$usage]['totalValue'] : 0.0;
    }
}

<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningDailyStatsResource;
use App\ApiResource\Ledger\MiningStatsDailyResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningStatsDailyResource>
 */
class MiningStatsDailyProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly UserLedgerSettingsRepository $settingsRepository,
        private readonly MiningBestValueCalculator $miningBestValueCalculator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningStatsDailyResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Get user settings to determine exclusions
        $settings = $this->settingsRepository->findByUser($user);
        $excludeUsages = $this->getExcludeUsages($settings);

        // Get daily best-price values
        $dailyBestValues = $this->miningBestValueCalculator->getDailyBestValues($user, $from, $to, $excludeUsages);

        // Get daily quantities for display
        $dailyTotals = $this->miningEntryRepository->getDailyTotals($user, $from, $to, $excludeUsages);

        // Fill in missing days with zero values
        $daily = [];
        $current = clone $from;
        while ($current <= $to) {
            $dateStr = $current->format('Y-m-d');
            $dayStats = new MiningDailyStatsResource();
            $dayStats->date = $dateStr;

            $dayStats->totalValue = $dailyBestValues[$dateStr] ?? 0.0;
            if (isset($dailyTotals[$dateStr])) {
                $dayStats->totalQuantity = $dailyTotals[$dateStr]['totalQuantity'];
            }

            $daily[] = $dayStats;
            $current = $current->modify('+1 day');
        }

        $resource = new MiningStatsDailyResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->daily = $daily;

        return $resource;
    }

    /**
     * Determine which usages to exclude based on settings.
     *
     * @return list<string>|null
     */
    private function getExcludeUsages(?UserLedgerSettings $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        if ($settings->getCorpProjectAccounting() === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE) {
            return [MiningEntry::USAGE_CORP_PROJECT];
        }

        return null;
    }
}

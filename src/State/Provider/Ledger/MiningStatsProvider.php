<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningStatsResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningStatsResource>
 */
class MiningStatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly UserLedgerSettingsRepository $settingsRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningStatsResource
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

        // Get totals
        $totalValue = $this->miningEntryRepository->getTotalValueByUserAndDateRange($user, $from, $to, $excludeUsages);
        $totalQuantity = $this->miningEntryRepository->getTotalQuantityByUserAndDateRange($user, $from, $to, $excludeUsages);

        // Get by usage
        $byUsage = $this->miningEntryRepository->getTotalsByUsage($user, $from, $to);

        // Calculate ISK per day
        $iskPerDay = $days > 0 ? $totalValue / $days : 0;

        $resource = new MiningStatsResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->totals = [
            'totalValue' => $totalValue,
            'totalQuantity' => $totalQuantity,
        ];
        $resource->byUsage = $byUsage;
        $resource->iskPerDay = $iskPerDay;

        return $resource;
    }

    /**
     * Determine which usages to exclude based on settings.
     *
     * @return string[]|null
     */
    private function getExcludeUsages(?UserLedgerSettings $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        // If corpProjectAccounting is 'pve', exclude corp_project from mining stats
        if ($settings->getCorpProjectAccounting() === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE) {
            return [MiningEntry::USAGE_CORP_PROJECT];
        }

        return null;
    }
}

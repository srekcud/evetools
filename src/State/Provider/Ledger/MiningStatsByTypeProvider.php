<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningStatsByTypeResource;
use App\ApiResource\Ledger\MiningTypeStatsResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningStatsByTypeResource>
 */
class MiningStatsByTypeProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly UserLedgerSettingsRepository $settingsRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningStatsByTypeResource
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

        // Get totals by type
        $byType = $this->miningEntryRepository->getTotalsByType($user, $from, $to, $excludeUsages);

        // Convert to resource objects
        $typeStats = [];
        foreach ($byType as $data) {
            $stat = new MiningTypeStatsResource();
            $stat->typeId = $data['typeId'];
            $stat->typeName = $data['typeName'];
            $stat->totalValue = $data['totalValue'];
            $stat->totalQuantity = $data['totalQuantity'];
            $typeStats[] = $stat;
        }

        $resource = new MiningStatsByTypeResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->byType = $typeStats;

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

        if ($settings->getCorpProjectAccounting() === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE) {
            return [MiningEntry::USAGE_CORP_PROJECT];
        }

        return null;
    }
}

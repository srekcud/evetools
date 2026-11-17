<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningStatsGridResource;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\Mining\MiningRevenueGridBuilder;
use App\Service\Pve\RequestedDays;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningStatsGridResource>
 */
class MiningStatsGridProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningRevenueGridBuilder $gridBuilder,
        private readonly UserLedgerSettingsRepository $ledgerSettingsRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningStatsGridResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $days = RequestedDays::fromRequest($this->requestStack->getCurrentRequest());
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        $ledgerSettings = $this->ledgerSettingsRepository->findByUser($user);
        $corpProjectCountedInPve = $ledgerSettings?->getCorpProjectAccounting() === UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE;

        $grid = $this->gridBuilder->build($user, $from, $to, $days, $corpProjectCountedInPve);

        $resource = new MiningStatsGridResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->columns = $grid['columns'];
        $resource->net = $grid['net'];
        $resource->missingPriceTypeIds = $grid['missingPriceTypeIds'];
        $resource->corpProjectCountedInPve = $corpProjectCountedInPve;

        return $resource;
    }
}

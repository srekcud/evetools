<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\IncomeResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<IncomeResource>
 */
class IncomeProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): IncomeResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $bountyEntries = $this->incomeRepository->findBountiesByUserAndDateRange($user, $from, $to);
        $totalBounties = $this->incomeRepository->getTotalBountiesByUserAndDateRange($user, $from, $to);

        $bounties = array_map(fn(PveIncome $i) => [
            'id' => $i->getJournalEntryId() ?? $i->getId()?->toRfc4122(),
            'date' => $i->getDate()->format('c'),
            'refType' => $i->getType(),
            'refTypeLabel' => match ($i->getType()) {
                PveIncome::TYPE_BOUNTY => 'Bounty',
                PveIncome::TYPE_ESS => 'ESS',
                PveIncome::TYPE_MISSION => 'Mission',
                default => $i->getType(),
            },
            'amount' => $i->getAmount(),
            'description' => $i->getDescription(),
            'characterName' => $this->extractCharacterName($i->getDescription()),
        ], $bountyEntries);

        $totalExpenses = $this->expenseRepository->getTotalByUserAndDateRange($user, $from, $to);
        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        $lootSalesList = $this->incomeRepository->findLootSalesByUserAndDateRange($user, $from, $to);
        $totalLootSales = $this->incomeRepository->getTotalLootSalesByUserAndDateRange($user, $from, $to);

        $settings = $this->settingsRepository->findByUser($user);
        $lastSyncAt = $settings?->getLastSyncAt();

        $resource = new IncomeResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->lastSyncAt = $lastSyncAt?->format('c');
        $resource->bounties = [
            'total' => $totalBounties,
            'count' => count($bountyEntries),
            'entries' => $bounties,
        ];
        $resource->lootSales = [
            'total' => $totalLootSales,
            'count' => count($lootSalesList),
            'entries' => array_map(fn(PveIncome $i) => [
                'id' => $i->getId()?->toRfc4122(),
                'type' => $i->getType(),
                'description' => $i->getDescription(),
                'amount' => $i->getAmount(),
                'date' => $i->getDate()->format('Y-m-d'),
            ], $lootSalesList),
        ];
        $resource->expenses = [
            'total' => $totalExpenses,
            'byType' => $expensesByType,
        ];
        $resource->profit = $totalBounties + $totalLootSales - $totalExpenses;

        return $resource;
    }

    private function extractCharacterName(string $description): string
    {
        $parts = explode(' - ', $description);

        return $parts[1] ?? $parts[0];
    }
}

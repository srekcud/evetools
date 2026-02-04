<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\StatsByTypeResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StatsByTypeResource>
 */
class StatsByTypeProvider implements ProviderInterface
{
    private const BOUNTY_REF_TYPES = [
        'bounty_prizes',
        'ess_escrow_transfer',
        'agent_mission_reward',
        'agent_mission_time_bonus_reward',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly UserLedgerSettingsRepository $ledgerSettingsRepository,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StatsByTypeResource
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

        $incomeByType = $this->incomeRepository->getTotalsByType($user, $from, $to);

        // Remove corp_project from the results if it should be excluded
        if ($excludeCorpProject && isset($incomeByType[PveIncome::TYPE_CORP_PROJECT])) {
            unset($incomeByType[PveIncome::TYPE_CORP_PROJECT]);
        }

        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        $bountyTotal = 0.0;
        $essTotal = 0.0;
        $missionTotal = 0.0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $journal = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/journal/",
                    $token
                );

                foreach ($journal as $entry) {
                    $refType = $entry['ref_type'] ?? '';
                    if (!in_array($refType, self::BOUNTY_REF_TYPES, true)) {
                        continue;
                    }

                    $entryDate = new \DateTimeImmutable($entry['date']);
                    if ($entryDate < $from || $entryDate > $to) {
                        continue;
                    }

                    $amount = (float) ($entry['amount'] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }

                    match ($refType) {
                        'bounty_prizes' => $bountyTotal += $amount,
                        'ess_escrow_transfer' => $essTotal += $amount,
                        'agent_mission_reward', 'agent_mission_time_bonus_reward' => $missionTotal += $amount,
                        default => null,
                    };
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $resource = new StatsByTypeResource();
        $resource->period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $days,
        ];
        $resource->income = [
            'bounty' => $bountyTotal,
            'ess' => $essTotal,
            'mission' => $missionTotal,
            ...$incomeByType,
        ];
        $resource->expenses = $expensesByType;

        return $resource;
    }
}

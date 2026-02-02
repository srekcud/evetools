<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Pve\DetectedExpenseResource;
use App\ApiResource\Pve\ScanContractsResultResource;
use App\Entity\PveExpense;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ScanContractsResultResource>
 */
class ScanContractsProcessor implements ProcessorInterface
{
    private const BEACON_TYPE_IDS = [
        60244, // CONCORD Rogue Analysis Beacon
    ];

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanContractsResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->getOrCreate($user);
        $ammoTypeIds = $settings->getAmmoTypeIds();

        $importedContractIds = $this->expenseRepository->getImportedContractIds($user);
        $importedTransactionIds = $this->expenseRepository->getImportedTransactionIds($user);
        $declinedContractIds = $settings->getDeclinedContractIds();
        $declinedTransactionIds = $settings->getDeclinedTransactionIds();

        $detectedExpenses = [];
        $scannedContracts = 0;
        $scannedTransactions = 0;

        // Scan contracts
        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $contracts = $this->esiClient->getPaginated(
                    "/characters/{$character->getEveCharacterId()}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    if (in_array($contract['contract_id'], $importedContractIds, true)) {
                        continue;
                    }
                    if (in_array($contract['contract_id'], $declinedContractIds, true)) {
                        continue;
                    }

                    if ($contract['type'] !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }

                    $isAcceptor = ($contract['acceptor_id'] ?? 0) === $character->getEveCharacterId();
                    $isIssuer = ($contract['issuer_id'] ?? 0) === $character->getEveCharacterId();

                    if (!$isAcceptor && !$isIssuer) {
                        continue;
                    }

                    $contractDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_accepted'] ?? $contract['date_issued']);
                    if ($contractDate < $from) {
                        continue;
                    }

                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$character->getEveCharacterId()}/contracts/{$contract['contract_id']}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    $beaconItems = [];
                    $ammoItems = [];

                    foreach ($items as $item) {
                        $isIncluded = $item['is_included'] ?? true;
                        $weReceivedItem = ($isAcceptor && $isIncluded) || ($isIssuer && !$isIncluded);

                        if (!$weReceivedItem) {
                            continue;
                        }

                        $typeId = $item['type_id'];
                        $quantity = $item['quantity'] ?? 1;
                        $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                        if (in_array($typeId, self::BEACON_TYPE_IDS, true)) {
                            $beaconItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                        } elseif (in_array($typeId, $ammoTypeIds, true)) {
                            $ammoItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                        }
                    }

                    $price = (float) ($contract['price'] ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    $type = null;
                    $allItems = [];

                    if (!empty($beaconItems)) {
                        $type = PveExpense::TYPE_CRAB_BEACON;
                        $allItems = $beaconItems;
                    }
                    if (!empty($ammoItems)) {
                        $type = $type ?? PveExpense::TYPE_AMMO;
                        $allItems = array_merge($allItems, $ammoItems);
                    }

                    if ($type === null || empty($allItems)) {
                        continue;
                    }

                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $allItems
                    ));

                    $expense = new DetectedExpenseResource();
                    $expense->contractId = $contract['contract_id'];
                    $expense->transactionId = 0;
                    $expense->type = $type;
                    $expense->typeId = $allItems[0]['typeId'];
                    $expense->typeName = $description;
                    $expense->quantity = array_sum(array_column($allItems, 'quantity'));
                    $expense->price = $price;
                    $expense->dateIssued = $contractDate->format('Y-m-d');
                    $expense->source = 'contract';

                    $detectedExpenses[] = $expense;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Scan market transactions
        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $transactions = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/transactions/",
                    $token
                );

                foreach ($transactions as $transaction) {
                    $scannedTransactions++;

                    if (in_array($transaction['transaction_id'], $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($transaction['transaction_id'], $declinedTransactionIds, true)) {
                        continue;
                    }

                    if (!($transaction['is_buy'] ?? false)) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $typeId = $transaction['type_id'];
                    $quantity = $transaction['quantity'] ?? 1;
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalPrice = $quantity * $unitPrice;

                    if ($totalPrice <= 0) {
                        continue;
                    }

                    $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                    $type = match (true) {
                        in_array($typeId, self::BEACON_TYPE_IDS, true) => PveExpense::TYPE_CRAB_BEACON,
                        in_array($typeId, $ammoTypeIds, true) => PveExpense::TYPE_AMMO,
                        default => null,
                    };

                    if ($type !== null) {
                        $expense = new DetectedExpenseResource();
                        $expense->contractId = 0;
                        $expense->transactionId = $transaction['transaction_id'];
                        $expense->type = $type;
                        $expense->typeId = $typeId;
                        $expense->typeName = "{$quantity}x {$typeName}";
                        $expense->quantity = $quantity;
                        $expense->price = $totalPrice;
                        $expense->dateIssued = $transactionDate->format('Y-m-d');
                        $expense->source = 'market';

                        $detectedExpenses[] = $expense;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $resource = new ScanContractsResultResource();
        $resource->scannedContracts = $scannedContracts;
        $resource->scannedTransactions = $scannedTransactions;
        $resource->detectedExpenses = $detectedExpenses;

        return $resource;
    }
}

<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Pve\CorporationProjectErrorResource;
use App\ApiResource\Pve\DetectedSaleResource;
use App\ApiResource\Pve\ScanLootSalesResultResource;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Pve\CorpProjectContribution;
use App\Service\Pve\CorpProjectContributionReader;
use App\Service\Pve\CorporationProjectReadError;
use App\Service\UnknownTypeName;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ScanLootSalesResultResource>
 */
class ScanLootSalesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly CorpProjectContributionReader $corpProjectContributionReader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanLootSalesResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->getOrCreate($user);
        $lootTypeIds = $settings->getLootTypeIds();

        $resource = new ScanLootSalesResultResource();

        $importedTransactionIds = $this->incomeRepository->getImportedTransactionIds($user);
        $declinedTransactionIds = $settings->getDeclinedLootSaleTransactionIds();

        $detectedSales = [];
        $scannedTransactions = 0;
        $scannedContracts = 0;

        $charactersReadForLootSales = $lootTypeIds === [] ? [] : $user->getCharacters();

        // Scan market transactions
        foreach ($charactersReadForLootSales as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                $this->tokenManager->getValidAccessToken($token);

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

                    if ($transaction['is_buy'] ?? true) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $typeId = $transaction['type_id'];
                    if (!in_array($typeId, $lootTypeIds, true)) {
                        continue;
                    }

                    $quantity = $transaction['quantity'] ?? 1;
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalPrice = $quantity * $unitPrice;

                    if ($totalPrice <= 0) {
                        continue;
                    }

                    $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? UnknownTypeName::label($typeId);

                    $sale = new DetectedSaleResource();
                    $sale->transactionId = $transaction['transaction_id'];
                    $sale->contractId = 0;
                    $sale->type = PveIncomeType::LootSale->value;
                    $sale->typeId = $typeId;
                    $sale->typeName = "{$quantity}x {$typeName}";
                    $sale->quantity = $quantity;
                    $sale->price = $totalPrice;
                    $sale->dateIssued = $transactionDate->format('Y-m-d');
                    $sale->characterName = $character->getName();
                    $sale->source = 'market';

                    $detectedSales[] = $sale;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Scan contracts for loot sales
        foreach ($charactersReadForLootSales as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                $this->tokenManager->getValidAccessToken($token);

                $contracts = $this->esiClient->getPaginated(
                    "/characters/{$character->getEveCharacterId()}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

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

                    $price = (float) ($contract['price'] ?? 0);
                    $reward = (float) ($contract['reward'] ?? 0);

                    $iskReceived = 0;
                    if ($isIssuer && $price > 0) {
                        $iskReceived = $price;
                    } elseif ($isAcceptor && $reward > 0) {
                        $iskReceived = $reward;
                    }

                    if ($iskReceived <= 0) {
                        continue;
                    }

                    $trackingId = -(int) $contract['contract_id'];

                    if (in_array($trackingId, $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($trackingId, $declinedTransactionIds, true)) {
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

                    $soldLootItems = [];

                    foreach ($items as $item) {
                        $isIncluded = $item['is_included'] ?? true;
                        $weGaveItem = ($isIssuer && $isIncluded) || ($isAcceptor && !$isIncluded);

                        if (!$weGaveItem) {
                            continue;
                        }

                        $typeId = $item['type_id'];
                        if (!in_array($typeId, $lootTypeIds, true)) {
                            continue;
                        }

                        $quantity = $item['quantity'] ?? 1;
                        $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? UnknownTypeName::label($typeId);

                        $soldLootItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                    }

                    if (empty($soldLootItems)) {
                        continue;
                    }

                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $soldLootItems
                    ));

                    $firstItem = $soldLootItems[0];

                    $sale = new DetectedSaleResource();
                    $sale->transactionId = $trackingId;
                    $sale->contractId = $contract['contract_id'];
                    $sale->type = PveIncomeType::LootSale->value;
                    $sale->typeId = $firstItem['typeId'];
                    $sale->typeName = $description;
                    $sale->quantity = array_sum(array_column($soldLootItems, 'quantity'));
                    $sale->price = $iskReceived;
                    $sale->dateIssued = $contractDate->format('Y-m-d');
                    $sale->characterName = $character->getName();
                    $sale->source = 'contract';

                    $detectedSales[] = $sale;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $corpProjectReading = $this->corpProjectContributionReader->read($user, $from);

        foreach ($corpProjectReading->contributions as $contribution) {
            $typeName = $contribution->typeId > 0
                ? ($this->invTypeRepository->find($contribution->typeId)?->getTypeName() ?? UnknownTypeName::label($contribution->typeId))
                : CorpProjectContribution::UNTYPED_LABEL;

            $sale = new DetectedSaleResource();
            $sale->transactionId = $contribution->trackingId();
            $sale->contractId = 0;
            $sale->projectId = $contribution->projectId;
            $sale->type = PveIncomeType::CorpProject->value;
            $sale->typeId = $contribution->typeId;
            $sale->typeName = "{$contribution->pendingQuantity()}x {$typeName}";
            $sale->quantity = $contribution->pendingQuantity();
            $sale->price = $contribution->pendingValue();
            $sale->dateIssued = $contribution->contributedAt->format('Y-m-d');
            $sale->characterName = $contribution->characterName;
            $sale->characterId = $contribution->characterId;
            $sale->accountedQuantity = $contribution->accountedQuantity;
            $sale->source = 'corp_project';
            $sale->projectName = $contribution->projectName;

            $detectedSales[] = $sale;
        }

        $resource->scannedTransactions = $lootTypeIds === [] ? null : $scannedTransactions;
        $resource->scannedContracts = $lootTypeIds === [] ? null : $scannedContracts;
        $resource->scannedProjects = $corpProjectReading->scannedProjects;
        $resource->detectedSales = $detectedSales;
        $resource->noLootTypesConfigured = $lootTypeIds === [];
        $resource->charactersToReconnect = $this->namesOfCharactersToReconnect($user, $corpProjectReading->refusedCharacterIds);
        $resource->corporationProjectErrors = array_map(
            static function (CorporationProjectReadError $error): CorporationProjectErrorResource {
                $errorResource = new CorporationProjectErrorResource();
                $errorResource->corporationName = $error->corporationName;
                $errorResource->projectName = $error->projectName;
                $errorResource->characterName = $error->characterName;
                $errorResource->reason = $error->reason->value;
                $errorResource->httpStatus = $error->httpStatus;

                return $errorResource;
            },
            $corpProjectReading->errors,
        );

        $this->logger->info('Loot sale scan finished', [
            'detectedSales' => count($detectedSales),
            'scannedProjects' => $resource->scannedProjects,
            'projectErrors' => count($resource->corporationProjectErrors),
        ]);

        return $resource;
    }

    /**
     * @param array<int, true> $refusedCharacterIds
     *
     * @return list<string>
     */
    private function namesOfCharactersToReconnect(User $user, array $refusedCharacterIds): array
    {
        $names = [];
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null && (!$character->isAuthValid() || isset($refusedCharacterIds[$character->getEveCharacterId()]))) {
                $names[] = $character->getName();
            }
        }

        return $names;
    }
}

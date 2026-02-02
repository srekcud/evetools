<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Pve\DetectedLootContractResource;
use App\ApiResource\Pve\ScanLootContractsResultResource;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ScanLootContractsResultResource>
 */
class ScanLootContractsProcessor implements ProcessorInterface
{
    private const MAX_LOOT_CONTRACTS_PER_SCAN = 50;

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanLootContractsResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->findByUser($user);
        $userLootTypeIds = $settings?->getLootTypeIds() ?? [];
        $pveLootTypeIds = array_unique(array_merge(UserPveSettings::PVE_LOOT_TYPE_IDS, $userLootTypeIds));
        $defaultPricePerItem = UserPveSettings::PVE_LOOT_DEFAULT_PRICE_PER_ITEM;

        $importedContractIds = $this->incomeRepository->getImportedContractIds($user);
        $declinedContractIds = $settings?->getDeclinedContractIds() ?? [];

        $detectedContracts = [];
        $scannedContracts = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $characterId = $character->getEveCharacterId();

                $contracts = $this->esiClient->get(
                    "/characters/{$characterId}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    if (count($detectedContracts) >= self::MAX_LOOT_CONTRACTS_PER_SCAN) {
                        break;
                    }

                    $contractId = (int) $contract['contract_id'];

                    if (in_array($contractId, $importedContractIds, true)) {
                        continue;
                    }
                    if (in_array($contractId, $declinedContractIds, true)) {
                        continue;
                    }

                    if (($contract['type'] ?? '') !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }
                    if (($contract['issuer_id'] ?? 0) !== $characterId) {
                        continue;
                    }

                    $contractPrice = (float) ($contract['price'] ?? 0);

                    $completedDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_issued']);
                    if ($completedDate < $from) {
                        continue;
                    }

                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$characterId}/contracts/{$contractId}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    $lootItems = [];
                    $totalQuantity = 0;

                    foreach ($items as $item) {
                        $typeId = $item['type_id'] ?? 0;
                        $isIncluded = $item['is_included'] ?? false;
                        $quantity = (int) ($item['quantity'] ?? 1);

                        if ($isIncluded && in_array($typeId, $pveLootTypeIds, true)) {
                            $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";
                            $lootItems[] = [
                                'typeId' => $typeId,
                                'typeName' => $typeName,
                                'quantity' => $quantity,
                            ];
                            $totalQuantity += $quantity;
                        }
                    }

                    if (empty($lootItems)) {
                        continue;
                    }

                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $lootItems
                    ));

                    $detected = new DetectedLootContractResource();
                    $detected->contractId = $contractId;
                    $detected->description = $description;
                    $detected->items = $lootItems;
                    $detected->totalQuantity = $totalQuantity;
                    $detected->contractPrice = $contractPrice;
                    $detected->suggestedPrice = $contractPrice > 0 ? $contractPrice : $totalQuantity * $defaultPricePerItem;
                    $detected->date = $completedDate->format('Y-m-d');
                    $detected->characterName = $character->getName();

                    $detectedContracts[] = $detected;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $resource = new ScanLootContractsResultResource();
        $resource->scannedContracts = $scannedContracts;
        $resource->detectedContracts = $detectedContracts;
        $resource->defaultPricePerItem = $defaultPricePerItem;
        $resource->hasMore = count($detectedContracts) >= self::MAX_LOOT_CONTRACTS_PER_SCAN;
        $resource->maxPerScan = self::MAX_LOOT_CONTRACTS_PER_SCAN;

        return $resource;
    }
}

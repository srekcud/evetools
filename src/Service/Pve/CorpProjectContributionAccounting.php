<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;

final readonly class CorpProjectContributionAccounting
{
    public function __construct(
        private PveIncomeRepository $incomeRepository,
        private UserPveSettingsRepository $settingsRepository,
    ) {
    }

    public function accountedQuantitiesOf(User $user): CorpProjectAccountedQuantities
    {
        $importedQuantities = [];
        $legacyQuantities = [];

        foreach ($this->incomeRepository->findCorpProjectImports($user) as $import) {
            $quantity = $import['corpProjectQuantity'];

            if ($import['corpProjectId'] !== null && $import['corpProjectCharacterId'] !== null && $quantity !== null) {
                $pairKey = CorpProjectAccountedQuantities::pairKey($import['corpProjectId'], $import['corpProjectCharacterId']);
                $importedQuantities[$pairKey] = (array_key_exists($pairKey, $importedQuantities) ? $importedQuantities[$pairKey] : 0) + $quantity;
                continue;
            }

            $legacyTransactionId = $import['transactionId'];
            if ($import['corpProjectId'] === null && $legacyTransactionId !== null) {
                $previousQuantity = array_key_exists($legacyTransactionId, $legacyQuantities) ? $legacyQuantities[$legacyTransactionId] : 0;
                $legacyQuantities[$legacyTransactionId] = $previousQuantity === null || $quantity === null ? null : $previousQuantity + $quantity;
            }
        }

        return new CorpProjectAccountedQuantities(
            $importedQuantities,
            $legacyQuantities,
            $this->settingsRepository->getOrCreate($user),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;

final readonly class CorpProjectContributionAccounting
{
    public function __construct(
        private PveIncomeRepository $incomeRepository,
        private UserPveSettingsRepository $settingsRepository,
    ) {
    }

    public function accountedQuantitiesOf(User $user, ?UserPveSettings $settings = null): CorpProjectAccountedQuantities
    {
        $accountedQuantities = new CorpProjectAccountedQuantities($settings ?? $this->settingsRepository->getOrCreate($user));

        foreach ($this->incomeRepository->findCorpProjectImports($user) as $import) {
            $quantity = $import['corpProjectQuantity'];

            if ($import['corpProjectId'] !== null && $import['corpProjectCharacterId'] !== null && $quantity !== null) {
                $accountedQuantities->recordImportedQuantity($import['corpProjectId'], $import['corpProjectCharacterId'], $quantity);
            } elseif ($import['corpProjectId'] === null && $import['transactionId'] !== null) {
                $accountedQuantities->recordLegacyQuantity($import['transactionId'], $quantity);
            }
        }

        return $accountedQuantities;
    }
}

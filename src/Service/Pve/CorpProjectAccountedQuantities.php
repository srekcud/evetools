<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\UserPveSettings;

final readonly class CorpProjectAccountedQuantities
{
    /**
     * @param array<string, int> $importedQuantities
     * @param array<int, ?int> $legacyQuantities
     */
    public function __construct(
        private array $importedQuantities,
        private array $legacyQuantities,
        private UserPveSettings $settings,
    ) {
    }

    public static function pairKey(string $projectId, int $characterId): string
    {
        return "{$projectId}:{$characterId}";
    }

    public static function legacyTransactionId(string $projectId, int $characterId): int
    {
        return -abs(crc32("{$projectId}_{$characterId}"));
    }

    public function quantityOf(string $projectId, int $characterId): ?int
    {
        $legacyTransactionId = self::legacyTransactionId($projectId, $characterId);
        $legacyQuantity = array_key_exists($legacyTransactionId, $this->legacyQuantities) ? $this->legacyQuantities[$legacyTransactionId] : 0;
        if ($legacyQuantity === null) {
            return null;
        }

        $pairKey = self::pairKey($projectId, $characterId);
        $importedQuantity = array_key_exists($pairKey, $this->importedQuantities) ? $this->importedQuantities[$pairKey] : 0;

        return $importedQuantity + $this->settings->declinedCorpProjectQuantityOf($projectId, $characterId) + $legacyQuantity;
    }

    public function isLegacyIdentifierDeclined(string $projectId, int $characterId): bool
    {
        return in_array(self::legacyTransactionId($projectId, $characterId), $this->settings->getDeclinedLootSaleTransactionIds(), true);
    }
}

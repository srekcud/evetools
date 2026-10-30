<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\UserPveSettings;

final class CorpProjectAccountedQuantities
{
    /** @var array<string, int> */
    private array $importedQuantities = [];

    /** @var array<int, ?int> */
    private array $legacyQuantities = [];

    public function __construct(private readonly UserPveSettings $settings)
    {
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

    public function recordImportedQuantity(string $projectId, int $characterId, int $quantity): void
    {
        $pairKey = self::pairKey($projectId, $characterId);
        $this->importedQuantities[$pairKey] = (array_key_exists($pairKey, $this->importedQuantities) ? $this->importedQuantities[$pairKey] : 0) + $quantity;
    }

    public function recordLegacyQuantity(int $legacyTransactionId, ?int $quantity): void
    {
        $previousQuantity = array_key_exists($legacyTransactionId, $this->legacyQuantities) ? $this->legacyQuantities[$legacyTransactionId] : 0;
        $this->legacyQuantities[$legacyTransactionId] = $previousQuantity === null || $quantity === null ? null : $previousQuantity + $quantity;
    }

    public function isLegacyIdentifierDeclined(string $projectId, int $characterId): bool
    {
        return in_array(self::legacyTransactionId($projectId, $characterId), $this->settings->getDeclinedLootSaleTransactionIds(), true);
    }
}

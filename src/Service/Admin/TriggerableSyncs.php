<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Message\CheckAlertPrices;
use App\Message\CheckCourierContracts;
use App\Message\CheckMiningSiteDepletionAlerts;
use App\Message\CheckMoonChunkAlerts;
use App\Message\PurgeExpiredSharedLists;
use App\Message\PurgeOldMarketHistory;
use App\Message\PurgeOldNotifications;
use App\Message\SyncAdjustedPrices;
use App\Message\SyncBlueprints;
use App\Message\SyncCharacterOrders;
use App\Message\SyncCostIndices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPublicContracts;
use App\Message\SyncRegionalMarketVolumes;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerAssetsSync;
use App\Message\TriggerCorporationMiningExtractionsSync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningOperationRecurrences;
use App\Message\TriggerMiningSync;
use App\Message\TriggerMoonTaxSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;

final class TriggerableSyncs
{
    public const SYNCHRONOUS_TYPE = 'market-alerts';

    private const MESSAGE_CLASSES_BY_TYPE = [
        'industry' => [SyncIndustryJobs::class],
        'pve' => [TriggerPveSync::class],
        'wallet' => [SyncWalletTransactions::class],
        'mining' => [TriggerMiningSync::class],
        'ansiblex' => [TriggerAnsiblexSync::class],
        'planetary' => [TriggerPlanetarySync::class],
        'market-jita' => [TriggerJitaMarketSync::class],
        'market-structure' => [TriggerStructureMarketSync::class],
        'alert-prices' => [CheckAlertPrices::class],
        'adjusted-prices' => [SyncAdjustedPrices::class],
        'cost-indices' => [SyncCostIndices::class],
        'public-contracts' => [SyncPublicContracts::class],
        'corporation-mining-extractions' => [TriggerCorporationMiningExtractionsSync::class],
        'moon-tax' => [TriggerMoonTaxSync::class],
        'blueprints' => [SyncBlueprints::class],
        'market-volumes' => [SyncRegionalMarketVolumes::class],
        'courier-contracts' => [CheckCourierContracts::class],
        'character-orders' => [SyncCharacterOrders::class],
        'mining-operation-recurrences' => [TriggerMiningOperationRecurrences::class],
        'moon-chunk-alerts' => [CheckMoonChunkAlerts::class],
        'mining-site-depletion-alerts' => [CheckMiningSiteDepletionAlerts::class],
        'notifications-purge' => [PurgeOldNotifications::class],
        'market-history-purge' => [PurgeOldMarketHistory::class],
        'shared-lists-purge' => [PurgeExpiredSharedLists::class],
        'assets' => [TriggerAssetsSync::class],
        self::SYNCHRONOUS_TYPE => [],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::MESSAGE_CLASSES_BY_TYPE);
    }

    public static function isTriggerable(string $type): bool
    {
        return array_key_exists($type, self::MESSAGE_CLASSES_BY_TYPE);
    }

    /** @return list<object> */
    public static function messagesFor(string $type): array
    {
        return array_map(
            static fn (string $messageClass): object => new $messageClass(),
            self::MESSAGE_CLASSES_BY_TYPE[$type] ?? [],
        );
    }
}

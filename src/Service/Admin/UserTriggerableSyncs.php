<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Message\SyncUserBlueprints;
use App\Message\SyncUserCharacterOrders;
use App\Message\SyncUserIndustryJobs;
use App\Message\SyncUserMiningData;
use App\Message\SyncUserPveData;

final class UserTriggerableSyncs
{
    public const TYPES = ['industry', 'blueprints', 'character-orders', 'pve', 'mining', 'assets', 'wallet', 'planetary'];

    private const MESSAGE_CLASSES_BY_TYPE = [
        'industry' => SyncUserIndustryJobs::class,
        'blueprints' => SyncUserBlueprints::class,
        'character-orders' => SyncUserCharacterOrders::class,
        'pve' => SyncUserPveData::class,
        'mining' => SyncUserMiningData::class,
    ];

    public static function isTriggerable(string $type): bool
    {
        return array_key_exists($type, self::MESSAGE_CLASSES_BY_TYPE);
    }

    public static function messageFor(string $type, string $userId): object
    {
        $messageClass = self::MESSAGE_CLASSES_BY_TYPE[$type]
            ?? throw new \InvalidArgumentException(sprintf('No user sync message for type "%s"', $type));

        return new $messageClass($userId);
    }
}

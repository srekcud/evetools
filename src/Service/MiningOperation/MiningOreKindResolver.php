<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningCompressorKind;

final class MiningOreKindResolver
{
    private const int ASTEROID_CATEGORY_ID = 25;
    private const int ICE_GROUP_ID = 465;
    private const int MERCOXIT_GROUP_ID = 468;
    private const int GAS_CLOUD_GROUP_ID = 711;
    private const array MOON_ORE_GROUP_IDS = [1884, 1920, 1921, 1922, 1923];

    public function kindOf(?int $categoryId, ?int $groupId): ?MiningCompressorKind
    {
        if (null === $groupId) {
            return null;
        }

        if (in_array($groupId, self::MOON_ORE_GROUP_IDS, true)) {
            return MiningCompressorKind::Moon;
        }

        return match (true) {
            self::ICE_GROUP_ID === $groupId => MiningCompressorKind::Ice,
            self::MERCOXIT_GROUP_ID === $groupId => MiningCompressorKind::Mercoxit,
            self::GAS_CLOUD_GROUP_ID === $groupId => MiningCompressorKind::Gas,
            self::ASTEROID_CATEGORY_ID === $categoryId => MiningCompressorKind::Asteroid,
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningCompressorKind;

final class MiningOreKindResolver
{
    public function kindOf(?int $categoryId, ?int $groupId): ?MiningCompressorKind
    {
        throw new \LogicException('not implemented');
    }
}

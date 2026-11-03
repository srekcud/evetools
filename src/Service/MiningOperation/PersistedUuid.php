<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use Symfony\Component\Uid\Uuid;

final class PersistedUuid
{
    public static function of(?Uuid $uuid): string
    {
        if ($uuid === null) {
            throw new \LogicException('The entity has no identifier yet: it must be persisted first');
        }

        return $uuid->toRfc4122();
    }
}

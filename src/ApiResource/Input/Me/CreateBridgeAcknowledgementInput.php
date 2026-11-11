<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Me;

use App\Enum\BridgeTodoKey;
use Symfony\Component\Validator\Constraints as Assert;

class CreateBridgeAcknowledgementInput
{
    public const int MAX_COUNT = 2_147_483_647;

    #[Assert\NotNull]
    #[Assert\Choice(callback: [self::class, 'acknowledgeableKeys'])]
    public string $itemKey;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: self::MAX_COUNT)]
    public int $count;

    /**
     * @return list<string>
     */
    public static function acknowledgeableKeys(): array
    {
        return array_map(static fn (BridgeTodoKey $key): string => $key->value, BridgeTodoKey::cases());
    }
}

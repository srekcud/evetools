<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;

/**
 * An item bought for the production: its quantity always computed, its cost unknown without market price (spec R10).
 */
final readonly class LeafCost
{
    public function __construct(public int $typeId, public float $quantity, public ?Isk $unitPrice, public Cost $cost)
    {
    }
}

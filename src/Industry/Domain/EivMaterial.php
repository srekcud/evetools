<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * One material of the EIV: its SDE base quantity per run (before ME and bonuses) and its adjusted price, null when absent.
 */
final readonly class EivMaterial
{
    public function __construct(public int $typeId, public Quantity $baseQuantityPerRun, public ?Isk $adjustedPrice)
    {
    }
}

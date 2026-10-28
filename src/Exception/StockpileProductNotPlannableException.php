<?php

declare(strict_types=1);

namespace App\Exception;

final class StockpileProductNotPlannableException extends \RuntimeException
{
    public static function noBlueprint(int $productTypeId): self
    {
        return new self();
    }

    public static function planFailed(int $productTypeId, \DomainException $previous): self
    {
        return new self();
    }

    public function getReason(): string
    {
        return '';
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(422)]
final class StockpileProductNotPlannableException extends \RuntimeException
{
    private const string REASON_NO_BLUEPRINT = 'no_blueprint';
    private const string REASON_PLAN_FAILED = 'plan_failed';

    private function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function noBlueprint(int $productTypeId): self
    {
        return new self(self::REASON_NO_BLUEPRINT, "Type {$productTypeId} has no blueprint nor reaction");
    }

    public static function planFailed(int $productTypeId, \DomainException $previous): self
    {
        return new self(self::REASON_PLAN_FAILED, "Type {$productTypeId} cannot be planned", $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

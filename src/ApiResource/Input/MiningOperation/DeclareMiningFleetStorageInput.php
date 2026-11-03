<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use App\Enum\MiningFleetStorageDelivery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DeclareMiningFleetStorageInput
{
    private const int MAX_QUANTITY_LINES = 50;
    private const int MAX_QUANTITY = 1_000_000_000;
    private const int MAX_TYPE_ID = 2_147_483_647;

    #[Assert\NotNull]
    #[Assert\Choice(choices: ['none', 'all', 'partial'])]
    public ?string $delivery = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $quantities = null;

    #[Assert\Callback]
    public function validateQuantities(ExecutionContextInterface $context): void
    {
        $quantities = $this->quantities ?? [];
        if ($this->delivery !== MiningFleetStorageDelivery::Partial->value) {
            if ($quantities !== []) {
                $context->buildViolation('Quantities are only declared for a partial delivery')->atPath('quantities')->addViolation();
            }

            return;
        }

        if ($quantities === [] || count($quantities) > self::MAX_QUANTITY_LINES) {
            $context->buildViolation('A partial delivery declares between 1 and 50 lines')->atPath('quantities')->addViolation();

            return;
        }

        $typeIds = [];
        foreach ($quantities as $line) {
            $typeId = self::positiveIntegerOf($line, 'typeId', self::MAX_TYPE_ID);
            if ($typeId === null || self::positiveIntegerOf($line, 'quantity', self::MAX_QUANTITY) === null || isset($typeIds[$typeId])) {
                $context->buildViolation('Each line needs a distinct positive typeId and a positive quantity')->atPath('quantities')->addViolation();

                return;
            }
            $typeIds[$typeId] = true;
        }
    }

    private static function positiveIntegerOf(mixed $line, string $field, int $upperBound): ?int
    {
        $value = is_array($line) ? ($line[$field] ?? null) : null;

        return is_int($value) && $value > 0 && $value <= $upperBound ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Transfer;

final readonly class TransferLine
{
    public function __construct(
        public int $typeId,
        public int $quantity,
        public ?int $departureBaseline,
        public ?int $arrivalBaseline,
    ) {
    }

    /**
     * @return array{typeId: int, quantity: int, departureBaseline: int|null, arrivalBaseline: int|null}
     */
    public function toArray(): array
    {
        return [
            'typeId' => $this->typeId,
            'quantity' => $this->quantity,
            'departureBaseline' => $this->departureBaseline,
            'arrivalBaseline' => $this->arrivalBaseline,
        ];
    }

    /**
     * @param array{typeId: int, quantity: int, departureBaseline: int|null, arrivalBaseline: int|null} $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['typeId'], $row['quantity'], $row['departureBaseline'], $row['arrivalBaseline']);
    }
}

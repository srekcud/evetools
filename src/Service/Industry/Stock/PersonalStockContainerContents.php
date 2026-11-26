<?php

declare(strict_types=1);

namespace App\Service\Industry\Stock;

final readonly class PersonalStockContainerContents
{
    /**
     * @param array<int, int> $articles typeId => quantity
     */
    public function __construct(
        public PersonalStockContainerReading $reading,
        public array $articles,
    ) {
    }
}

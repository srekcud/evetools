<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

final readonly class FactoryProjectStockLine
{
    public const string RESERVED = 'reserved';
    public const string CONSUMED = 'consumed';
    public const string RETURNED = 'returned';

    public function __construct(
        public string $projectId,
        public ?string $projectName,
        public string $projectStatus,
        public int $typeId,
        public string $typeName,
        public string $state,
        public int $quantity,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

final readonly class FactoryStationProject
{
    public function __construct(
        public string $id,
        public ?string $name,
    ) {
    }
}

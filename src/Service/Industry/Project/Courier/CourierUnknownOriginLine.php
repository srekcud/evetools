<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierUnknownOriginLine
{
    public function __construct(
        public int $typeId,
        public int $quantity,
    ) {
    }
}

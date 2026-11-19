<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierOtherOriginLine
{
    public function __construct(
        public int $solarSystemId,
        public ?string $solarSystemName,
        public int $typeId,
        public int $quantity,
    ) {
    }
}

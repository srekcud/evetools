<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final readonly class HaulLegsBuilder
{
    public function build(HaulReadings $readings): HaulLegs
    {
        return new HaulLegs([], [], null);
    }
}

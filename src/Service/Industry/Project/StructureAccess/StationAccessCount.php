<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Service\Industry\Project\Action\ActionPlace;

final readonly class StationAccessCount
{
    public function __construct(
        public ActionPlace $station,
        public int $canLaunch,
        public int $denied,
        public int $unknown,
    ) {
    }
}

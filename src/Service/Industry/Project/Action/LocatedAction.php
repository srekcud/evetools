<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

final readonly class LocatedAction
{
    public function __construct(
        public PlacedAction $action,
        public ?bool $here,
    ) {
    }
}

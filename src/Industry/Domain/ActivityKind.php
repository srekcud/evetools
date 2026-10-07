<?php

declare(strict_types=1);

namespace App\Industry\Domain;

enum ActivityKind
{
    case Manufacturing;
    case Reaction;
    case Copying;
    case Invention;
}

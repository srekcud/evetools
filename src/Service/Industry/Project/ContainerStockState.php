<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum ContainerStockState: string
{
    case Known = 'known';
    case Unknown = 'unknown';
    case None = 'none';
}

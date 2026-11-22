<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RefreshProjectStructureAccess
{
    public function __construct(public string $projectId)
    {
    }
}

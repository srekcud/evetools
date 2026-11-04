<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\MoonComposition;
use App\Entity\User;

final readonly class MoonCompositionRecorder
{
    public function record(User $user, int $corporationId, int $moonId, string $scan): MoonComposition
    {
        return new MoonComposition();
    }
}

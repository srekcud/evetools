<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

use App\Entity\User;

class CompletedProjectFiguresLoader
{
    /**
     * @return list<CompletedProjectFigures>
     */
    public function loadFor(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [];
    }
}

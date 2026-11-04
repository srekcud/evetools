<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\User;
use App\Service\ESI\CharacterService;

final readonly class MoonTaxManagerAccess
{
    public function __construct(private CharacterService $characterService)
    {
    }

    public function assertManages(User $user, int $corporationId): void
    {
    }
}

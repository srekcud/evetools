<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Enum\StructureAccessStatus;

interface CharacterStructureAccess
{
    public function statusOf(Character $character, int $structureId): StructureAccessStatus;
}

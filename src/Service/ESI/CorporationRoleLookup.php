<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use App\Enum\CorporationRoleStatus;

final readonly class CorporationRoleLookup
{
    /**
     * @param array<string, list<string>> $rolesReadByCharacterName
     * @param list<string>                $unreadableCharacterNames
     */
    public function __construct(
        public CorporationRoleStatus $status,
        public ?Character $holder = null,
        public mixed $value = null,
        public array $rolesReadByCharacterName = [],
        public array $unreadableCharacterNames = [],
    ) {
    }
}

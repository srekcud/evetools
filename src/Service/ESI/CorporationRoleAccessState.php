<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Enum\CorporationRoleStatus;

final readonly class CorporationRoleAccessState
{
    /**
     * @param list<array{characterId: int, name: string, isMain: bool, state: string, roles: ?list<string>}> $characters
     */
    public function __construct(
        public CorporationRoleStatus $status,
        public string $requiredRole,
        public array $characters,
    ) {
    }

    public function label(): string
    {
        return match ($this->status) {
            CorporationRoleStatus::Held => 'held',
            CorporationRoleStatus::RoleAbsent => 'role_missing',
            CorporationRoleStatus::ReconnectRequired => 'reconnect',
            CorporationRoleStatus::EsiError => 'esi_error',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Constant\EveConstants;
use App\Enum\CorporationRoleStatus;
use App\Repository\CharacterRepository;

final readonly class CorporationDirectorAccess
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private CorporationRoleReader $corporationRoleReader,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function isConnected(int $corporationId, array $scopes): bool
    {
        $lookup = $this->corporationRoleReader->findHolder(
            $corporationId,
            array_values($this->characterRepository->findByCorporationId($corporationId)),
            [],
            [...$scopes, EveConstants::CORPORATION_ROLES_SCOPE],
        );

        return $lookup->status === CorporationRoleStatus::Held;
    }
}

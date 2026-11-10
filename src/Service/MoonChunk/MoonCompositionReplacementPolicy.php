<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\Character;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Enum\CorporationRoleStatus;
use App\Repository\MoonCompositionRepository;
use App\Service\ESI\CorporationRoleReader;

final readonly class MoonCompositionReplacementPolicy
{
    private const array MANAGEMENT_ROLES = ['Director', 'Station_Manager'];
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    public function __construct(
        private MoonCompositionRepository $compositionRepository,
        private CorporationRoleReader $corporationRoleReader,
    ) {
    }

    public function assertMayRecord(User $user, int $corporationId, int $moonId): void
    {
        $composition = $this->compositionRepository->findOneBy(['corporationId' => $corporationId, 'moonId' => $moonId]);

        if ($composition === null || $this->isAuthor($user, $composition) || $this->holdsManagementRole($user, $corporationId)) {
            return;
        }

        throw new MoonCompositionReplacementRefused('Only the author of the scan or a Director or Station Manager of the corporation can replace it');
    }

    private function isAuthor(User $user, MoonComposition $composition): bool
    {
        $authorId = $composition->getRecordedBy()?->getId();
        $userId = $user->getId();

        return $authorId !== null && $userId !== null && $authorId->equals($userId);
    }

    private function holdsManagementRole(User $user, int $corporationId): bool
    {
        $members = $user->getCharacters()->filter(
            static fn (Character $character): bool => $character->getCorporationId() === $corporationId,
        )->getValues();

        $lookup = $this->corporationRoleReader->findHolder($corporationId, $members, self::MANAGEMENT_ROLES, [self::ROLES_SCOPE]);

        return match ($lookup->status) {
            CorporationRoleStatus::Held => true,
            CorporationRoleStatus::RoleAbsent => false,
            CorporationRoleStatus::EsiError, CorporationRoleStatus::ReconnectRequired => throw new CorporationRolesUnreadable('The corporation roles of your characters could not be read, try again later'),
        };
    }
}

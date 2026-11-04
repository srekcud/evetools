<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\Character;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Repository\MoonCompositionRepository;
use App\Service\ESI\CharacterService;

final readonly class MoonCompositionReplacementPolicy
{
    private const array MANAGEMENT_ROLES = ['Director', 'Station_Manager'];

    public function __construct(
        private MoonCompositionRepository $compositionRepository,
        private CharacterService $characterService,
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
        $hasUnreadableRoles = false;

        foreach ($user->getCharacters() as $character) {
            if ($character->getCorporationId() !== $corporationId) {
                continue;
            }

            try {
                $roles = $this->characterService->getCharacterRoles($character);
            } catch (EsiApiException|EveAuthRequiredException) {
                $hasUnreadableRoles = true;
                continue;
            }

            if (array_intersect(self::MANAGEMENT_ROLES, $roles) !== []) {
                return true;
            }
        }

        if ($hasUnreadableRoles) {
            throw new CorporationRolesUnreadable('The corporation roles of your characters could not be read, try again later');
        }

        return false;
    }
}

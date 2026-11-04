<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\Character;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class MoonTaxManagerAccess
{
    public const string ACCOUNTANT_ROLE = 'Accountant';
    public const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    public function __construct(private CharacterService $characterService)
    {
    }

    public function assertManages(User $user, int $corporationId): void
    {
        $rolesByCharacterName = [];
        $unreadableCharacterNames = [];

        foreach ($this->readersOf($user, $corporationId) as $character) {
            try {
                $roles = $this->characterService->readCorporationRoles($character);
            } catch (EsiApiException|\UnexpectedValueException) {
                $unreadableCharacterNames[] = $character->getName();

                continue;
            }
            if (in_array(self::ACCOUNTANT_ROLE, $roles, true)) {
                return;
            }
            $rolesByCharacterName[$character->getName()] = $roles;
        }

        if ($unreadableCharacterNames !== []) {
            throw new ServiceUnavailableHttpException(null, sprintf('The corporation roles of %s could not be read', implode(', ', $unreadableCharacterNames)));
        }

        throw new AccessDeniedHttpException(sprintf('The %s role is required to manage the moon tax of corporation %d. Roles read: %s', self::ACCOUNTANT_ROLE, $corporationId, $this->describe($rolesByCharacterName)));
    }

    /**
     * @return list<Character>
     */
    private function readersOf(User $user, int $corporationId): array
    {
        return array_values(array_filter(
            $user->getCharacters()->toArray(),
            static fn (Character $character): bool => $character->getCorporationId() === $corporationId
                && $character->isAuthValid()
                && $character->getEveToken()?->hasScope(self::ROLES_SCOPE) === true,
        ));
    }

    /**
     * @param array<string, list<string>> $rolesByCharacterName
     */
    private function describe(array $rolesByCharacterName): string
    {
        if ($rolesByCharacterName === []) {
            return 'none, no character of this corporation can read its roles';
        }

        $parts = [];
        foreach ($rolesByCharacterName as $name => $roles) {
            $parts[] = sprintf('%s (%s)', $name, implode(', ', $roles));
        }

        return implode('; ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\User;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleReader;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class MoonTaxManagerAccess
{
    public const string ACCOUNTANT_ROLE = 'Accountant';
    public const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    public function __construct(
        private CharacterService $characterService,
        private CorporationRoleReader $corporationRoleReader,
    ) {
    }

    public function assertManages(User $user, int $corporationId): void
    {
        $unreadableCharacterNames = [];
        $members = [];

        foreach ($user->getCharacters() as $character) {
            try {
                if ($this->characterService->readCorporationId($character->getEveCharacterId()) === $corporationId) {
                    $members[] = $character;
                }
            } catch (EsiApiException|\UnexpectedValueException) {
                $unreadableCharacterNames[] = $character->getName();
            }
        }

        $lookup = $this->corporationRoleReader->findHolder($corporationId, $members, [self::ACCOUNTANT_ROLE], [self::ROLES_SCOPE]);
        if ($lookup->status === CorporationRoleStatus::Held) {
            return;
        }

        $unreadableCharacterNames = [...$unreadableCharacterNames, ...$lookup->unreadableCharacterNames];
        if ($unreadableCharacterNames !== []) {
            throw new ServiceUnavailableHttpException(null, sprintf('The corporation roles of %s could not be read', implode(', ', $unreadableCharacterNames)));
        }

        if ($lookup->status === CorporationRoleStatus::ReconnectRequired && $lookup->rolesReadByCharacterName === []) {
            throw new AccessDeniedHttpException(sprintf('No character of corporation %d has a valid token with the scope %s to read its roles: reconnect one', $corporationId, self::ROLES_SCOPE));
        }

        throw new AccessDeniedHttpException(sprintf('The %s role is required to manage the moon tax of corporation %d. Roles read: %s', self::ACCOUNTANT_ROLE, $corporationId, $this->describe($lookup->rolesReadByCharacterName)));
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
            $parts[] = sprintf('%s (%s)', $name, $roles === [] ? 'no role' : implode(', ', $roles));
        }

        return implode('; ', $parts);
    }
}

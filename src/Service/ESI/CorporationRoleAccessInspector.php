<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use App\Entity\User;
use App\Enum\CorporationRoleFeature;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;

final readonly class CorporationRoleAccessInspector
{
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    private const string STATE_WITHOUT_ROLE = 'without_role';
    private const string STATE_RECONNECT = 'reconnect';
    private const string STATE_UNREADABLE = 'unreadable';
    private const string STATE_UNKNOWN = 'unknown';

    public function __construct(
        private CharacterService $characterService,
        private CorporationRoleReader $corporationRoleReader,
    ) {
    }

    public function inspect(User $user, int $corporationId, CorporationRoleFeature $feature): CorporationRoleAccessState
    {
        $requiredRole = $feature->requiredRole();
        $members = [];
        $unaffiliated = [];
        foreach ($user->getCharacters() as $character) {
            try {
                if ($this->characterService->readCorporationId($character->getEveCharacterId()) === $corporationId) {
                    $members[] = $character;
                }
            } catch (EsiApiException|\UnexpectedValueException) {
                $unaffiliated[] = $character;
            }
        }

        $lookup = $this->corporationRoleReader->findHolder($corporationId, $members, [$requiredRole], [self::ROLES_SCOPE]);
        if ($lookup->status === CorporationRoleStatus::Held) {
            return new CorporationRoleAccessState(CorporationRoleStatus::Held, $requiredRole, []);
        }

        $unreadableNames = [...array_map(static fn (Character $character): string => $character->getName(), $unaffiliated), ...$lookup->unreadableCharacterNames];
        $status = match (true) {
            $unreadableNames !== [] => CorporationRoleStatus::EsiError,
            $lookup->status === CorporationRoleStatus::ReconnectRequired && $lookup->rolesReadByCharacterName !== [] => CorporationRoleStatus::RoleAbsent,
            default => $lookup->status,
        };

        return new CorporationRoleAccessState(
            $status,
            $requiredRole,
            array_map(
                fn (Character $character): array => $this->describe($character, $lookup, $unreadableNames),
                $this->ordered([...$members, ...$unaffiliated]),
            ),
        );
    }

    public function recheck(User $user, int $corporationId, CorporationRoleFeature $feature): CorporationRoleAccessState
    {
        $this->corporationRoleReader->forget($corporationId, [$feature->requiredRole()]);

        return $this->inspect($user, $corporationId, $feature);
    }

    /**
     * @param list<string> $unreadableNames
     *
     * @return array{characterId: int, name: string, isMain: bool, state: string, roles: ?list<string>}
     */
    private function describe(Character $character, CorporationRoleLookup $lookup, array $unreadableNames): array
    {
        $name = $character->getName();
        $rolesRead = $lookup->rolesReadByCharacterName[$name] ?? null;
        $state = match (true) {
            in_array($name, $unreadableNames, true) => self::STATE_UNREADABLE,
            $rolesRead !== null => self::STATE_WITHOUT_ROLE,
            !$this->corporationRoleReader->canRead($character, [self::ROLES_SCOPE]) => self::STATE_RECONNECT,
            default => self::STATE_UNKNOWN,
        };

        return [
            'characterId' => $character->getEveCharacterId(),
            'name' => $name,
            'isMain' => $character->isMain(),
            'state' => $state,
            'roles' => $state === self::STATE_WITHOUT_ROLE ? $rolesRead : null,
        ];
    }

    /**
     * @param list<Character> $characters
     *
     * @return list<Character>
     */
    private function ordered(array $characters): array
    {
        usort($characters, static fn (Character $left, Character $right): int => [$right->isMain(), $left->getEveCharacterId()] <=> [$left->isMain(), $right->getEveCharacterId()]);

        return $characters;
    }
}

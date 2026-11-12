<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationDirectorAccess;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

trait BuildsCorporationDirectorAccess
{
    /** @var list<int> */
    private array $rolesReadFor = [];

    /** @var array<int, list<string>> */
    private array $rolesByEveCharacterId = [];

    /** @var array<int, list<Character>> */
    private array $membersByCorporationId = [];

    private int $nextDirectorAccessMemberId = 2_112_900_001;

    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     */
    private function corporationMember(int $corporationId, array $roles, ?array $scopes = null, bool $authValid = true): Character
    {
        $eveCharacterId = $this->nextDirectorAccessMemberId++;
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Member ' . $eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken((new EveToken())
            ->setAccessToken('access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes ?? [
                EveConstants::CORPORATION_ASSETS_SCOPE,
                EveConstants::CORPORATION_BLUEPRINTS_SCOPE,
                EveConstants::CORPORATION_ROLES_SCOPE,
            ]));
        if (!$authValid) {
            $character->markAuthInvalid();
        }
        (new User())->addCharacter($character);
        $this->rolesByEveCharacterId[$eveCharacterId] = $roles;
        $this->membersByCorporationId[$corporationId][] = $character;

        return $character;
    }

    private function corporationDirectorAccess(): CorporationDirectorAccess
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $eveCharacterId = (int) preg_replace('#\D#', '', $endpoint);
            $this->rolesReadFor[] = $eveCharacterId;

            return ['roles' => $this->rolesByEveCharacterId[$eveCharacterId] ?? []];
        });

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findByCorporationId')->willReturnCallback(
            fn (int $corporationId): array => $this->membersByCorporationId[$corporationId] ?? [],
        );

        return new CorporationDirectorAccess(
            $characterRepository,
            new CorporationRoleReader(new CharacterService($esiClient), new ArrayAdapter(), new NullLogger()),
        );
    }
}

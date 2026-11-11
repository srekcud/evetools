<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\CorporationRoleFeature;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleAccessInspector;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CorporationRoleAccessInspector::class)]
#[Group('issue-583')]
final class CorporationRoleAccessInspectorTest extends TestCase
{
    private const int CORPORATION = 98_583_001;
    private const int OTHER_CORPORATION = 98_583_002;
    private const int ALT_ID = 2_112_583_001;
    private const int MAIN_ID = 2_112_583_002;
    private const int OTHER_ID = 2_112_583_003;
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    private User $user;
    private CorporationRoleAccessInspector $inspector;

    private array $rolesByEveCharacterId = [];

    private array $rolesFailures = [];

    private array $rolesReadFor = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $eveCharacterId = (int) preg_replace('#\D#', '', $endpoint);
            $this->rolesReadFor[] = $eveCharacterId;
            if (isset($this->rolesFailures[$eveCharacterId])) {
                throw $this->rolesFailures[$eveCharacterId];
            }

            return ['roles' => $this->rolesByEveCharacterId[$eveCharacterId]];
        });
        $esiClient->method('getWithCache')->willReturnCallback(
            fn (string $endpoint): array => ['corporation_id' => $this->corporationByEveCharacterId[(int) preg_replace('#\D#', '', $endpoint)]],
        );
        $characterService = new CharacterService($esiClient);
        $this->inspector = new CorporationRoleAccessInspector($characterService, new CorporationRoleReader($characterService, new ArrayAdapter(), new NullLogger()));
    }

    private array $corporationByEveCharacterId = [];

    public function testWithoutTheRequiredRoleTheStateListsEachCharacterOfTheCorporationWithTheRolesRead(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, ['Auditor', 'Junior_Accountant']);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, []);
        $this->user->setMainCharacter($main);
        $this->character('Brann Holt', self::OTHER_ID, ['Accountant'], self::OTHER_CORPORATION);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [
                CorporationRoleStatus::RoleAbsent,
                'Accountant',
                [
                    ['characterId' => self::MAIN_ID, 'name' => 'Ilyra Voss', 'isMain' => true, 'state' => 'without_role', 'roles' => []],
                    ['characterId' => self::ALT_ID, 'name' => 'Aurel Kade', 'isMain' => false, 'state' => 'without_role', 'roles' => ['Auditor', 'Junior_Accountant']],
                ],
            ],
            [$state->status, $state->requiredRole, $state->characters],
        );
        self::assertSame($alt->getEveCharacterId(), $state->characters[1]['characterId']);
    }

    public function testTheExtractionsFeatureRequiresTheStationManagerRole(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant']);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::Extractions);

        self::assertSame([CorporationRoleStatus::RoleAbsent, 'Station_Manager'], [$state->status, $state->requiredRole]);
    }

    public function testAHolderOfTheRoleGetsAHeldStateWithoutAnyCharacterListed(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant']);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame([CorporationRoleStatus::Held, []], [$state->status, $state->characters]);
    }

    public function testADirectorHoldsTheRoleImplicitly(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Director']);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::Extractions);

        self::assertSame(CorporationRoleStatus::Held, $state->status);
    }

    public function testWhenNoCharacterCanReadItsRolesTheStateAsksToReconnectWithoutRoles(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant'])->markAuthInvalid();
        $this->character('Aurel Kade', self::ALT_ID, ['Accountant'], self::CORPORATION, []);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [
                CorporationRoleStatus::ReconnectRequired,
                [
                    ['characterId' => self::ALT_ID, 'name' => 'Aurel Kade', 'isMain' => false, 'state' => 'reconnect', 'roles' => null],
                    ['characterId' => self::MAIN_ID, 'name' => 'Ilyra Voss', 'isMain' => false, 'state' => 'reconnect', 'roles' => null],
                ],
                [],
            ],
            [$state->status, $state->characters, $this->rolesReadFor],
        );
    }

    public function testACharacterToReconnectDoesNotHideTheRolesReadOnAnother(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant'])->markAuthInvalid();
        $this->character('Aurel Kade', self::ALT_ID, ['Auditor']);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [
                CorporationRoleStatus::RoleAbsent,
                [
                    ['characterId' => self::ALT_ID, 'name' => 'Aurel Kade', 'isMain' => false, 'state' => 'without_role', 'roles' => ['Auditor']],
                    ['characterId' => self::MAIN_ID, 'name' => 'Ilyra Voss', 'isMain' => false, 'state' => 'reconnect', 'roles' => null],
                ],
            ],
            [$state->status, $state->characters],
        );
    }

    public function testAnEsiFailureOnTheRolesIsAnEsiErrorWithTheCharacterUnreadable(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant']);
        $this->rolesFailures[self::MAIN_ID] = EsiApiException::fromResponse(500, 'ESI 500', '/characters/roles/');

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [CorporationRoleStatus::EsiError, [['characterId' => self::MAIN_ID, 'name' => 'Ilyra Voss', 'isMain' => false, 'state' => 'unreadable', 'roles' => null]]],
            [$state->status, $state->characters],
        );
    }

    public function testAnEsiRefusalOfTheRolesLeavesTheCharacterUnknownNotWithoutRole(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant']);
        $this->rolesFailures[self::MAIN_ID] = EsiApiException::fromResponse(403, 'ESI 403', '/characters/roles/');

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [CorporationRoleStatus::RoleAbsent, [['characterId' => self::MAIN_ID, 'name' => 'Ilyra Voss', 'isMain' => false, 'state' => 'unknown', 'roles' => null]]],
            [$state->status, $state->characters],
        );
    }

    public function testAnAccessWithoutCharacterInTheCorporationIsRoleAbsentWithNobodyListed(): void
    {
        $this->character('Brann Holt', self::OTHER_ID, ['Accountant'], self::OTHER_CORPORATION);

        $state = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame([CorporationRoleStatus::RoleAbsent, []], [$state->status, $state->characters]);
    }

    public function testAnInspectionRemembersTheHolderButARecheckReadsTheRolesAgain(): void
    {
        $this->character('Ilyra Voss', self::MAIN_ID, ['Accountant']);
        $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);
        $this->rolesByEveCharacterId[self::MAIN_ID] = [];

        $remembered = $this->inspector->inspect($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);
        $rechecked = $this->inspector->recheck($this->user, self::CORPORATION, CorporationRoleFeature::MoonTax);

        self::assertSame(
            [CorporationRoleStatus::Held, CorporationRoleStatus::RoleAbsent, [self::MAIN_ID, self::MAIN_ID]],
            [$remembered->status, $rechecked->status, $this->rolesReadFor],
        );
    }

    private function character(string $name, int $eveCharacterId, array $roles, int $corporationId = self::CORPORATION, array $scopes = [self::ROLES_SCOPE]): Character
    {
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken(
            (new EveToken())
                ->setAccessToken('access-token-' . $eveCharacterId)
                ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-11 23:00:00', new \DateTimeZone('UTC')))
                ->setScopes($scopes),
        );
        $this->user->addCharacter($character);
        $this->rolesByEveCharacterId[$eveCharacterId] = $roles;
        $this->corporationByEveCharacterId[$eveCharacterId] = $corporationId;

        return $character;
    }
}

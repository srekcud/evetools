<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CorporationRoleReader::class)]
#[Group('issue-581')]
final class CorporationRoleReaderTest extends TestCase
{
    private const int CORPORATION = 98_581_001;
    private const int OTHER_CORPORATION = 98_581_002;
    private const int ALT_ID = 2_112_581_001;
    private const int MAIN_ID = 2_112_581_002;
    private const int THIRD_ID = 2_112_581_003;
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    private const string STRUCTURES_SCOPE = 'esi-corporations.read_structures.v1';
    private const string STATION_MANAGER = 'Station_Manager';
    private const string ACCOUNTANT = 'Accountant';
    private const string DIRECTOR = 'Director';

    private User $user;
    private CorporationRoleReader $reader;

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
        $this->reader = new CorporationRoleReader(new CharacterService($esiClient), new ArrayAdapter(), new NullLogger());
    }

    public function testTheMainCharacterIsTriedBeforeAnAltWithALowerEveCharacterId(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER]);
        $this->user->setMainCharacter($main);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([CorporationRoleStatus::Held, $main, [self::MAIN_ID]], [$lookup->status, $lookup->holder, $this->rolesReadFor]);
    }

    public function testWithoutAMainCharacterTheLowestEveCharacterIdIsTriedFirst(): void
    {
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER]);
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$main, $alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([$alt, [self::ALT_ID]], [$lookup->holder, $this->rolesReadFor]);
    }

    public function testTwoLookupsOfTheSameCorporationAndRolesReadTheRolesOnce(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER]);
        $this->user->setMainCharacter($main);

        $first = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $second = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::Held, $main, CorporationRoleStatus::Held, $main, [self::MAIN_ID]],
            [$first->status, $first->holder, $second->status, $second->holder, $this->rolesReadFor],
        );
    }

    public function testTheRememberedHolderIsTriedFirstEvenWhenAMainCharacterIsNowAvailable(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER]);
        $this->user->setMainCharacter($main);
        $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([$alt, [self::ALT_ID]], [$lookup->holder, $this->rolesReadFor]);
    }

    public function testTheMemoryIsPerCorporationAndPerRequiredRoles(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER, self::ACCOUNTANT]);

        $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $this->reader->findHolder(self::CORPORATION, [$alt], [self::ACCOUNTANT], [self::ROLES_SCOPE]);
        $this->reader->findHolder(self::OTHER_CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([self::ALT_ID, self::ALT_ID, self::ALT_ID], $this->rolesReadFor);
    }

    public function testAReadAsHolderThatSucceedsRemembersTheHolderAndReturnsTheValueRead(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER]);
        $this->user->setMainCharacter($main);

        $read = $this->reader->readAsHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE], static fn (Character $holder): string => 'structures of '.$holder->getName());
        $found = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::Held, $main, 'structures of Ilyra Voss', $main, [self::MAIN_ID]],
            [$read->status, $read->holder, $read->value, $found->holder, $this->rolesReadFor],
        );
    }

    public function testARememberedHolderRefusedByTheReadIsForgottenAndTheNextCharacterBecomesTheHolder(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $third = $this->character('Brann Holt', self::THIRD_ID, [self::STATION_MANAGER]);
        $readBy = [];
        $refuseTheAlt = true;
        $read = static function (Character $holder) use (&$readBy, &$refuseTheAlt): string {
            $readBy[] = $holder->getEveCharacterId();
            if ($refuseTheAlt && $holder->getEveCharacterId() === self::ALT_ID) {
                throw EsiApiException::forbidden('Forbidden', '/corporations/98581001/structures/');
            }

            return 'read by '.$holder->getName();
        };
        $this->reader->readAsHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE], static fn (): string => 'first read');
        $readBy = [];

        $second = $this->reader->readAsHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE], $read);
        $refuseTheAlt = false;
        $found = $this->reader->findHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::Held, $third, 'read by Brann Holt', [self::ALT_ID, self::THIRD_ID], $third, [self::ALT_ID, self::THIRD_ID]],
            [$second->status, $second->holder, $second->value, $readBy, $found->holder, $this->rolesReadFor],
        );
    }

    public function testARememberedHolderWhoseTokenBecameInvalidIsForgottenAndTheOthersAreTried(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $third = $this->character('Brann Holt', self::THIRD_ID, [self::STATION_MANAGER]);
        $this->reader->findHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $alt->markAuthInvalid();

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $again = $this->reader->findHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::Held, $third, $third, [self::ALT_ID, self::THIRD_ID]],
            [$lookup->status, $lookup->holder, $again->holder, $this->rolesReadFor],
        );
    }

    public function testAnAuthRequiredFailureOfTheRememberedHolderForgetsItAndAsksToReconnectWhenNobodyElseCanRead(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        $lookup = $this->reader->readAsHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE], static fn (): never => throw new EveAuthRequiredException((string) self::ALT_ID));
        $afterwards = $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::ReconnectRequired, null, [self::ALT_ID, self::ALT_ID]],
            [$lookup->status, $lookup->holder, $this->rolesReadFor],
        );
        self::assertSame(CorporationRoleStatus::Held, $afterwards->status);
    }

    public function testADirectorWithoutTheRequiredRoleHoldsIt(): void
    {
        $director = $this->character('Aurel Kade', self::ALT_ID, [self::ACCOUNTANT, self::DIRECTOR]);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$director], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([CorporationRoleStatus::Held, $director], [$lookup->status, $lookup->holder]);
    }

    public function testWithoutAnyCharacterWithTheScopesTheLookupAsksToReconnectInsteadOfReportingAnAbsentRole(): void
    {
        $invalidAuth = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $invalidAuth->markAuthInvalid();
        $withoutScope = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$invalidAuth, $withoutScope], [self::STATION_MANAGER], [self::ROLES_SCOPE, self::STRUCTURES_SCOPE]);

        self::assertSame([CorporationRoleStatus::ReconnectRequired, null, []], [$lookup->status, $lookup->holder, $this->rolesReadFor]);
    }

    public function testRolesReadWithoutTheRequiredRoleAreRoleAbsentAndListedByCharacterName(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::ACCOUNTANT, 'Junior_Accountant']);
        $third = $this->character('Brann Holt', self::THIRD_ID, []);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $third], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::RoleAbsent, null, ['Aurel Kade' => [self::ACCOUNTANT, 'Junior_Accountant'], 'Brann Holt' => []], []],
            [$lookup->status, $lookup->holder, $lookup->rolesReadByCharacterName, $lookup->unreadableCharacterNames],
        );
    }

    public function testAnEmptyCharacterListIsRoleAbsent(): void
    {
        $lookup = $this->reader->findHolder(self::CORPORATION, [], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([CorporationRoleStatus::RoleAbsent, null, [], []], [$lookup->status, $lookup->holder, $lookup->rolesReadByCharacterName, $this->rolesReadFor]);
    }

    public function testAServerErrorOnTheRolesOfTheOnlyCandidateIsAnEsiErrorNamingTheCharacter(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $this->rolesFailures[self::ALT_ID] = EsiApiException::fromResponse(500, 'ESI 500', '/characters/2112581001/roles/');

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([CorporationRoleStatus::EsiError, null, ['Aurel Kade']], [$lookup->status, $lookup->holder, $lookup->unreadableCharacterNames]);
    }

    public function testAServerErrorOfTheReadKeepsTheRememberedHolder(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        $failed = $this->reader->readAsHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE], static fn (): never => throw EsiApiException::fromResponse(503, 'ESI 503', '/corporations/98581001/structures/'));
        $found = $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(
            [CorporationRoleStatus::EsiError, CorporationRoleStatus::Held, $alt, [self::ALT_ID]],
            [$failed->status, $found->status, $found->holder, $this->rolesReadFor],
        );
    }

    public function testAnUnreadableRolesAnswerIsAnEsiErrorNotAnAbsentRole(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $this->rolesByEveCharacterId[self::ALT_ID] = 'Station_Manager';

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame([CorporationRoleStatus::EsiError, ['Aurel Kade']], [$lookup->status, $lookup->unreadableCharacterNames]);
    }

    public function testAnAltIsTheHolderWhenTheMainCharacterLacksTheScope(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $main = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $this->user->setMainCharacter($main);

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $main], [self::STATION_MANAGER], [self::ROLES_SCOPE, self::STRUCTURES_SCOPE]);

        self::assertSame([CorporationRoleStatus::Held, $alt, [self::ALT_ID]], [$lookup->status, $lookup->holder, $this->rolesReadFor]);
    }

    public function testAForbiddenRolesReadIsAnAbsentRoleNotAnEsiError(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $this->rolesFailures[self::ALT_ID] = EsiApiException::forbidden('Forbidden', '/characters/2112581001/roles/');

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE]);

        self::assertSame(CorporationRoleStatus::RoleAbsent, $lookup->status);
    }

    public function testAForbiddenReadIsAnAbsentRoleNotAnEsiError(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);

        $lookup = $this->reader->readAsHolder(self::CORPORATION, [$alt], [self::STATION_MANAGER], [self::ROLES_SCOPE], static fn (): never => throw EsiApiException::forbidden('Forbidden', '/corporations/98581001/structures/'));

        self::assertSame([CorporationRoleStatus::RoleAbsent, null], [$lookup->status, $lookup->holder]);
    }

    public function testAnEsiErrorOnOneCandidateWinsOverAnAbsentRoleOnTheOtherAndOverAnUnreadableCharacter(): void
    {
        $alt = $this->character('Aurel Kade', self::ALT_ID, [self::STATION_MANAGER]);
        $third = $this->character('Brann Holt', self::THIRD_ID, [self::ACCOUNTANT]);
        $withoutScope = $this->character('Ilyra Voss', self::MAIN_ID, [self::STATION_MANAGER], [self::ROLES_SCOPE]);
        $this->rolesFailures[self::ALT_ID] = EsiApiException::fromResponse(500, 'ESI 500', '/characters/2112581001/roles/');

        $lookup = $this->reader->findHolder(self::CORPORATION, [$alt, $third, $withoutScope], [self::STATION_MANAGER], [self::ROLES_SCOPE, self::STRUCTURES_SCOPE]);

        self::assertSame(CorporationRoleStatus::EsiError, $lookup->status);
    }

    private function character(string $name, int $eveCharacterId, array $roles, array $scopes = [self::ROLES_SCOPE, self::STRUCTURES_SCOPE]): Character
    {
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId)
            ->setCorporationId(self::CORPORATION);
        $character->setEveToken(
            (new EveToken())
                ->setAccessToken('access-token-'.$eveCharacterId)
                ->setRefreshTokenEncrypted('refresh-token-'.$eveCharacterId)
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-11 23:00:00', new \DateTimeZone('UTC')))
                ->setScopes($scopes),
        );
        $this->user->addCharacter($character);
        $this->rolesByEveCharacterId[$eveCharacterId] = $roles;

        return $character;
    }
}

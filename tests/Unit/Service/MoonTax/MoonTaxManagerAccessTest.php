<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use App\Service\ESI\EsiClient;
use App\Service\MoonTax\MoonTaxManagerAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

#[CoversClass(MoonTaxManagerAccess::class)]
#[Group('issue-273')]
#[Group('issue-369')]
final class MoonTaxManagerAccessTest extends TestCase
{
    private const int CORPORATION = 98_000_001;
    private const int OTHER_CORPORATION = 98_000_002;
    private const int ILYRA_ID = 2_112_000_001;
    private const int AUREL_ID = 2_112_000_002;
    private const int BRANN_ID = 2_112_000_003;
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    private User $user;
    private MoonTaxManagerAccess $access;

    private array $esiResponses = [];

    private array $esiFailures = [];

    private array $rolesReadBy = [];

    private array $corporationsReadFor = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->rolesReadBy[] = $endpoint;
            if (isset($this->esiFailures[$endpoint])) {
                throw $this->esiFailures[$endpoint];
            }

            return $this->esiResponses[$endpoint];
        });
        $esiClient->method('getWithCache')->willReturnCallback(function (string $endpoint): array {
            $this->corporationsReadFor[] = $endpoint;
            if (isset($this->esiFailures[$endpoint])) {
                throw $this->esiFailures[$endpoint];
            }

            return $this->esiResponses[$endpoint];
        });
        $this->access = new MoonTaxManagerAccess(new CharacterService($esiClient));
    }

    public function testACharacterOfTheCorporationWithTheAccountantRoleManagesIt(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant', 'Director']);

        $this->access->assertManages($this->user, self::CORPORATION);

        self::assertSame([$this->rolesEndpoint(self::ILYRA_ID)], $this->rolesReadBy);
    }

    public function testRolesAreReadAgainAtEveryCall(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);

        $this->access->assertManages($this->user, self::CORPORATION);
        $this->access->assertManages($this->user, self::CORPORATION);

        self::assertSame([$this->rolesEndpoint(self::ILYRA_ID), $this->rolesEndpoint(self::ILYRA_ID)], $this->rolesReadBy);
    }

    public function testWithoutTheAccountantRoleTheAccessIsRefusedWithTheRolesReadForEachCharacterOfTheCorporation(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Director', 'Junior_Accountant']);
        $this->character('Aurel Kade', self::AUREL_ID, self::CORPORATION, ['Factory_Manager']);
        $this->character('Brann Holt', self::BRANN_ID, self::OTHER_CORPORATION, ['Accountant']);

        try {
            $this->access->assertManages($this->user, self::CORPORATION);
            self::fail('The access should be refused');
        } catch (AccessDeniedHttpException $refusal) {
            self::assertSame(403, $refusal->getStatusCode());
            self::assertSame(
                [true, true, true, true, true, false],
                [
                    str_contains($refusal->getMessage(), 'Ilyra Voss'),
                    str_contains($refusal->getMessage(), 'Director'),
                    str_contains($refusal->getMessage(), 'Junior_Accountant'),
                    str_contains($refusal->getMessage(), 'Aurel Kade'),
                    str_contains($refusal->getMessage(), 'Factory_Manager'),
                    str_contains($refusal->getMessage(), 'Brann Holt'),
                ],
            );
        }
    }

    public function testAnAccountantOfAnotherCorporationDoesNotManageThisOne(): void
    {
        $this->character('Brann Holt', self::BRANN_ID, self::OTHER_CORPORATION, ['Accountant']);

        $this->expectException(AccessDeniedHttpException::class);

        $this->access->assertManages($this->user, self::CORPORATION);
    }

    public function testAnEsiFailureOnTheOnlyReaderIsAServiceUnavailableErrorNotAGrantedAccess(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);
        $this->esiFailures[$this->rolesEndpoint(self::ILYRA_ID)] = EsiApiException::fromResponse(503, 'ESI 503', $this->rolesEndpoint(self::ILYRA_ID));

        try {
            $this->access->assertManages($this->user, self::CORPORATION);
            self::fail('The roles read failure should be reported');
        } catch (ServiceUnavailableHttpException $unavailable) {
            self::assertSame(503, $unavailable->getStatusCode());
        }
    }

    public function testAnAccountantStillGrantsTheAccessWhenAnotherCharacterRolesReadFails(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Director']);
        $this->character('Aurel Kade', self::AUREL_ID, self::CORPORATION, ['Accountant']);
        $this->esiFailures[$this->rolesEndpoint(self::ILYRA_ID)] = EsiApiException::fromResponse(500, 'ESI 500', $this->rolesEndpoint(self::ILYRA_ID));

        $this->access->assertManages($this->user, self::CORPORATION);

        self::assertContains($this->rolesEndpoint(self::AUREL_ID), $this->rolesReadBy);
    }

    public function testCharactersWithAnInvalidAuthOrWithoutTheRolesScopeAreIgnoredAndTheAccessIsRefused(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant'])->markAuthInvalid();
        $this->character('Aurel Kade', self::AUREL_ID, self::CORPORATION, ['Accountant'], []);

        try {
            $this->access->assertManages($this->user, self::CORPORATION);
            self::fail('The access should be refused');
        } catch (AccessDeniedHttpException) {
            self::assertSame([], $this->rolesReadBy);
        }
    }

    public function testACharacterStoredInTheCorporationButReadInAnotherOneIsRefusedWithoutReadingItsRoles(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant'], currentCorporationId: self::OTHER_CORPORATION);

        try {
            $this->access->assertManages($this->user, self::CORPORATION);
            self::fail('The access should be refused');
        } catch (AccessDeniedHttpException $refusal) {
            self::assertSame(
                [403, false, [$this->characterEndpoint(self::ILYRA_ID)], []],
                [$refusal->getStatusCode(), str_contains($refusal->getMessage(), 'Ilyra Voss'), $this->corporationsReadFor, $this->rolesReadBy],
            );
        }
    }

    public function testACharacterStoredInAnotherCorporationButReadInTheCorporationManagesIt(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::OTHER_CORPORATION, ['Accountant'], currentCorporationId: self::CORPORATION);

        $refusal = null;
        try {
            $this->access->assertManages($this->user, self::CORPORATION);
        } catch (AccessDeniedHttpException|ServiceUnavailableHttpException $refusal) {
        }

        self::assertSame(
            [null, [$this->characterEndpoint(self::ILYRA_ID)], [$this->rolesEndpoint(self::ILYRA_ID)]],
            [$refusal?->getStatusCode(), $this->corporationsReadFor, $this->rolesReadBy],
        );
    }

    public function testAFailedAffiliationReadIsAServiceUnavailableErrorNotAGrantedAccess(): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);
        $this->esiFailures[$this->characterEndpoint(self::ILYRA_ID)] = EsiApiException::fromResponse(503, 'ESI 503', $this->characterEndpoint(self::ILYRA_ID));

        try {
            $this->access->assertManages($this->user, self::CORPORATION);
            self::fail('The affiliation read failure should be reported');
        } catch (ServiceUnavailableHttpException $unavailable) {
            self::assertSame([503, []], [$unavailable->getStatusCode(), $this->rolesReadBy]);
        }
    }

    #[DataProvider('unreadableRolesAnswers')]
    public function testAnUnreadableRolesAnswerIsAServiceUnavailableErrorNotAnEmptyRoleList(array $answer): void
    {
        $this->character('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);
        $this->esiResponses[$this->rolesEndpoint(self::ILYRA_ID)] = $answer;

        $this->expectException(ServiceUnavailableHttpException::class);

        $this->access->assertManages($this->user, self::CORPORATION);
    }

    public static function unreadableRolesAnswers(): iterable
    {
        yield 'roles absent' => [[]];
        yield 'roles as a string' => [['roles' => 'Accountant']];
        yield 'roles as null' => [['roles' => null]];
    }

    private function character(string $name, int $eveCharacterId, int $corporationId, array $roles, array $scopes = [self::ROLES_SCOPE], ?int $currentCorporationId = null): Character
    {
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken(
            (new EveToken())
                ->setAccessToken('access-token-' . $eveCharacterId)
                ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-05 19:20:00', new \DateTimeZone('UTC')))
                ->setScopes($scopes),
        );
        $this->user->addCharacter($character);
        $this->esiResponses[$this->rolesEndpoint($eveCharacterId)] = ['roles' => $roles];
        $this->esiResponses[$this->characterEndpoint($eveCharacterId)] = ['name' => $name, 'corporation_id' => $currentCorporationId ?? $corporationId];

        return $character;
    }

    private function characterEndpoint(int $eveCharacterId): string
    {
        return sprintf('/characters/%d/', $eveCharacterId);
    }

    private function rolesEndpoint(int $eveCharacterId): string
    {
        return sprintf('/characters/%d/roles/', $eveCharacterId);
    }
}

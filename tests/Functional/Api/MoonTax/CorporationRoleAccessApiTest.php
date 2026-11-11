<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use PHPUnit\Framework\Attributes\Group;

#[Group('issue-583')]
final class CorporationRoleAccessApiTest extends MoonTaxApiTestCase
{
    private const string ACCESS_PATH = '/api/mining/corporation-role-access';

    public function testWithoutTheRoleTheEndpointAnswersAStructuredStateInsteadOfAForbiddenMessage(): void
    {
        $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Auditor', 'Junior_Accountant']);
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::ILYRA_ID], 'GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=moon-tax');

        self::assertSame(
            [
                200,
                'role_missing',
                'Accountant',
                [['characterId' => self::ILYRA_ID, 'name' => 'Ilyra Voss', 'isMain' => true, 'state' => 'without_role', 'roles' => ['Auditor', 'Junior_Accountant']]],
            ],
            [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->responseJson()['requiredRole'] ?? null, $this->responseJson()['characters'] ?? null],
        );
    }

    public function testAnAccountantGetsAHeldStateWithoutAnyCharacterListed(): void
    {
        $user = $this->accountant();

        $this->request($user, 'GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=moon-tax');

        self::assertSame([200, 'held', []], [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->responseJson()['characters'] ?? null]);
    }

    public function testTheExtractionsFeatureAsksForTheStationManagerRole(): void
    {
        $user = $this->accountant();

        $this->request($user, 'GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=extractions');

        self::assertSame([200, 'role_missing', 'Station_Manager'], [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->responseJson()['requiredRole'] ?? null]);
    }

    public function testAnInvalidTokenIsAReconnectStateNotAMissingRole(): void
    {
        $character = $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);
        $character->markAuthInvalid();
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::ILYRA_ID], 'GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=moon-tax');

        self::assertSame(
            [200, 'reconnect', ['characterId' => self::ILYRA_ID, 'name' => 'Ilyra Voss', 'isMain' => true, 'state' => 'reconnect', 'roles' => null]],
            [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->responseJson()['characters'][0] ?? 'missing'],
        );
    }

    public function testAnUnknownFeatureIsABadRequest(): void
    {
        $user = $this->accountant();

        $this->request($user, 'GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=wallet');

        self::assertSame(400, $this->statusCode());
    }

    public function testAMissingFeatureIsABadRequest(): void
    {
        $user = $this->accountant();

        $this->request($user, 'GET', self::ACCESS_PATH . '/' . self::CORPORATION);

        self::assertSame(400, $this->statusCode());
    }

    public function testACorporationWithoutAnyCharacterOfTheUserRevealsNoCharacter(): void
    {
        $user = $this->accountant();
        $this->persistCharacterOfCorporation('Brann Holt', self::BRANN_ID, self::OTHER_OWNER_CORPORATION, ['Accountant']);
        $this->em->flush();

        $this->request($user, 'GET', self::ACCESS_PATH . '/' . self::OTHER_OWNER_CORPORATION . '?feature=moon-tax');

        self::assertSame([200, 'role_missing', []], [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->responseJson()['characters'] ?? null]);
    }

    public function testRecheckReadsTheRolesAgainAndAnswersTheFreshState(): void
    {
        $user = $this->accountant();

        $this->request($user, 'POST', self::ACCESS_PATH . '/' . self::CORPORATION . '/recheck?feature=moon-tax', []);

        self::assertSame([200, 'held', [self::ILYRA_ID]], [$this->statusCode(), $this->responseJson()['status'] ?? null, $this->charactersReading(self::rolesEndpoint(self::ILYRA_ID))]);
    }

    public function testTheEndpointNeedsAnAuthenticatedUser(): void
    {
        $this->client->request('GET', self::ACCESS_PATH . '/' . self::CORPORATION . '?feature=moon-tax', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->statusCode());
    }
}

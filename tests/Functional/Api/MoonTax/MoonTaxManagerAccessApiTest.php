<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use PHPUnit\Framework\Attributes\Group;

#[Group('issue-273')]
final class MoonTaxManagerAccessApiTest extends MoonTaxApiTestCase
{
    public function testAccountantReadsTheStatementsAndHisRolesAreReadDuringTheRequest(): void
    {
        $user = $this->accountant();

        $this->request($user, 'GET', self::BASE_PATH . '/' . self::CORPORATION . '/statements');

        self::assertSame([200, [], [self::ILYRA_ID]], [$this->statusCode(), $this->responseJson(), $this->charactersReading(self::rolesEndpoint(self::ILYRA_ID))]);
    }

    public function testUserWithoutAccountantIsRefusedWithTheRolesReadForEachCharacterOfTheCorporation(): void
    {
        $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Auditor', 'Junior_Accountant']);
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::ILYRA_ID], 'GET', self::BASE_PATH . '/' . self::CORPORATION . '/statements');

        self::assertSame(
            [403, true, true, true],
            [$this->statusCode(), str_contains($this->content(), 'Ilyra Voss'), str_contains($this->content(), 'Auditor'), str_contains($this->content(), 'Junior_Accountant')],
        );
    }

    public function testRolesReadFailureIsAServiceUnavailableErrorNotAGrantedAccess(): void
    {
        $user = $this->accountant();
        $this->failEsi(self::rolesEndpoint(self::ILYRA_ID), 500);

        $this->request($user, 'GET', self::BASE_PATH . '/' . self::CORPORATION . '/statements');

        self::assertSame(503, $this->statusCode());
    }

    public function testAccountantOfOneCorporationCannotReadTheStatementsOfAnotherCorporation(): void
    {
        $user = $this->accountant();
        $this->persistStatement('TAX-2026-W44-OTHR', self::DAX_ID, '2026-10-26', 6_000_000.0, self::ROCK_HOLLOW_ID, self::OTHER_OWNER_CORPORATION);

        $this->request($user, 'GET', self::BASE_PATH . '/' . self::OTHER_OWNER_CORPORATION . '/statements');

        self::assertSame([403, false], [$this->statusCode(), str_contains($this->content(), 'TAX-2026-W44-OTHR')]);
    }

    public function testMinerViewNeedsNoRoleAndNeverReadsTheRoles(): void
    {
        $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Director']);
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::ILYRA_ID], 'GET', self::BASE_PATH . '/mine');

        self::assertSame([200, []], [$this->statusCode(), $this->esiCallsTo(self::rolesEndpoint(self::ILYRA_ID))]);
    }
}

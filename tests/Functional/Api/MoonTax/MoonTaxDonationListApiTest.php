<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-369')]
final class MoonTaxDonationListApiTest extends MoonTaxApiTestCase
{
    private const int UNNAMED_ALT_ID = 2_112_000_201;
    private const int NAMED_ALT_ID = 2_112_000_202;
    private const int FRESH_ALT_ID = 2_112_000_203;
    private const int PROVISIONAL_DONATION = 7_003;
    private const int UNMATCHED_DONATION = 7_002;
    private const int DAX_DONATION = 7_001;
    private const int ATTRIBUTED_DONATION = 7_004;
    private const int EIGHTY_DAYS_DONATION = 7_005;
    private const int HUNDRED_DAYS_DONATION = 7_006;
    private const int OTHER_CORPORATION_DONATION = 7_007;
    private const string NAMED_ALT_NAME = 'Vex Orlen';
    private const array DONATION_FIELDS = ['journalId', 'corporationId', 'date', 'amount', 'reason', 'firstPartyId', 'firstPartyName', 'attributedCharacterId', 'attributedCharacterName', 'attributedAt', 'payerIsMiner'];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->respondWithCharacterNames([self::DAX_ID => 'Dax Orlen', self::MIRA_ID => 'Mira Venn', self::NAMED_ALT_ID => self::NAMED_ALT_NAME]);
        $this->manager = $this->accountant();
    }

    public function testManagerListsTheDonationsOfTheCorporationNewestFirstWithTheirFullContent(): void
    {
        $this->seedDonations();

        $this->request($this->manager, 'GET', $this->donationsPath());

        $rows = $this->donationRows();
        self::assertSame(
            [
                200,
                [self::PROVISIONAL_DONATION, self::UNMATCHED_DONATION, self::DAX_DONATION, self::ATTRIBUTED_DONATION, self::EIGHTY_DAYS_DONATION],
                [
                    'journalId' => self::UNMATCHED_DONATION,
                    'corporationId' => self::CORPORATION,
                    'date' => '2026-11-05T09:30:00+00:00',
                    'amount' => 1_250_000.0,
                    'reason' => null,
                    'firstPartyId' => self::UNNAMED_ALT_ID,
                    'firstPartyName' => null,
                    'attributedCharacterId' => null,
                    'attributedCharacterName' => null,
                    'attributedAt' => null,
                    'payerIsMiner' => false,
                ],
                [
                    'journalId' => self::DAX_DONATION,
                    'corporationId' => self::CORPORATION,
                    'date' => '2026-11-03T10:00:00+00:00',
                    'amount' => 4_000_000.0,
                    'reason' => 'weekly moon tax',
                    'firstPartyId' => self::DAX_ID,
                    'firstPartyName' => 'Dax Orlen',
                    'attributedCharacterId' => null,
                    'attributedCharacterName' => null,
                    'attributedAt' => null,
                    'payerIsMiner' => true,
                ],
                [
                    'journalId' => self::ATTRIBUTED_DONATION,
                    'corporationId' => self::CORPORATION,
                    'date' => '2026-10-20T18:15:00+00:00',
                    'amount' => 380_000.0,
                    'reason' => null,
                    'firstPartyId' => self::NAMED_ALT_ID,
                    'firstPartyName' => self::NAMED_ALT_NAME,
                    'attributedCharacterId' => self::DAX_ID,
                    'attributedCharacterName' => 'Dax Orlen',
                    'attributedAt' => '2026-10-21T08:00:00+00:00',
                    'payerIsMiner' => true,
                ],
            ],
            [
                $this->statusCode(),
                array_keys($rows),
                $rows[self::UNMATCHED_DONATION] ?? [],
                $rows[self::DAX_DONATION] ?? [],
                $rows[self::ATTRIBUTED_DONATION] ?? [],
            ],
        );
    }

    public function testPayerIsMinerIsTrueForAStatementOfTheCorporationProvisionalIncludedAndFalseOtherwise(): void
    {
        $this->seedDonations();

        $this->request($this->manager, 'GET', $this->donationsPath());

        $rows = $this->donationRows();
        self::assertSame(
            [
                self::PROVISIONAL_DONATION => true,
                self::UNMATCHED_DONATION => false,
                self::DAX_DONATION => true,
                self::ATTRIBUTED_DONATION => true,
                self::EIGHTY_DAYS_DONATION => false,
            ],
            array_map(static fn (array $row): mixed => $row['payerIsMiner'], $rows),
        );
    }

    public function testDonationOfEightyDaysIsListedAndDonationOfAHundredDaysIsNot(): void
    {
        $this->seedDonations();

        $this->request($this->manager, 'GET', $this->donationsPath());

        $rows = $this->donationRows();
        self::assertSame([true, false, 200], [isset($rows[self::EIGHTY_DAYS_DONATION]), isset($rows[self::HUNDRED_DAYS_DONATION]), $this->statusCode()]);
    }

    public function testDonationOfAnotherCorporationIsNeverListed(): void
    {
        $this->seedDonations();

        $this->request($this->manager, 'GET', $this->donationsPath());

        $rows = $this->donationRows();
        self::assertSame([200, true, false], [$this->statusCode(), isset($rows[self::DAX_DONATION]), isset($rows[self::OTHER_CORPORATION_DONATION])]);
    }

    public function testUserWithoutAccountantRoleIsRefusedAndSeesNoDonation(): void
    {
        $this->seedDonations();
        $this->persistCharacterOfCorporation('Brann Kolt', self::BRANN_ID, self::CORPORATION, ['Director']);
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::BRANN_ID], 'GET', $this->donationsPath());

        self::assertSame([403, false], [$this->statusCode(), str_contains($this->content(), (string) self::DAX_DONATION)]);
    }

    private function seedDonations(): void
    {
        $this->persistStatement('TAX-2026-W44-AAAA', self::DAX_ID, '2026-10-26', 6_000_000.0);
        $this->persistSetting(self::ROCK_HOLLOW_ID);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-10', 10_000);
        $this->persistDonation(self::DAX_DONATION, self::DAX_ID, 4_000_000.0, '2026-11-03 10:00:00')->setReason('weekly moon tax');
        $this->persistDonation(self::UNMATCHED_DONATION, self::UNNAMED_ALT_ID, 1_250_000.0, '2026-11-05 09:30:00');
        $this->persistDonation(self::PROVISIONAL_DONATION, self::MIRA_ID, 750_000.0, '2026-11-07 14:45:00')->setReason('moon rent');
        $this->persistDonation(self::ATTRIBUTED_DONATION, self::NAMED_ALT_ID, 380_000.0, '2026-10-20 18:15:00')
            ->setAttributedCharacterId(self::DAX_ID)
            ->setAttributedAt(new \DateTimeImmutable('2026-10-21 08:00:00', new \DateTimeZone('UTC')));
        $this->persistDonation(self::EIGHTY_DAYS_DONATION, self::FRESH_ALT_ID, 90_000.0, '2026-08-22 12:00:00');
        $this->persistDonation(self::HUNDRED_DAYS_DONATION, self::FRESH_ALT_ID, 60_000.0, '2026-08-02 12:00:00');
        $this->persistDonation(self::OTHER_CORPORATION_DONATION, self::DAX_ID, 9_000_000.0, '2026-11-06 10:00:00', self::OTHER_OWNER_CORPORATION);
    }

    private function donationsPath(): string
    {
        return self::BASE_PATH . '/' . self::CORPORATION . '/donations';
    }

    private function donationRows(): array
    {
        $rows = [];
        foreach (array_filter($this->responseJson(), static fn (mixed $item): bool => is_array($item) && isset($item['journalId'])) as $item) {
            $row = [];
            foreach (self::DONATION_FIELDS as $field) {
                self::assertArrayHasKey($field, $item);
                $row[$field] = $item[$field];
            }
            $row['date'] = $this->utc(new \DateTimeImmutable($row['date']));
            $row['attributedAt'] = $row['attributedAt'] === null ? null : $this->utc(new \DateTimeImmutable($row['attributedAt']));
            $row['amount'] = (float) $row['amount'];
            $rows[$row['journalId']] = $row;
        }

        return $rows;
    }
}

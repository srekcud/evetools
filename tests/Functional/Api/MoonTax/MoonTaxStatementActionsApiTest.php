<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxStatement;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-273')]
final class MoonTaxStatementActionsApiTest extends MoonTaxApiTestCase
{
    private const string DAX_STATEMENT = 'TAX-2026-W44-AAAA';
    private const string OTHER_CORPORATION_STATEMENT = 'TAX-2026-W44-OTHR';
    private const int ALT_OF_DAX_ID = 2_112_000_109;
    private const int ALT_DONATION = 7_001;
    private const int OTHER_CORPORATION_DONATION = 7_002;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->accountant();
        $this->persistStatement(self::DAX_STATEMENT, self::DAX_ID, '2026-10-26', 6_000_000.0);
        $this->persistStatement(self::OTHER_CORPORATION_STATEMENT, self::DAX_ID, '2026-10-26', 9_000_000.0, self::ROCK_HOLLOW_ID, self::OTHER_OWNER_CORPORATION);
        $this->persistDonation(self::ALT_DONATION, self::ALT_OF_DAX_ID, 6_000_000.0, '2026-11-03 10:00:00');
        $this->persistDonation(self::OTHER_CORPORATION_DONATION, self::ALT_OF_DAX_ID, 9_000_000.0, '2026-11-03 10:00:00', self::OTHER_OWNER_CORPORATION);
        $this->em->flush();
    }

    public function testManagerMarksAStatementPaidAndTheTraceKeepsWhoAndWhen(): void
    {
        $this->request($this->manager, 'POST', $this->statementPath(self::DAX_STATEMENT, 'mark-paid'), []);
        $actionStatus = $this->statusCode();
        $actionBody = $this->responseJson();

        $statement = $this->statement(self::DAX_STATEMENT);
        $this->request($this->manager, 'GET', $this->statementsPath());
        self::assertSame(
            [200, 'paid', self::NOW, self::NOW, (string) $this->manager->getId(), null, 'paid'],
            [
                $actionStatus,
                $actionBody['status'] ?? null,
                $this->utc($statement->getMarkedPaidAt()),
                $this->utc($statement->getActedAt()),
                (string) $statement->getActedBy()?->getId(),
                $this->utc($statement->getCancelledAt()),
                $this->statusOfListedStatement(self::DAX_STATEMENT),
            ],
        );
    }

    public function testManagerCancelsAStatementAndTheTraceKeepsWhoAndWhen(): void
    {
        $this->request($this->manager, 'POST', $this->statementPath(self::DAX_STATEMENT, 'cancel'), []);
        $actionStatus = $this->statusCode();

        $statement = $this->statement(self::DAX_STATEMENT);
        $this->request($this->manager, 'GET', $this->statementsPath());
        self::assertSame(
            [200, self::NOW, self::NOW, (string) $this->manager->getId(), null, 'cancelled'],
            [
                $actionStatus,
                $this->utc($statement->getCancelledAt()),
                $this->utc($statement->getActedAt()),
                (string) $statement->getActedBy()?->getId(),
                $this->utc($statement->getMarkedPaidAt()),
                $this->statusOfListedStatement(self::DAX_STATEMENT),
            ],
        );
    }

    public function testStatementOfAnotherCorporationCannotBeMarkedPaidNorCancelledThroughTheCorporationOfTheManager(): void
    {
        $this->request($this->manager, 'POST', $this->statementPath(self::DAX_STATEMENT, 'cancel'), []);
        $ownStatus = $this->statusCode();
        $this->request($this->manager, 'POST', $this->statementPath(self::OTHER_CORPORATION_STATEMENT, 'mark-paid'), []);
        $markPaidStatus = $this->statusCode();
        $this->request($this->manager, 'POST', $this->statementPath(self::OTHER_CORPORATION_STATEMENT, 'cancel'), []);

        $foreign = $this->statement(self::OTHER_CORPORATION_STATEMENT);
        self::assertSame([200, 404, 404, null, null], [$ownStatus, $markPaidStatus, $this->statusCode(), $foreign->getMarkedPaidAt(), $foreign->getCancelledAt()]);
    }

    public function testManagerAttributesTheDonationOfAnAltToTheMinerAndTheStatementIsSettledWithTheTrace(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath());
        $before = $this->statusOfListedStatement(self::DAX_STATEMENT);

        $this->request($this->manager, 'POST', $this->donationPath(self::ALT_DONATION), ['characterId' => self::DAX_ID]);
        $actionStatus = $this->statusCode();

        $donation = $this->donation(self::ALT_DONATION);
        $this->request($this->manager, 'GET', $this->statementsPath());
        self::assertSame(
            [['late', 200, self::DAX_ID, (string) $this->manager->getId(), self::NOW, 'paid']],
            [[$before, $actionStatus, $donation->getAttributedCharacterId(), (string) $donation->getAttributedBy()?->getId(), $this->utc($donation->getAttributedAt()), $this->statusOfListedStatement(self::DAX_STATEMENT)]],
        );
    }

    #[DataProvider('invalidCharacterIds')]
    public function testAttributionRefusesACharacterIdThatIsNotAPositiveInteger(int $characterId): void
    {
        $this->request($this->manager, 'POST', $this->donationPath(self::ALT_DONATION), ['characterId' => $characterId]);

        self::assertSame([422, null], [$this->statusCode(), $this->donation(self::ALT_DONATION)->getAttributedCharacterId()]);
    }

    public static function invalidCharacterIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-2_112_000_101];
    }

    public function testDonationOfAnotherCorporationOrUnknownCannotBeAttributedThroughTheCorporationOfTheManager(): void
    {
        $this->request($this->manager, 'POST', $this->donationPath(self::ALT_DONATION), ['characterId' => self::DAX_ID]);
        $ownStatus = $this->statusCode();
        $this->request($this->manager, 'POST', $this->donationPath(self::OTHER_CORPORATION_DONATION), ['characterId' => self::DAX_ID]);
        $otherCorporationStatus = $this->statusCode();
        $this->request($this->manager, 'POST', $this->donationPath(7_999), ['characterId' => self::DAX_ID]);

        self::assertSame([200, 404, 404, null], [$ownStatus, $otherCorporationStatus, $this->statusCode(), $this->donation(self::OTHER_CORPORATION_DONATION, self::OTHER_OWNER_CORPORATION)->getAttributedCharacterId()]);
    }

    private function statementsPath(): string
    {
        return self::BASE_PATH . '/' . self::CORPORATION . '/statements';
    }

    private function statementPath(string $reference, string $action): string
    {
        $statement = $this->statement($reference);

        return sprintf('%s/%s/%s', $this->statementsPath(), $statement->getId(), $action);
    }

    private function donationPath(int $journalId): string
    {
        return sprintf('%s/%d/donations/%d/attribute', self::BASE_PATH, self::CORPORATION, $journalId);
    }

    private function statement(string $reference): MoonTaxStatement
    {
        $this->em->clear();
        $statement = $this->em->getRepository(MoonTaxStatement::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($statement);

        return $statement;
    }

    private function donation(int $journalId, int $corporationId = self::CORPORATION): CorporationWalletDonation
    {
        $this->em->clear();
        $donation = $this->em->getRepository(CorporationWalletDonation::class)->findOneBy(['journalId' => $journalId, 'corporationId' => $corporationId]);
        self::assertNotNull($donation);

        return $donation;
    }

    private function statusOfListedStatement(string $reference): ?string
    {
        foreach ($this->responseJson() as $item) {
            if (is_array($item) && ($item['reference'] ?? null) === $reference) {
                return $item['status'] ?? null;
            }
        }

        return null;
    }
}

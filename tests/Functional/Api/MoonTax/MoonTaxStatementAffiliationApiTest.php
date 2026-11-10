<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-369')]
final class MoonTaxStatementAffiliationApiTest extends MoonTaxApiTestCase
{
    private const string FROZEN_WEEK = 'TAX-2026-W44-AAAA';
    private const string HOLLOW_NAME = 'Hollow Rock Mining';
    private const string SOLO_NAME = 'Solo Prospectors';
    private const string SABLE_NAME = 'Sable Reach Alliance';
    private const array AFFILIATION_FIELDS = ['recordedCorporationId', 'recordedCorporationName', 'allianceId', 'allianceName', 'affiliationKnown'];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->accountant();
    }

    public function testFrozenStatementCarriesTheRecordedCorporationItsAllianceAndTheirNames(): void
    {
        $this->persistStatement(self::FROZEN_WEEK, self::DAX_ID, '2026-10-26', 6_000_000.0)->setRecordedCorporationId(self::NEIGHBOR_CORPORATION);
        $this->respondWithCorporation(self::NEIGHBOR_CORPORATION, self::ALLIANCE, self::HOLLOW_NAME);
        $this->respondWithAlliance(self::ALLIANCE, self::SABLE_NAME);

        $this->request($this->manager, 'GET', $this->statementsPath());

        self::assertSame(
            [200, [self::NEIGHBOR_CORPORATION, self::HOLLOW_NAME, self::ALLIANCE, self::SABLE_NAME, true]],
            [$this->statusCode(), array_values($this->affiliationOf(self::FROZEN_WEEK))],
        );
    }

    public function testRecordedCorporationWithoutAllianceIsKnownWithoutAlliance(): void
    {
        $this->persistStatement(self::FROZEN_WEEK, self::DAX_ID, '2026-10-26', 6_000_000.0)->setRecordedCorporationId(self::LONER_CORPORATION);
        $this->respondWithCorporation(self::LONER_CORPORATION, null, self::SOLO_NAME);

        $this->request($this->manager, 'GET', $this->statementsPath());

        self::assertSame(
            [200, [self::LONER_CORPORATION, self::SOLO_NAME, null, null, true]],
            [$this->statusCode(), array_values($this->affiliationOf(self::FROZEN_WEEK))],
        );
    }

    public function testUnreadableRecordedCorporationLeavesTheStatementListedWithAnUnknownAffiliation(): void
    {
        $this->persistStatement(self::FROZEN_WEEK, self::DAX_ID, '2026-10-26', 6_000_000.0)->setRecordedCorporationId(self::NEIGHBOR_CORPORATION);
        $this->failEsi(self::corporationEndpoint(self::NEIGHBOR_CORPORATION), 500);

        $this->request($this->manager, 'GET', $this->statementsPath());

        self::assertSame(
            [200, [self::NEIGHBOR_CORPORATION, null, null, null, false]],
            [$this->statusCode(), array_values($this->affiliationOf(self::FROZEN_WEEK))],
        );
    }

    public function testProvisionalStatementCarriesTheLatestRecordedCorporationOfTheLedger(): void
    {
        $this->persistSetting(self::ROCK_HOLLOW_ID);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-10', 10_000, self::NEIGHBOR_CORPORATION);
        $this->respondWithCorporation(self::NEIGHBOR_CORPORATION, self::ALLIANCE, self::HOLLOW_NAME);
        $this->respondWithAlliance(self::ALLIANCE, self::SABLE_NAME);

        $this->request($this->manager, 'GET', $this->statementsPath());

        $provisional = array_values(array_filter($this->responseJson(), static fn (mixed $item): bool => is_array($item) && ($item['status'] ?? null) === 'provisional'));
        self::assertSame(
            [200, 1, [self::NEIGHBOR_CORPORATION, self::HOLLOW_NAME, self::ALLIANCE, self::SABLE_NAME, true]],
            [$this->statusCode(), count($provisional), $provisional === [] ? [] : array_values($this->affiliationFieldsOf($provisional[0]))],
        );
    }

    private function statementsPath(): string
    {
        return self::BASE_PATH . '/' . self::CORPORATION . '/statements';
    }

    private function affiliationOf(string $reference): array
    {
        $items = array_values(array_filter($this->responseJson(), static fn (mixed $item): bool => is_array($item) && ($item['reference'] ?? null) === $reference));
        self::assertCount(1, $items);

        return $this->affiliationFieldsOf($items[0]);
    }

    private function affiliationFieldsOf(array $item): array
    {
        $fields = [];
        foreach (self::AFFILIATION_FIELDS as $field) {
            self::assertArrayHasKey($field, $item);
            $fields[$field] = $item[$field];
        }

        return $fields;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-273')]
final class MoonTaxStatementsApiTest extends MoonTaxApiTestCase
{
    private const string FIRST_WEEK = 'TAX-2026-W44-AAAA';
    private const string SECOND_WEEK_MIRA = 'TAX-2026-W45-BBBB';
    private const string SECOND_WEEK_TOLA = 'TAX-2026-W45-CCCC';
    private const string FIRST_WEEK_START = '2026-10-26T00:00:00+00:00';
    private const string SECOND_WEEK_START = '2026-11-02T00:00:00+00:00';
    private const string THIRD_WEEK_START = '2026-11-09T00:00:00+00:00';
    private const string CSV_HEADER = 'reference,characterId,characterName,structureId,periodStart,periodEnd,tax,paid,remaining,status,outsideAlliance,unknownTypeIds';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->accountant();
        $this->persistStatement(self::FIRST_WEEK, self::DAX_ID, '2026-10-26', 6_000_000.0);
        $this->persistStatement(self::SECOND_WEEK_MIRA, self::MIRA_ID, '2026-11-02', 640_000.0)->setOutsideAlliance(true);
        $this->persistStatement(self::SECOND_WEEK_TOLA, self::TOLA_ID, '2026-11-02', null)->setUnknownTypeIds([self::XENOTIME, self::VELDSPAR]);
        $this->persistStatement('TAX-2026-W44-OTHR', self::DAX_ID, '2026-10-26', 9_000_000.0, self::ROCK_HOLLOW_ID, self::OTHER_OWNER_CORPORATION);
        $this->persistDonation(7_001, self::DAX_ID, 4_000_000.0, '2026-11-03 10:00:00');
        $this->persistDonation(7_002, self::DAX_ID, 9_000_000.0, '2026-11-03 10:00:00', self::OTHER_OWNER_CORPORATION);
    }

    public function testManagerListsTheStatementsOfHisCorporationOnlyWithTheirSettlementFiguresAndTheirNames(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath());

        $rows = $this->rowsByReference($this->responseJson());
        self::assertSame(
            [
                200,
                [self::FIRST_WEEK, self::SECOND_WEEK_MIRA, self::SECOND_WEEK_TOLA],
                [self::FIRST_WEEK => [self::DAX_ID, 'Dax Orlen', self::ROCK_HOLLOW_ID, self::FIRST_WEEK_START, self::SECOND_WEEK_START, 6_000_000.0, 4_000_000.0, 2_000_000.0, 'late', false, []]],
                [self::SECOND_WEEK_MIRA => [self::MIRA_ID, 'Mira Venn', self::ROCK_HOLLOW_ID, self::SECOND_WEEK_START, self::THIRD_WEEK_START, 640_000.0, 0.0, 640_000.0, 'due', true, []]],
                [self::SECOND_WEEK_TOLA => [self::TOLA_ID, null, self::ROCK_HOLLOW_ID, self::SECOND_WEEK_START, self::THIRD_WEEK_START, null, 0.0, null, 'unknown', false, [self::XENOTIME, self::VELDSPAR]]],
            ],
            [
                $this->statusCode(),
                array_keys($rows),
                array_intersect_key($rows, [self::FIRST_WEEK => 1]),
                array_intersect_key($rows, [self::SECOND_WEEK_MIRA => 1]),
                array_intersect_key($rows, [self::SECOND_WEEK_TOLA => 1]),
            ],
        );
    }

    public function testLateFilterKeepsTheLateStatementsOnly(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath() . '?late=true');

        self::assertSame([200, [self::FIRST_WEEK]], [$this->statusCode(), array_keys($this->rowsByReference($this->responseJson()))]);
    }

    public function testPeriodFilterKeepsTheStatementsOfThePeriodStartingThatDay(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath() . '?period=2026-11-02');

        self::assertSame([200, [self::SECOND_WEEK_MIRA, self::SECOND_WEEK_TOLA]], [$this->statusCode(), array_keys($this->rowsByReference($this->responseJson()))]);
    }

    public function testCsvExportRendersTheSameColumnsWithTheUnknownTypesJoinedBySemicolons(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath() . '?period=2026-11-02', null, 'text/csv');

        $lines = $this->statusCode() === 200 ? array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $this->content())))) : [''];
        $header = array_shift($lines);
        sort($lines);
        self::assertSame(
            [
                200,
                true,
                self::CSV_HEADER,
                [
                    'TAX-2026-W45-BBBB,2112000102,Mira Venn,1035000000101,2026-11-02T00:00:00+00:00,2026-11-09T00:00:00+00:00,640000,0,640000,due,1,',
                    'TAX-2026-W45-CCCC,2112000103,,1035000000101,2026-11-02T00:00:00+00:00,2026-11-09T00:00:00+00:00,,0,,unknown,0,45520;1230',
                ],
            ],
            [$this->statusCode(), str_starts_with((string) $this->client->getResponse()->headers->get('Content-Type'), 'text/csv'), $header, $lines],
        );
    }

    public function testPeriodFilterNeverLeaksTheStatementsOfAnotherCorporationOfTheSamePeriod(): void
    {
        $this->request($this->manager, 'GET', $this->statementsPath() . '?period=2026-10-26');

        self::assertSame([200, [self::FIRST_WEEK]], [$this->statusCode(), array_keys($this->rowsByReference($this->responseJson()))]);
    }

    private function statementsPath(): string
    {
        return self::BASE_PATH . '/' . self::CORPORATION . '/statements';
    }

    private function rowsByReference(array $items): array
    {
        $rows = [];
        foreach (array_filter($items, static fn (mixed $item): bool => is_array($item) && isset($item['reference'])) as $item) {
            $rows[$item['reference']] = [
                $item['characterId'],
                $item['characterName'],
                $item['structureId'],
                $this->utc(new \DateTimeImmutable($item['periodStart'])),
                $this->utc(new \DateTimeImmutable($item['periodEnd'])),
                $item['tax'] === null ? null : (float) $item['tax'],
                (float) $item['paid'],
                $item['remaining'] === null ? null : (float) $item['remaining'],
                $item['status'],
                $item['outsideAlliance'],
                $item['unknownTypeIds'],
            ];
        }
        ksort($rows);

        return $rows;
    }
}

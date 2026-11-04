<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-273')]
final class MoonTaxMineApiTest extends MoonTaxApiTestCase
{
    private const string FIRST_WEEK_START = '2026-10-26T00:00:00+00:00';
    private const string SECOND_WEEK_START = '2026-11-02T00:00:00+00:00';
    private const string CURRENT_WEEK_START = '2026-11-09T00:00:00+00:00';

    private User $dax;
    private User $mira;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistSetting(self::ROCK_HOLLOW_ID);
        $this->persistCharacterOfCorporation('Dax Orlen', self::DAX_ID, self::CORPORATION, []);
        $this->dax = $this->usersByEveCharacterId[self::DAX_ID];
        $this->persistCharacterForUser($this->dax, 'Tola Rask', self::TOLA_ID, self::NEIGHBOR_CORPORATION);
        $this->persistCharacterOfCorporation('Mira Venn', self::MIRA_ID, self::CORPORATION, []);
        $this->mira = $this->usersByEveCharacterId[self::MIRA_ID];
        $this->persistStatement('TAX-2026-W44-AAAA', self::DAX_ID, '2026-10-26', 6_000_000.0);
        $this->persistStatement('TAX-2026-W45-NNNN', self::TOLA_ID, '2026-11-02', 150_000.0, self::DEEP_CORE_ID, self::NEIGHBOR_CORPORATION);
        $this->persistStatement('TAX-2026-W45-MMMM', self::MIRA_ID, '2026-11-02', 640_000.0);
        $this->persistDonation(7_001, self::DAX_ID, 4_000_000.0, '2026-11-03 10:00:00');
        $this->persistDonation(7_002, self::TOLA_ID, 150_000.0, '2026-11-04 10:00:00', self::NEIGHBOR_CORPORATION);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-10', 5_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-10', 10_000);
        $this->em->flush();
    }

    public function testMinerSeesTheFrozenStatementsOfEveryCharacterOfHisAccountAcrossCorporationsAndNothingElse(): void
    {
        $this->request($this->dax, 'GET', self::BASE_PATH . '/mine');

        $rows = $this->rowsByCharacterAndPeriod($this->responseJson());
        self::assertSame(
            [
                200,
                [self::DAX_ID . '|' . self::FIRST_WEEK_START, self::DAX_ID . '|' . self::CURRENT_WEEK_START, self::TOLA_ID . '|' . self::SECOND_WEEK_START],
                [self::CORPORATION, self::ROCK_HOLLOW_ID, 'TAX-2026-W44-AAAA', 6_000_000.0, 4_000_000.0, 2_000_000.0, 'late', []],
                [self::NEIGHBOR_CORPORATION, self::DEEP_CORE_ID, 'TAX-2026-W45-NNNN', 150_000.0, 150_000.0, 0.0, 'paid', []],
            ],
            [
                $this->statusCode(),
                array_keys($rows),
                $rows[self::DAX_ID . '|' . self::FIRST_WEEK_START] ?? null,
                $rows[self::TOLA_ID . '|' . self::SECOND_WEEK_START] ?? null,
            ],
        );
    }

    public function testPeriodInProgressIsComputedAtReadAsProvisionalWithoutReference(): void
    {
        $this->request($this->dax, 'GET', self::BASE_PATH . '/mine');

        $rows = $this->rowsByCharacterAndPeriod($this->responseJson());
        $provisional = $rows[self::DAX_ID . '|' . self::CURRENT_WEEK_START] ?? [];
        self::assertSame(
            [self::CORPORATION, self::ROCK_HOLLOW_ID, null, 1_500_000.0, 'provisional'],
            [$provisional[0] ?? null, $provisional[1] ?? null, $provisional[2] ?? null, $provisional[3] ?? null, $provisional[6] ?? null],
        );
    }

    public function testAnotherMinerOnlySeesHisOwnStatements(): void
    {
        $this->request($this->mira, 'GET', self::BASE_PATH . '/mine');

        $rows = $this->rowsByCharacterAndPeriod($this->responseJson());
        self::assertSame(
            [self::MIRA_ID . '|' . self::SECOND_WEEK_START, self::MIRA_ID . '|' . self::CURRENT_WEEK_START],
            array_keys($rows),
        );
    }

    private function rowsByCharacterAndPeriod(array $items): array
    {
        $rows = [];
        foreach (array_filter($items, static fn (mixed $item): bool => is_array($item) && isset($item['characterId'], $item['periodStart'])) as $item) {
            $rows[$item['characterId'] . '|' . $this->utc(new \DateTimeImmutable($item['periodStart']))] = [
                $item['corporationId'],
                $item['structureId'],
                $item['reference'],
                $item['tax'] === null ? null : (float) $item['tax'],
                (float) $item['paid'],
                $item['remaining'] === null ? null : (float) $item['remaining'],
                $item['status'],
                $item['unknownTypeIds'],
            ];
        }
        ksort($rows);

        return $rows;
    }
}

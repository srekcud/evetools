<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Planetary\PlanetaryPlannerUserPrices;
use App\Tests\Unit\Support\BuildsPlanetaryPlannerUserPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPlannerUserPrices::class)]
#[Group('issue-1454')]
final class PlanetaryPlannerUserPricesTest extends TestCase
{
    use BuildsPlanetaryPlannerUserPrices;

    private const int WATER = 3645;
    private const int BACTERIA = 2393;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int UNPRICED_TYPE = 9999;
    private const int HUB_STRUCTURE_ID = 1_040_000_000_007;
    private const int OTHER_STRUCTURE_ID = 1_040_000_000_008;
    private const string NOW = '2026-11-26T10:00:00+00:00';

    public function testTheHubPriceIsTheLowestSellPriceOfTheHubStructureAndThePaidPriceTheWeightedAverageOfThePurchases(): void
    {
        $pilot = $this->pilot(2112000001);
        $prices = $this->plannerUserPrices(
            self::HUB_STRUCTURE_ID,
            [self::WATER => 61.5, self::BACTERIA => 85.5, self::COOLANT => 410.5],
            true,
            [
                $this->plannerPurchase($pilot, self::WATER, 10, 1_000.0, '2026-11-20T12:00:00+00:00'),
                $this->plannerPurchase($pilot, self::WATER, 30, 2_000.0, '2026-11-21T12:00:00+00:00'),
                $this->plannerPurchase($pilot, self::COOLANT, 4, 300.0, '2026-11-22T12:00:00+00:00'),
            ],
            [self::WATER, self::BACTERIA, self::COOLANT],
        );

        $report = $prices->read($this->userOf($pilot), [self::WATER, self::BACTERIA, self::COOLANT], self::HUB_STRUCTURE_ID, new \DateTimeImmutable(self::NOW));

        $this->assertSame([
            self::WATER => ['hub' => 61.5, 'paid' => 1_750.0],
            self::BACTERIA => ['hub' => 85.5, 'paid' => null],
            self::COOLANT => ['hub' => 410.5, 'paid' => 300.0],
        ], $report['prices']);
    }

    public function testTheReportSaysWhetherTheHubStructureHasCachedMarketData(): void
    {
        $pilot = $this->pilot(2112000001);
        $withData = $this->plannerUserPrices(self::HUB_STRUCTURE_ID, [self::WATER => 61.5], true, [], [self::WATER]);
        $withoutData = $this->plannerUserPrices(self::HUB_STRUCTURE_ID, [], false, [], [self::WATER]);
        $now = new \DateTimeImmutable(self::NOW);

        $this->assertTrue($withData->read($this->userOf($pilot), [self::WATER], self::HUB_STRUCTURE_ID, $now)['hasHubData']);
        $this->assertFalse($withoutData->read($this->userOf($pilot), [self::WATER], self::HUB_STRUCTURE_ID, $now)['hasHubData']);
        $this->assertFalse($withData->read($this->userOf($pilot), [self::WATER], self::OTHER_STRUCTURE_ID, $now)['hasHubData']);
    }

    public function testATypeWithNoHubOrderNorPurchaseIsNullOnBothPricesNeverZero(): void
    {
        $pilot = $this->pilot(2112000001);
        $prices = $this->plannerUserPrices(self::HUB_STRUCTURE_ID, [self::WATER => 61.5], true, [], [self::WATER]);

        $report = $prices->read($this->userOf($pilot), [self::WATER, self::UNPRICED_TYPE], self::HUB_STRUCTURE_ID, new \DateTimeImmutable(self::NOW));

        $this->assertSame([
            self::WATER => ['hub' => 61.5, 'paid' => null],
            self::UNPRICED_TYPE => ['hub' => null, 'paid' => null],
        ], $report['prices']);
    }

    public function testAHubWithoutCachedDataGivesNullHubPricesWhileThePaidPricesStayKnown(): void
    {
        $pilot = $this->pilot(2112000001);
        $prices = $this->plannerUserPrices(
            self::HUB_STRUCTURE_ID,
            [],
            false,
            [$this->plannerPurchase($pilot, self::WATER, 10, 1_000.0, '2026-11-20T12:00:00+00:00')],
            [self::WATER],
        );

        $report = $prices->read($this->userOf($pilot), [self::WATER], self::HUB_STRUCTURE_ID, new \DateTimeImmutable(self::NOW));

        $this->assertSame([self::WATER => ['hub' => null, 'paid' => 1_000.0]], $report['prices']);
    }

    public function testOnlyThePurchasesOfTheLastNinetyDaysOfTheUserCountForThePaidPrice(): void
    {
        $pilot = $this->pilot(2112000001);
        $stranger = $this->pilot(2112000002);
        $prices = $this->plannerUserPrices(
            self::HUB_STRUCTURE_ID,
            [],
            true,
            [
                $this->plannerPurchase($pilot, self::WATER, 10, 1_000.0, '2026-08-28T10:00:00+00:00'),
                $this->plannerPurchase($pilot, self::BACTERIA, 10, 700.0, '2026-08-28T09:59:59+00:00'),
                $this->plannerPurchase($stranger, self::COOLANT, 10, 9_000.0, '2026-11-20T12:00:00+00:00'),
            ],
            [self::WATER, self::BACTERIA, self::COOLANT],
        );

        $report = $prices->read($this->userOf($pilot), [self::WATER, self::BACTERIA, self::COOLANT], self::HUB_STRUCTURE_ID, new \DateTimeImmutable(self::NOW));

        $this->assertSame(1_000.0, $report['prices'][self::WATER]['paid']);
        $this->assertNull($report['prices'][self::BACTERIA]['paid']);
        $this->assertNull($report['prices'][self::COOLANT]['paid']);
    }

    public function testATypeThatNoSchematicConsumesKeepsItsHubPriceAndHasNoPaidPrice(): void
    {
        $pilot = $this->pilot(2112000001);
        $prices = $this->plannerUserPrices(
            self::HUB_STRUCTURE_ID,
            [self::ROBOTICS => 31_000.0],
            true,
            [$this->plannerPurchase($pilot, self::ROBOTICS, 2, 29_000.0, '2026-11-20T12:00:00+00:00')],
            [self::WATER],
        );

        $report = $prices->read($this->userOf($pilot), [self::ROBOTICS], self::HUB_STRUCTURE_ID, new \DateTimeImmutable(self::NOW));

        $this->assertSame([self::ROBOTICS => ['hub' => 31_000.0, 'paid' => null]], $report['prices']);
    }

    private function pilot(int $eveCharacterId): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot '.$eveCharacterId);
    }

    private function userOf(Character $character): User
    {
        $user = new User();
        $user->addCharacter($character);

        return $user;
    }
}

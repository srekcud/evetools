<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Ledger\MiningDailyStatsResource;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\MiningStatsDailyProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningStatsDailyProvider::class)]
#[Group('issue-260')]
final class MiningStatsDailyProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float VALUE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;

    public function testOnlyTheDayHoldingATypeWithoutPriceIsUnknownAndDaysWithoutEntryAreZero(): void
    {
        $knownDay = (new \DateTimeImmutable('-20 days'))->format('Y-m-d');
        $unknownDay = (new \DateTimeImmutable('-19 days'))->format('Y-m-d');
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            $knownDay => [self::BITUMENS => 1000],
            $unknownDay => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $miningEntryRepository->method('getDailyTotals')->willReturn([
            $knownDay => ['date' => $knownDay, 'totalValue' => 0.0, 'totalQuantity' => 1000],
            $unknownDay => ['date' => $unknownDay, 'totalValue' => 0.0, 'totalQuantity' => 5650],
        ]);

        $dailyStats = $this->createProvider($miningEntryRepository)->provide(new Get());

        $daysByDate = array_combine(
            array_map(static fn (MiningDailyStatsResource $day): string => $day->date, $dailyStats->daily),
            $dailyStats->daily,
        );
        $this->assertCount(31, $daysByDate);
        $this->assertEqualsWithDelta(831214.8, $daysByDate[$knownDay]->totalValue, self::VALUE_DELTA);
        $this->assertSame(1000, $daysByDate[$knownDay]->totalQuantity);
        $this->assertNull($daysByDate[$unknownDay]->totalValue);
        $this->assertSame(5650, $daysByDate[$unknownDay]->totalQuantity);
        unset($daysByDate[$knownDay], $daysByDate[$unknownDay]);
        $this->assertSame(
            array_fill(0, 29, [0.0, 0]),
            array_map(
                static fn (MiningDailyStatsResource $day): array => [$day->totalValue, $day->totalQuantity],
                array_values($daysByDate),
            ),
        );
        $this->assertObjectHasProperty('missingPriceTypeIds', $dailyStats);
        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE], $dailyStats->missingPriceTypeIds);
    }

    private function createProvider(MiningEntryRepository $miningEntryRepository): MiningStatsDailyProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new MiningStatsDailyProvider(
            $security,
            $miningEntryRepository,
            $settingsRepository,
            new MiningBestValueCalculator(
                $miningEntryRepository,
                $this->createOreValueService($this->jitaSellPricesWithoutCompressedOre()),
            ),
            $requestStack,
        );
    }
}

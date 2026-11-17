<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\Mining\MiningRevenueGridBuilder;
use App\Service\MiningBestValueCalculator;
use App\State\Provider\Ledger\MiningStatsGridProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MiningStatsGridProvider::class)]
#[Group('issue-1066')]
final class MiningStatsGridProviderTest extends TestCase
{
    public function testItExposesThePeriodTheColumnsAndTheNet(): void
    {
        $settings = new UserLedgerSettings();
        $settings->setCorpProjectAccounting(UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING);

        $grid = $this->createProvider(new User(), new Request(['days' => '14']), $settings)->provide(new Get());

        $this->assertSame(14, $grid->period['days']);
        $this->assertSame(['sold', 'corp_project', 'unknown'], array_keys($grid->columns));
        $this->assertSame(0.0, $grid->net['value']);
        $this->assertSame([], $grid->missingPriceTypeIds);
        $this->assertFalse($grid->corpProjectCountedInPve);
    }

    public function testItDefaultsToThirtyDays(): void
    {
        $this->assertSame(30, $this->createProvider(new User(), new Request(), null)->provide(new Get())->period['days']);
    }

    public function testItTellsWhenPveCountsTheCorpProjectOre(): void
    {
        $this->assertTrue($this->createProvider(new User(), new Request(), new UserLedgerSettings())->provide(new Get())->corpProjectCountedInPve);
    }

    public function testItRefusesAPeriodOutsideTheAllowedRange(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->createProvider(new User(), new Request(['days' => '0']), null)->provide(new Get());
    }

    public function testItRefusesAnAnonymousVisitor(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->createProvider(null, new Request(), null)->provide(new Get());
    }

    private function createProvider(?User $user, Request $request, ?UserLedgerSettings $settings): MiningStatsGridProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $entryRepository = $this->createStub(MiningEntryRepository::class);
        $entryRepository->method('getQuantitiesByUsageDayTypeAndCharacter')->willReturn([]);
        $calculator = $this->createStub(MiningBestValueCalculator::class);
        $calculator->method('getBestUnitPrices')->willReturn([]);
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn($settings);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new MiningStatsGridProvider($security, new MiningRevenueGridBuilder($entryRepository, $calculator), $settingsRepository, $requestStack);
    }
}

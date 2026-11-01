<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\State\Provider\Pve\IncomeProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(IncomeProvider::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-294')]
final class IncomeProviderDaysBoundsTest extends TestCase
{
    private array $requestedPeriods = [];

    #[DataProvider('daysOutsideOneToThreeThousandSixHundredFifty')]
    public function testDaysOutsideOneToThreeThousandSixHundredFiftyIsABadRequestWithoutAnyIncomeRead(string $days): void
    {
        $provider = $this->createProvider(new Request(['days' => $days]));

        try {
            $provider->provide(new Get());
            $this->fail("days={$days} was accepted");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('days must be an integer between 1 and 3650', $exception->getMessage());
        }

        $this->assertSame([], $this->requestedPeriods);
    }

    public static function daysOutsideOneToThreeThousandSixHundredFifty(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'one more than ten years' => ['3651'];
        yield 'far beyond ten years' => ['100000'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['7.5'];
    }

    #[DataProvider('acceptedDays')]
    public function testIncomePeriodCoversTheRequestedDays(Request $request, int $expectedDays): void
    {
        $provider = $this->createProvider($request);

        $income = $provider->provide(new Get());

        $this->assertSame(
            [
                'from' => (new \DateTimeImmutable("-{$expectedDays} days"))->format('Y-m-d'),
                'to' => (new \DateTimeImmutable())->format('Y-m-d'),
                'days' => $expectedDays,
            ],
            $income->period,
        );
        $this->assertSame([$expectedDays], $this->requestedPeriods);
    }

    public static function acceptedDays(): iterable
    {
        yield 'days absent is thirty days' => [new Request(), 30];
        yield 'one day' => [new Request(['days' => '1']), 1];
        yield 'ninety days' => [new Request(['days' => '90']), 90];
        yield 'three thousand six hundred fifty days' => [new Request(['days' => '3650']), 3650];
    }

    private function createProvider(Request $request): IncomeProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findBountiesByUserAndDateRange')->willReturnCallback(
            function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
                $this->requestedPeriods[] = $from->diff($to)->days;

                return [];
            },
        );
        $incomeRepository->method('getTotalBountiesByUserAndDateRange')->willReturn(18400000.0);
        $incomeRepository->method('findLootSalesByUserAndDateRange')->willReturn([]);
        $incomeRepository->method('getTotalLootSalesByUserAndDateRange')->willReturn(6250000.0);

        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturn(2175000.0);
        $expenseRepository->method('getTotalsByTypeAndDateRange')->willReturn([]);

        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new IncomeProvider($security, $incomeRepository, $expenseRepository, $settingsRepository, $requestStack);
    }
}

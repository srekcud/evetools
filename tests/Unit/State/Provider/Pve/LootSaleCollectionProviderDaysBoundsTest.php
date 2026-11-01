<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\State\Provider\Pve\LootSaleCollectionProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(LootSaleCollectionProvider::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-294')]
final class LootSaleCollectionProviderDaysBoundsTest extends TestCase
{
    private array $requestedPeriods = [];

    #[DataProvider('daysOutsideOneToThreeThousandSixHundredFifty')]
    public function testDaysOutsideOneToThreeThousandSixHundredFiftyIsABadRequestWithoutAnyLootSaleRead(string $days): void
    {
        $provider = $this->createProvider(new Request(['days' => $days]));

        try {
            $provider->provide(new GetCollection());
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
    public function testLootSalesAreReadOverTheRequestedDays(Request $request, int $expectedDays): void
    {
        $provider = $this->createProvider($request);

        $lootSales = $provider->provide(new GetCollection());

        $this->assertSame([], $lootSales);
        $this->assertSame(
            [[(new \DateTimeImmutable("-{$expectedDays} days"))->format('Y-m-d'), (new \DateTimeImmutable())->format('Y-m-d'), $expectedDays]],
            $this->requestedPeriods,
        );
    }

    public static function acceptedDays(): iterable
    {
        yield 'days absent is thirty days' => [new Request(), 30];
        yield 'one day' => [new Request(['days' => '1']), 1];
        yield 'one hundred twenty days' => [new Request(['days' => '120']), 120];
        yield 'three thousand six hundred fifty days' => [new Request(['days' => '3650']), 3650];
    }

    private function createProvider(Request $request): LootSaleCollectionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findByUserAndDateRange')->willReturnCallback(
            function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
                $this->requestedPeriods[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), $from->diff($to)->days];

                return [];
            },
        );

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new LootSaleCollectionProvider($security, $incomeRepository, $requestStack);
    }
}

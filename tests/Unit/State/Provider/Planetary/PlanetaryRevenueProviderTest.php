<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryRevenueResource;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryRevenueCalculator;
use App\State\Provider\Planetary\PlanetaryRevenueProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryRevenueProvider::class)]
#[Group('issue-1068')]
final class PlanetaryRevenueProviderTest extends TestCase
{
    public function testTheDefaultPeriodIsThirtyDays(): void
    {
        $resource = $this->provide(new User(), []);

        $this->assertSame(30, $resource->days);
    }

    public function testTheRequestedPeriodIsUsed(): void
    {
        $resource = $this->provide(new User(), ['days' => '90']);

        $this->assertSame(90, $resource->days);
    }

    public function testAnEmptyAccountGivesNoRealisedRevenueAndAnUnknownChange(): void
    {
        $resource = $this->provide(new User(), []);

        $this->assertSame([0.0, null, [], []], [$resource->realisedIsk, $resource->realisedChange, $resource->toFix, $resource->estimate['contributors']]);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($resource->tiers, 'tier'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDays(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'above one year' => ['366'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['1.5'];
    }

    #[DataProvider('invalidDays')]
    public function testAnInvalidPeriodIsRejected(string $days): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->provide(new User(), ['days' => $days]);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null, []);
    }

    /**
     * @param array<string, string> $query
     */
    private function provide(?User $user, array $query): PlanetaryRevenueResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn([]);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateTotalDailyIsk')->willReturn(0.0);

        $calculator = new PlanetaryRevenueCalculator(
            $this->createStub(CachedWalletTransactionRepository::class),
            $colonyRepository,
            $schematicTypeRepository,
            $productionCalculator,
        );

        return (new PlanetaryRevenueProvider($security, $requestStack, $calculator))->provide(new Get());
    }
}

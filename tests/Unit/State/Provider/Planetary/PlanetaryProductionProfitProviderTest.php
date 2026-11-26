<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryProductionProfitResource;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductionProfitCalculator;
use App\Service\StructureMarketService;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\TypeNameResolver;
use App\State\Provider\Planetary\PlanetaryProductionProfitProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryProductionProfitProvider::class)]
#[Group('issue-1400')]
final class PlanetaryProductionProfitProviderTest extends TestCase
{
    private const int DEFAULT_HUB = 1049588174021;

    public function testAnAccountWithoutProductionGivesAnEmptyProfitOnTheDefaultHub(): void
    {
        $resource = $this->provide(new User());

        $this->assertSame([[], 0.0, 0, ['days' => 30, 'perDay' => null]], [$resource->lines, $resource->profitPerDay, $resource->unknownLineCount, $resource->taxes]);
        $this->assertSame(self::DEFAULT_HUB, $resource->hub['structureId']);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null);
    }

    private function provide(?User $user): PlanetaryProductionProfitResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn([]);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateProduction')->willReturn(['tiers' => [], 'totalDailyIsk' => 0.0, 'totalMonthlyIsk' => 0.0]);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $paidPriceCalculator = new PlanetaryPaidPriceCalculator(
            $this->createStub(CachedWalletTransactionRepository::class),
            $schematicTypeRepository,
            $this->createStub(TypeNameResolver::class),
            new WalletReadingStateReader($this->createStub(CharacterWalletReadingRepository::class)),
        );
        $journalRepository = $this->createStub(CachedWalletJournalEntryRepository::class);
        $journalRepository->method('planetaryTaxesByColonyAndDay')->willReturn([]);

        $calculator = new PlanetaryProductionProfitCalculator(
            $colonyRepository,
            $productionCalculator,
            $paidPriceCalculator,
            $this->createStub(StructureMarketService::class),
            $journalRepository,
            self::DEFAULT_HUB,
            'Default Hub',
        );

        return (new PlanetaryProductionProfitProvider($security, $calculator))->provide(new Get());
    }
}

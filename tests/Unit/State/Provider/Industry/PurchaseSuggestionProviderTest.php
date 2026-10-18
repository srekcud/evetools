<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\PurchaseSuggestionProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(PurchaseSuggestionProvider::class)]
#[Group('issue-115')]
final class PurchaseSuggestionProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-115000000002';
    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4314;
    private const int CAPITAL_CONSTRUCTION_PARTS_TYPE_ID = 21037;
    private const int CAPITAL_CONSTRUCTION_PARTS_BLUEPRINT_TYPE_ID = 21038;
    private const int JITA_4_4_STATION_ID = 60003760;

    public function testWalletTransactionOnATypeMissingFromTheSdeIsSuggestedWithAnUnknownTypeNameAndUnchangedQuantityAndPrices(): void
    {
        $pilot = new User();
        $character = (new Character())->setName('Aurora Industrialist');
        $pilot->addCharacter($character);

        $project = (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId(self::CAPITAL_CONSTRUCTION_PARTS_TYPE_ID)
            ->setRuns(2);
        $project->addStep($this->step(self::CAPITAL_CONSTRUCTION_PARTS_BLUEPRINT_TYPE_ID, self::CAPITAL_CONSTRUCTION_PARTS_TYPE_ID, 2, 0));
        $project->addStep($this->step(self::ROBOTICS_BLUEPRINT_TYPE_ID, self::ROBOTICS_TYPE_ID, 24, 1));
        $project->addStep($this->step(self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1));

        $roboticsPurchase = $this->buy($character, 6_100_009_848, self::ROBOTICS_TYPE_ID, 24, 98_500.0);
        $nitrogenFuelBlockPurchase = $this->buy($character, 6_100_004_051, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 400, 15_000.0);

        $result = $this->provider($pilot, $project, [$roboticsPurchase, $nitrogenFuelBlockPurchase])
            ->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame(2, $result->totalCount);
        self::assertSame(self::ROBOTICS_TYPE_ID, $result->suggestions[0]->typeId);
        self::assertNull($result->suggestions[0]->typeName);
        self::assertSame(24, $result->suggestions[0]->quantity);
        self::assertSame(98_500.0, $result->suggestions[0]->unitPrice);
        self::assertSame(2_364_000.0, $result->suggestions[0]->totalPrice);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_TYPE_ID, $result->suggestions[1]->typeId);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $result->suggestions[1]->typeName);
        self::assertSame(400, $result->suggestions[1]->quantity);
        self::assertSame(6_000_000.0, $result->suggestions[1]->totalPrice);
    }

    /**
     * @param list<CachedWalletTransaction> $transactions
     */
    private function provider(User $pilot, IndustryProject $project, array $transactions): PurchaseSuggestionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByTypeIds')->willReturn($transactions);

        return new PurchaseSuggestionProvider(
            $security,
            $projectRepository,
            $transactionRepository,
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function step(int $blueprintTypeId, int $productTypeId, int $runs, int $depth): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($runs)
            ->setRuns($runs)
            ->setDepth($depth)
            ->setActivityType('manufacturing')
            ->setSortOrder($depth);
    }

    private function buy(Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(self::JITA_4_4_STATION_ID)
            ->setClientId(1_000_125)
            ->setDate(new \DateTimeImmutable('2026-10-15T12:00:00+00:00'));
    }
}

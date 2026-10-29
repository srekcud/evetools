<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\PurchaseSuggestionResource;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\State\Provider\Industry\PurchaseSuggestionProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(PurchaseSuggestionProvider::class)]
final class PurchaseSuggestionProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-115000000002';
    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4314;
    private const int CAPITAL_CONSTRUCTION_PARTS_TYPE_ID = 21037;
    private const int CAPITAL_CONSTRUCTION_PARTS_BLUEPRINT_TYPE_ID = 21038;
    private const int JITA_4_4_STATION_ID = 60003760;

    private const int MANUFACTURING_BLUEPRINT_TYPE_ID = 9200;
    private const int REACTION_FORMULA_TYPE_ID = 9300;

    private array $existingPurchases = [];

    private array $purchaseLookups = [];

    private array $materialLookups = [];

    private array $buyLookups = [];

    #[Group('issue-115')]
    public function testWalletTransactionOnATypeMissingFromTheSdeIsSuggestedWithAnUnknownTypeNameAndUnchangedQuantityAndPrices(): void
    {
        $character = (new Character())->setName('Aurora Industrialist');
        $pilot = $this->pilotWith($character);

        $project = (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId(self::CAPITAL_CONSTRUCTION_PARTS_TYPE_ID)
            ->setRuns(2);
        $project->addStep($this->step(self::CAPITAL_CONSTRUCTION_PARTS_BLUEPRINT_TYPE_ID, self::CAPITAL_CONSTRUCTION_PARTS_TYPE_ID, 2, 0));
        $project->addStep($this->step(self::ROBOTICS_BLUEPRINT_TYPE_ID, self::ROBOTICS_TYPE_ID, 24, 1));
        $project->addStep($this->step(self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1));

        $roboticsPurchase = $this->buy($character, 6_100_009_848, self::ROBOTICS_TYPE_ID, 24, 98_500.0);
        $nitrogenFuelBlockPurchase = $this->buy($character, 6_100_004_051, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 400, 15_000.0);

        $result = $this->provider($pilot, $project, ['Aurora Industrialist' => [$roboticsPurchase, $nitrogenFuelBlockPurchase]])
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

    #[Group('issue-205')]
    public function testG1WalletTransactionsLinkedOnlyToOtherProjectsAreNotSuggestedAndThoseLinkedToTheCurrentProjectAreMarkedLinked(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $otherPilot = $this->pilotWith((new Character())->setName('Other Pilot'));
        $j1 = $this->projectOf($u1, 9000);
        $j1->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9000, 4, 0));
        $s1b = $this->step(9201, 9101, 30, 1);
        $j1->addStep($s1b);
        $j1->addStep($this->step(9203, 9102, 12, 1));
        $s2 = $this->step(9202, 9010, 2, 0);
        $this->projectOf($u1, 9010)->addStep($s2);
        $s9 = $this->step(9209, 9090, 1, 0);
        $this->projectOf($otherPilot, 9090)->addStep($s9);

        $t1 = $this->buy($p1, 6_400_000_001, 9101, 120, 5.25);
        $t2 = $this->buy($p1, 6_400_000_002, 9101, 75, 5.4);
        $t3 = $this->buy($p1, 6_400_000_003, 9102, 50, 18.0);
        $t4 = $this->buy($p1, 6_400_000_004, 9102, 35, 17.5);
        $t5 = $this->buy($p1, 6_400_000_005, 9101, 90, 5.1);
        $t6 = $this->buy($p1, 6_400_000_006, 9102, 20, 19.25);
        $this->linkInAPurchase($s2, $t2);
        $this->linkInAPurchase($s1b, $t3);
        $this->linkInAPurchase($s2, $t4);
        $this->linkInAPurchase($s2, $t4);
        $this->linkInAPurchase($s1b, $t5);
        $this->linkInAPurchase($s2, $t5);
        $this->linkInAPurchase($s9, $t6);

        $result = $this->provider($u1, $j1, ['Pilot One' => [$t1, $t2, $t3, $t4, $t5, $t6]])
            ->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame(
            [3, [[6_400_000_001, false], [6_400_000_003, true], [6_400_000_005, true]]],
            [$result->totalCount, $this->transactionIdsAndLinkedFlagsOf($result->suggestions)],
        );
        self::assertSame([[6_400_000_001, 6_400_000_002, 6_400_000_003, 6_400_000_004, 6_400_000_005, 6_400_000_006]], $this->purchaseLookups);
        self::assertSame([], $this->materialLookups);
    }

    #[Group('issue-205')]
    public function testG2NoWalletTransactionFoundGivesNoSuggestionWithoutReadingThePurchases(): void
    {
        $u1 = $this->pilotWith((new Character())->setName('Pilot One'));
        $j1 = $this->projectOf($u1, 9000);
        $j1->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9000, 4, 0));
        $j1->addStep($this->step(9201, 9101, 30, 1));

        $result = $this->provider($u1, $j1, [])->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame([0, [], [['Pilot One', [9101]]], []], [$result->totalCount, $result->suggestions, $this->buyLookups, $this->purchaseLookups]);
    }

    #[Group('issue-205')]
    public function testG3WalletTransactionsOfEveryCharacterAreCheckedAgainstThePurchasesInASingleLookup(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $p2 = (new Character())->setName('Pilot Two');
        $u1 = $this->pilotWith($p1, $p2);
        $j1 = $this->projectOf($u1, 9000);
        $j1->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9000, 4, 0));
        $j1->addStep($this->step(9201, 9101, 30, 1));
        $j1->addStep($this->step(9203, 9102, 12, 1));
        $t1 = $this->buy($p1, 6_400_000_001, 9101, 120, 5.25);
        $t7 = $this->buy($p2, 6_400_000_007, 9102, 64, 16.75);

        $result = $this->provider($u1, $j1, ['Pilot One' => [$t1], 'Pilot Two' => [$t7]])
            ->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame(
            [[6_400_000_001, false, 'Pilot One'], [6_400_000_007, false, 'Pilot Two']],
            array_map(
                static fn (PurchaseSuggestionResource $suggestion): array => [$suggestion->transactionId, $suggestion->alreadyLinked, $suggestion->characterName],
                $result->suggestions,
            ),
        );
        self::assertSame([[6_400_000_001, 6_400_000_007]], $this->purchaseLookups);
    }

    #[Group('issue-205')]
    public function testM1MaterialsOfTheReactionFormulaOfAProjectWithoutSubStepsAreSuggested(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $j4 = $this->projectOf($u1, 9301);
        $j4->addStep($this->step(self::REACTION_FORMULA_TYPE_ID, 9301, 20, 0)->setActivityType('reaction'));
        $t8 = $this->buy($p1, 6_400_000_008, 9311, 40, 610.0);

        $result = $this->provider($u1, $j4, ['Pilot One' => [$t8]])->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame(
            [
                [[[self::REACTION_FORMULA_TYPE_ID], [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value]]],
                [['Pilot One', [9311, 9312]]],
            ],
            [$this->materialLookups, $this->buyLookups],
        );
        self::assertSame(
            [1, [[9311, 40, 24_400.0]]],
            [$result->totalCount, $this->typeIdsQuantitiesAndTotalPricesOf($result->suggestions)],
        );
    }

    #[Group('issue-205')]
    public function testM2MaterialsOfTheManufacturingBlueprintOfAProjectWithoutSubStepsAreStillSuggested(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $j5 = $this->projectOf($u1, 9000);
        $j5->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9000, 4, 0));
        $t1 = $this->buy($p1, 6_400_000_001, 9101, 120, 5.25);

        $result = $this->provider($u1, $j5, ['Pilot One' => [$t1]])->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame([['Pilot One', [9101, 9102]]], $this->buyLookups);
        self::assertSame(
            [1, [[9101, 120, 630.0]]],
            [$result->totalCount, $this->typeIdsQuantitiesAndTotalPricesOf($result->suggestions)],
        );
    }

    #[Group('issue-205')]
    public function testM3MaterialsOfBothTheManufacturingBlueprintAndTheReactionFormulaOfAProjectWithoutSubStepsAreSuggested(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $j6 = $this->projectOf($u1, 9000);
        $j6->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9000, 4, 0));
        $j6->addStep($this->step(self::REACTION_FORMULA_TYPE_ID, 9301, 20, 0)->setActivityType('reaction'));
        $t1 = $this->buy($p1, 6_400_000_001, 9101, 120, 5.25);
        $t8 = $this->buy($p1, 6_400_000_008, 9311, 40, 610.0);

        $result = $this->provider($u1, $j6, ['Pilot One' => [$t1, $t8]])->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame([['Pilot One', [9101, 9102, 9311, 9312]]], $this->buyLookups);
        self::assertSame(
            [2, [[9101, 120, 630.0], [9311, 40, 24_400.0]]],
            [$result->totalCount, $this->typeIdsQuantitiesAndTotalPricesOf($result->suggestions)],
        );
    }

    #[Group('issue-205')]
    public function testM4ProjectWithOnlyACopyStepHasNoSuggestionWithoutReadingTheSdeNorTheWalletTransactions(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $j7 = $this->projectOf($u1, 9200);
        $j7->addStep($this->step(self::MANUFACTURING_BLUEPRINT_TYPE_ID, 9200, 3, 0)->setActivityType('copy'));

        $result = $this->provider($u1, $j7, ['Pilot One' => [$this->buy($p1, 6_400_000_001, 9101, 120, 5.25)]])
            ->provide(new Get(), ['id' => self::PROJECT_ID]);

        self::assertSame([0, [], [], []], [$result->totalCount, $this->materialLookups, $this->buyLookups, $this->purchaseLookups]);
    }

    #[Group('issue-285')]
    public function testProjectOfAnotherUserIsNotFoundWithoutReadingTheWalletTransactions(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $u9 = $this->pilotWith((new Character())->setName('Other Pilot'));
        $j9 = $this->projectOf($u9, 9090);
        $j9->addStep($this->step(9209, 9090, 1, 0));
        $j9->addStep($this->step(9201, 9101, 30, 1));
        $provider = $this->provider($u1, $j9, ['Pilot One' => [$this->buy($p1, 6_400_000_001, 9101, 120, 5.25)]]);

        try {
            $provider->provide(new Get(), ['id' => self::PROJECT_ID]);
            $outcome = 'provided';
        } catch (HttpExceptionInterface $exception) {
            $outcome = $exception->getStatusCode() . ' ' . $exception->getMessage();
        }

        self::assertSame(['404 Project not found', [], [], []], [$outcome, $this->buyLookups, $this->materialLookups, $this->purchaseLookups]);
    }

    #[Group('issue-200')]
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheWalletTransactions(): void
    {
        $p1 = (new Character())->setName('Pilot One');
        $u1 = $this->pilotWith($p1);
        $j7 = $this->projectOf($u1, 9070);
        $j7->addStep($this->step(9207, 9070, 1, 0));
        $provider = $this->provider($u1, $j7, ['Pilot One' => [$this->buy($p1, 6_400_000_007, 9070, 45, 8.75)]]);

        try {
            $provider->provide(new Get(), ['id' => 'abc']);
            $outcome = 'provided';
        } catch (HttpExceptionInterface $exception) {
            $outcome = $exception->getStatusCode().' '.$exception->getMessage();
        } catch (\Throwable $throwable) {
            $outcome = $throwable::class.': '.$throwable->getMessage();
        }

        self::assertSame(['404 Project not found', [], [], []], [$outcome, $this->buyLookups, $this->materialLookups, $this->purchaseLookups]);
    }

    /**
     * @param array<string, list<CachedWalletTransaction>> $buysByCharacterName
     */
    private function provider(User $pilot, IndustryProject $project, array $buysByCharacterName): PurchaseSuggestionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByTypeIds')->willReturnCallback(
            function (Character $character, array $typeIds) use ($buysByCharacterName): array {
                $sortedTypeIds = $typeIds;
                sort($sortedTypeIds);
                $this->buyLookups[] = [$character->getName(), $sortedTypeIds];

                return array_values(array_filter(
                    $buysByCharacterName[$character->getName()] ?? [],
                    static fn (CachedWalletTransaction $transaction): bool => in_array($transaction->getTypeId(), $typeIds, true),
                ));
            },
        );

        return new PurchaseSuggestionProvider(
            $security,
            $projectRepository,
            $transactionRepository,
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->sdeMaterialRepository(),
            $this->purchaseRepositoryLookingUpTheLinkedPurchases(),
        );
    }

    private function sdeMaterialRepository(): IndustryActivityMaterialRepository
    {
        $materialsByBlueprintAndActivity = [
            self::MANUFACTURING_BLUEPRINT_TYPE_ID => [
                IndustryActivityType::Manufacturing->value => [['materialTypeId' => 9101, 'quantity' => 300], ['materialTypeId' => 9102, 'quantity' => 150]],
            ],
            self::REACTION_FORMULA_TYPE_ID => [
                IndustryActivityType::Reaction->value => [['materialTypeId' => 9311, 'quantity' => 100], ['materialTypeId' => 9312, 'quantity' => 100]],
            ],
        ];
        $repository = $this->createStub(IndustryActivityMaterialRepository::class);
        $repository->method('findMaterialsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds) use ($materialsByBlueprintAndActivity): array {
                $this->materialLookups[] = [$blueprintTypeIds, $activityIds];
                $materialsByBlueprint = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($materialsByBlueprintAndActivity[$blueprintTypeId] ?? [] as $activityId => $materials) {
                        if (in_array($activityId, $activityIds, true)) {
                            $materialsByBlueprint[$blueprintTypeId] = $materials;
                        }
                    }
                }

                return $materialsByBlueprint;
            },
        );

        return $repository;
    }

    private function purchaseRepositoryLookingUpTheLinkedPurchases(): IndustryStepPurchaseRepository
    {
        $repository = $this->createStub(IndustryStepPurchaseRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            if (array_keys($criteria) !== ['transaction']) {
                self::fail('Unexpected criteria ' . implode(', ', array_keys($criteria)));
            }
            $this->purchaseLookups[] = array_map(
                static fn (CachedWalletTransaction $transaction): int => $transaction->getTransactionId(),
                $criteria['transaction'],
            );

            return array_values(array_filter(
                $this->existingPurchases,
                static fn (IndustryStepPurchase $purchase): bool => in_array($purchase->getTransaction(), $criteria['transaction'], true),
            ));
        });

        return $repository;
    }

    private function linkInAPurchase(IndustryProjectStep $step, CachedWalletTransaction $transaction): void
    {
        $purchase = (new IndustryStepPurchase())
            ->setTransaction($transaction)
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($transaction->getQuantity())
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getTotalPrice())
            ->setSource('esi_wallet');
        $step->addPurchase($purchase);
        $this->existingPurchases[] = $purchase;
    }

    /**
     * @param list<PurchaseSuggestionResource> $suggestions
     */
    private function transactionIdsAndLinkedFlagsOf(array $suggestions): array
    {
        return array_map(
            static fn (PurchaseSuggestionResource $suggestion): array => [$suggestion->transactionId, $suggestion->alreadyLinked],
            $suggestions,
        );
    }

    /**
     * @param list<PurchaseSuggestionResource> $suggestions
     */
    private function typeIdsQuantitiesAndTotalPricesOf(array $suggestions): array
    {
        return array_map(
            static fn (PurchaseSuggestionResource $suggestion): array => [$suggestion->typeId, $suggestion->quantity, $suggestion->totalPrice],
            $suggestions,
        );
    }

    private function pilotWith(Character ...$characters): User
    {
        $pilot = new User();
        foreach ($characters as $character) {
            $pilot->addCharacter($character);
        }

        return $pilot;
    }

    private function projectOf(User $owner, int $productTypeId): IndustryProject
    {
        return (new IndustryProject())->setUser($owner)->setProductTypeId($productTypeId)->setRuns(1);
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

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\IndustryProject;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvType;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1288')]
final class ProjectSourcingDecisionsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const float RAM_SELL_PRICE = 1_000.0;
    private const float TRITANIUM_SELL_PRICE = 5.0;
    private const float RAM_ADJUSTED_PRICE = 900.0;
    private const float TRITANIUM_ADJUSTED_PRICE = 4.0;
    private const float RAM_CUBIC_METERS = 0.04;
    private const float TRITANIUM_CUBIC_METERS = 0.01;
    private const float JITA_MANUFACTURING_COST_INDEX = 0.05;
    private const float WORKSHOP_FACILITY_TAX_PERCENT = 1.0;
    private const float COURIER_ISK_PER_CUBIC_METER = 800.0;
    private const float JUMP_BRIDGE_ISK_PER_CUBIC_METER = 0.0;
    private const float CENTS = 0.01;

    private const int RAM_DEMAND = 298;
    private const int RAM_SURPLUS = 2;
    private const int TRITANIUM_DEMAND_OF_THE_FULL_PLAN = 10_680;
    private const int TRITANIUM_DEMAND_WITH_THE_COMPONENT_BOUGHT = 9_600;

    private IndustryStructureConfig $workshop;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->em->find(InvType::class, self::RAM_STARSHIP_TECH_TYPE_ID)->setVolume(self::RAM_CUBIC_METERS);
        $this->em->find(InvType::class, self::TRITANIUM_TYPE_ID)->setVolume(self::TRITANIUM_CUBIC_METERS);
        $this->workshop = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Jita Raitaru')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent(self::WORKSHOP_FACILITY_TAX_PERCENT)
            ->setRigs([]);
        $this->em->persist($this->workshop);
        $this->em->persist((new IndustryUserSettings())
            ->setUser($this->owner)
            ->setFavoriteManufacturingSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setExportCostPerM3(self::COURIER_ISK_PER_CUBIC_METER)
            ->setJumpBridgeCostPerM3(self::JUMP_BRIDGE_ISK_PER_CUBIC_METER));
        $this->em->flush();
        $this->seedAdjustedPrices([
            self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_ADJUSTED_PRICE,
            self::TRITANIUM_TYPE_ID => self::TRITANIUM_ADJUSTED_PRICE,
        ]);
        $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);
        $this->seedJitaSellOrderBooks([
            self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => self::RAM_SELL_PRICE, 'volume' => 100_000]],
            self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_SELL_PRICE, 'volume' => 1_000_000]],
        ]);
    }

    public function testOwnerReadsTheLinesOfTheFullPlanWithTheirOptionsTheRecommendedChoiceAndTheTotals(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->sourcingDecisionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $body['id']);
        self::assertArrayHasKey('boughtTypeIds', $body);
        self::assertSame([], $body['boughtTypeIds']);
        self::assertCount(2, $body['rows']);

        $component = $this->rowOf(self::RAM_STARSHIP_TECH_TYPE_ID);
        self::assertSame(
            ['R.A.M.- Starship Tech', 'component', 'manufacturing', self::RAM_DEMAND, self::RAM_RUNS, self::RAM_SURPLUS, [$this->workshop->getId()->toRfc4122()], 'build', 'build', false],
            $this->fieldsOf($component, ['typeName', 'stage', 'activity', 'quantity', 'runs', 'surplus', 'consumerStructureIds', 'recommended', 'chosen', 'bought']),
        );
        self::assertSame(['build', 'jita', 'structure'], $this->optionKeysOf($component));
        self::assertEqualsWithDelta([5_880.0, 19.73, 5_400.0, 480.0, 0.0], $this->amountsOf($component['options']['build'], ['total', 'perUnit', 'price', 'installation', 'transport']), self::CENTS);
        self::assertEqualsWithDelta([298_000.0, 1_000.0, 298_000.0, 0.0], $this->amountsOf($component['options']['jita'], ['total', 'perUnit', 'price', 'transport']), self::CENTS);
        self::assertTrue($component['options']['build']['eligible']);
        self::assertTrue($component['options']['jita']['eligible']);
        self::assertFalse($component['options']['structure']['eligible']);
        self::assertArrayHasKey('total', $component['options']['structure']);
        self::assertNull($component['options']['structure']['total']);

        $leaf = $this->rowOf(self::TRITANIUM_TYPE_ID);
        self::assertSame(
            ['Tritanium', 'base', self::TRITANIUM_DEMAND_OF_THE_FULL_PLAN, 'jita', 'jita'],
            $this->fieldsOf($leaf, ['typeName', 'stage', 'quantity', 'recommended', 'chosen']),
        );
        self::assertSame(['jita', 'structure'], $this->optionKeysOf($leaf));
        self::assertEqualsWithDelta([53_400.0, 5.0, 53_400.0, 0.0], $this->amountsOf($leaf['options']['jita'], ['total', 'perUnit', 'price', 'transport']), self::CENTS);

        self::assertEqualsWithDelta(
            [53_400.0, 32_380.0, 0.0, 85_780.0],
            $this->amountsOf($body['totals'], ['purchases', 'installation', 'transport', 'total']),
            self::CENTS,
        );
    }

    public function testPilotOfAnotherCorporationIsRefusedTheSourcingDecisions(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->sourcingDecisionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testSourcingDecisionsOfAnUnknownProjectAreNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->sourcingDecisionsUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testBuyingTheComponentRemovesItsJobAndItsMaterialsFromTheRecalculatedPlanWithoutWritingAnything(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->sourcingDecisionsUri($this->ishtarBatch->getId()->toRfc4122()).'?buy='.self::RAM_STARSHIP_TECH_TYPE_ID);

        self::assertResponseStatusCodeSame(200);
        $this->assertTheComponentIsBoughtAndItsJobIsGone();
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->getBlacklistTypeIds());
    }

    public function testWithoutParameterTheTypesBlacklistedByTheProjectCountAsBought(): void
    {
        $this->ishtarBatch->setBlacklistTypeIds([self::RAM_STARSHIP_TECH_TYPE_ID]);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('GET', $this->sourcingDecisionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $this->assertTheComponentIsBoughtAndItsJobIsGone();
    }

    private function assertTheComponentIsBoughtAndItsJobIsGone(): void
    {
        $body = $this->responseJson();
        self::assertSame([self::RAM_STARSHIP_TECH_TYPE_ID], $body['boughtTypeIds']);
        self::assertCount(2, $body['rows']);

        $component = $this->rowOf(self::RAM_STARSHIP_TECH_TYPE_ID);
        self::assertSame(
            [self::RAM_DEMAND, 'build', 'jita', true],
            $this->fieldsOf($component, ['quantity', 'recommended', 'chosen', 'bought']),
        );
        self::assertEqualsWithDelta(5_880.0, $component['options']['build']['total'], self::CENTS);

        $leaf = $this->rowOf(self::TRITANIUM_TYPE_ID);
        self::assertSame(self::TRITANIUM_DEMAND_WITH_THE_COMPONENT_BOUGHT, $leaf['quantity']);

        self::assertEqualsWithDelta(
            [346_000.0, 31_900.0, 0.0, 377_900.0],
            $this->amountsOf($body['totals'], ['purchases', 'installation', 'transport', 'total']),
            self::CENTS,
        );
    }

    private function sourcingDecisionsUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/sourcing-decisions';
    }

    private function rowOf(int $typeId): array
    {
        $rows = array_values(array_filter($this->responseJson()['rows'], static fn (array $row): bool => $row['typeId'] === $typeId));
        self::assertCount(1, $rows, \sprintf('Expected one row for the type %d.', $typeId));

        return $rows[0];
    }

    private function optionKeysOf(array $row): array
    {
        $keys = array_keys($row['options']);
        sort($keys);

        return $keys;
    }

    private function amountsOf(array $object, array $fields): array
    {
        return array_map(static function (string $field) use ($object): mixed {
            self::assertArrayHasKey($field, $object);

            return $object[$field];
        }, $fields);
    }
}

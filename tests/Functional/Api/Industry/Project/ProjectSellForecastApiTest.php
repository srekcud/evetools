<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedCharacterSkill;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-50')]
final class ProjectSellForecastApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int SELL_STRUCTURE_ID = 1_040_000_000_001;
    private const string SELL_STRUCTURE_NAME = 'Ishtar Citadel';
    private const string JITA_NAME = 'Jita IV - Moon 4';
    private const int SABRE_TYPE_ID = 22456;
    private const int HECATE_UNSOLD_QUANTITY = 6;
    private const int SABRE_UNSOLD_QUANTITY = 3;
    private const float HECATE_STRUCTURE_PRICE = 800_000.0;
    private const float HECATE_JITA_PRICE = 900_000.0;
    private const float SABRE_JITA_PRICE = 1_250_000.0;
    private const float FEE_RATE = 0.04875;
    private const float DELTA = 0.01;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();

        $this->ishtarBatch->setSellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME);
        $this->addJobLine($this->ishtarBatch, self::HECATE_TYPE_ID, 'Hecate', 'final', self::HECATE_UNSOLD_QUANTITY, self::HECATE_UNSOLD_QUANTITY, 10, 20, null);
        $this->addJobLine($this->ishtarBatch, self::SABRE_TYPE_ID, 'Sabre', 'final', self::SABRE_UNSOLD_QUANTITY, self::SABRE_UNSOLD_QUANTITY, 10, 20, null);
        $this->em->flush();

        $this->seedJitaSellOrderBooks([
            self::HECATE_TYPE_ID => [['price' => self::HECATE_JITA_PRICE, 'volume' => 10]],
            self::SABRE_TYPE_ID => [['price' => self::SABRE_JITA_PRICE, 'volume' => 5]],
        ]);
        $this->seedNoJitaSellOrderFor(self::ISHTAR_TYPE_ID);
        $this->saveInCache('structure_market.cache', 'structure_market_'.self::SELL_STRUCTURE_ID, [
            self::HECATE_TYPE_ID => ['sell' => self::HECATE_STRUCTURE_PRICE, 'buy' => null],
        ]);
    }

    public function testAcceptedMemberReadsTheForecastOfWhatTheProjectStillHasToSell(): void
    {
        $this->ownerHasSyncedSalesFeeSkills();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $forecast = $this->responseJson();
        self::assertEqualsWithDelta([
            'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
            'sellStructure' => ['id' => self::SELL_STRUCTURE_ID, 'name' => self::SELL_STRUCTURE_NAME],
            'products' => [
                [
                    'typeId' => self::HECATE_TYPE_ID,
                    'typeName' => 'Hecate',
                    'quantity' => self::HECATE_UNSOLD_QUANTITY,
                    'structure' => [
                        'name' => self::SELL_STRUCTURE_NAME,
                        'unitPrice' => 800_000.0,
                        'coveragePercent' => null,
                        'feeRate' => self::FEE_RATE,
                        'netUnitPrice' => 761_000.0,
                        'netRevenue' => 4_566_000.0,
                        'daysToSell' => null,
                    ],
                    'jita' => [
                        'name' => self::JITA_NAME,
                        'unitPrice' => 900_000.0,
                        'coveragePercent' => null,
                        'feeRate' => self::FEE_RATE,
                        'netUnitPrice' => 856_125.0,
                        'netRevenue' => 5_136_750.0,
                        'daysToSell' => null,
                    ],
                ],
                [
                    'typeId' => self::SABRE_TYPE_ID,
                    'typeName' => 'Sabre',
                    'quantity' => self::SABRE_UNSOLD_QUANTITY,
                    'structure' => [
                        'name' => self::SELL_STRUCTURE_NAME,
                        'unitPrice' => null,
                        'coveragePercent' => null,
                        'feeRate' => self::FEE_RATE,
                        'netUnitPrice' => null,
                        'netRevenue' => null,
                        'daysToSell' => null,
                    ],
                    'jita' => [
                        'name' => self::JITA_NAME,
                        'unitPrice' => 1_250_000.0,
                        'coveragePercent' => null,
                        'feeRate' => self::FEE_RATE,
                        'netUnitPrice' => 1_189_062.5,
                        'netRevenue' => 3_567_187.5,
                        'daysToSell' => null,
                    ],
                ],
            ],
            'totals' => [
                'structureNetRevenue' => null,
                'jitaNetRevenue' => 8_703_937.5,
                'structureUnknownProductCount' => 1,
                'jitaUnknownProductCount' => 0,
                'remainingCost' => null,
                'remainingCostUnknownLineCount' => 1,
                'structureProfit' => null,
                'jitaProfit' => null,
            ],
        ], $forecast, self::DELTA);
        self::assertArrayHasKey('structureNetRevenue', $forecast['totals']);
        self::assertNull($forecast['totals']['structureNetRevenue']);
        self::assertArrayHasKey('remainingCost', $forecast['totals']);
        self::assertNull($forecast['totals']['remainingCost']);
        self::assertArrayHasKey('structureProfit', $forecast['totals']);
        self::assertNull($forecast['totals']['structureProfit']);
        self::assertArrayHasKey('jitaProfit', $forecast['totals']);
        self::assertNull($forecast['totals']['jitaProfit']);
        self::assertArrayHasKey('netRevenue', $forecast['products'][1]['structure']);
        self::assertNull($forecast['products'][1]['structure']['netRevenue']);
    }

    public function testOwnerWithoutSyncedSkillsGetsThePricesButNoFeeNorRevenue(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $forecast = $this->responseJson();
        $hecate = $forecast['products'][0];
        self::assertSame(800_000.0, $hecate['structure']['unitPrice']);
        self::assertArrayHasKey('feeRate', $hecate['structure']);
        self::assertNull($hecate['structure']['feeRate']);
        self::assertArrayHasKey('netUnitPrice', $hecate['structure']);
        self::assertNull($hecate['structure']['netUnitPrice']);
        self::assertArrayHasKey('netRevenue', $hecate['structure']);
        self::assertNull($hecate['structure']['netRevenue']);
        self::assertSame(900_000.0, $hecate['jita']['unitPrice']);
        self::assertNull($hecate['jita']['netRevenue']);
        self::assertArrayHasKey('jitaNetRevenue', $forecast['totals']);
        self::assertNull($forecast['totals']['jitaNetRevenue']);
        self::assertSame(2, $forecast['totals']['jitaUnknownProductCount']);
        self::assertSame(2, $forecast['totals']['structureUnknownProductCount']);
    }

    public function testProjectWithoutSellStructureIsForecastOnJitaOnly(): void
    {
        $this->ownerHasSyncedSalesFeeSkills();
        $this->ishtarBatch->setSellStructure(null, null);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $forecast = $this->responseJson();
        self::assertArrayHasKey('sellStructure', $forecast);
        self::assertNull($forecast['sellStructure']);
        self::assertCount(2, $forecast['products']);
        foreach ($forecast['products'] as $product) {
            self::assertArrayHasKey('structure', $product);
            self::assertNull($product['structure']);
        }
        self::assertEqualsWithDelta(856_125.0, $forecast['products'][0]['jita']['netUnitPrice'], self::DELTA);
        self::assertArrayHasKey('structureNetRevenue', $forecast['totals']);
        self::assertNull($forecast['totals']['structureNetRevenue']);
        self::assertEqualsWithDelta(8_703_937.5, $forecast['totals']['jitaNetRevenue'], self::DELTA);
    }

    public function testProjectWithEverythingSoldHasNoProductAndNoTotal(): void
    {
        $this->addSale($this->ishtarBatch, self::HECATE_TYPE_ID, 'Hecate', self::HECATE_UNSOLD_QUANTITY, 880_000.0, 'Jita IV - Moon 4', '2026-11-02T10:00:00+00:00');
        $this->addSale($this->ishtarBatch, self::SABRE_TYPE_ID, 'Sabre', self::SABRE_UNSOLD_QUANTITY, 1_240_000.0, 'Jita IV - Moon 4', '2026-11-02T11:00:00+00:00');
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $forecast = $this->responseJson();
        self::assertSame([], $forecast['products']);
        self::assertArrayHasKey('structureNetRevenue', $forecast['totals']);
        self::assertArrayHasKey('jitaNetRevenue', $forecast['totals']);
        self::assertArrayHasKey('remainingCost', $forecast['totals']);
        self::assertArrayHasKey('structureProfit', $forecast['totals']);
        self::assertArrayHasKey('jitaProfit', $forecast['totals']);
        self::assertSame(
            [
                'structureNetRevenue' => null,
                'jitaNetRevenue' => null,
                'structureUnknownProductCount' => 0,
                'jitaUnknownProductCount' => 0,
                'remainingCost' => null,
                'remainingCostUnknownLineCount' => 1,
                'structureProfit' => null,
                'jitaProfit' => null,
            ],
            $forecast['totals'],
        );
    }

    #[Group('issue-483')]
    public function testPlannedProfitIsTheNetRevenueMinusTheCostOfTheRemainingMaterials(): void
    {
        $this->ownerHasSyncedSalesFeeSkills();
        $this->nocxiumLine->setFulfilledQuantity(600);
        $this->addSale($this->ishtarBatch, self::SABRE_TYPE_ID, 'Sabre', self::SABRE_UNSOLD_QUANTITY, 1_240_000.0, 'Jita IV - Moon 4', '2026-11-02T11:00:00+00:00');
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $totals = $this->responseJson()['totals'];
        self::assertEqualsWithDelta(4_566_000.0, $totals['structureNetRevenue'], self::DELTA);
        self::assertEqualsWithDelta(5_136_750.0, $totals['jitaNetRevenue'], self::DELTA);
        self::assertEqualsWithDelta(2_770_500.0, $totals['remainingCost'], self::DELTA);
        self::assertSame(0, $totals['remainingCostUnknownLineCount']);
        self::assertEqualsWithDelta(1_795_500.0, $totals['structureProfit'], self::DELTA);
        self::assertEqualsWithDelta(2_366_250.0, $totals['jitaProfit'], self::DELTA);
    }

    #[Group('issue-483')]
    public function testPlannedProfitIsUnknownWhileARemainingMaterialHasNoPrice(): void
    {
        $this->ownerHasSyncedSalesFeeSkills();
        $this->addSale($this->ishtarBatch, self::SABRE_TYPE_ID, 'Sabre', self::SABRE_UNSOLD_QUANTITY, 1_240_000.0, 'Jita IV - Moon 4', '2026-11-02T11:00:00+00:00');
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $totals = $this->responseJson()['totals'];
        self::assertEqualsWithDelta(4_566_000.0, $totals['structureNetRevenue'], self::DELTA);
        self::assertArrayHasKey('remainingCost', $totals);
        self::assertNull($totals['remainingCost']);
        self::assertSame(1, $totals['remainingCostUnknownLineCount']);
        self::assertArrayHasKey('structureProfit', $totals);
        self::assertNull($totals['structureProfit']);
        self::assertArrayHasKey('jitaProfit', $totals);
        self::assertNull($totals['jitaProfit']);
    }

    public function testOutsiderIsRefusedTheForecast(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        self::assertArrayNotHasKey('products', $this->responseJson());
    }

    public function testPendingMemberIsRefusedTheForecast(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri('7d1f0c3e-5b1a-4c55-9a52-0d2f6a9b1e77'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testProjectIdentifiedByANonUuidIsNotFound(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->forecastUri(self::NOT_A_UUID));

        self::assertResponseStatusCodeSame(404);
    }

    public function testForecastWithoutAuthenticationIsUnauthorized(): void
    {
        $this->request('GET', $this->forecastUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(401);
    }

    private function forecastUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/sell-forecast';
    }

    private function ownerHasSyncedSalesFeeSkills(): void
    {
        $seller = $this->owner->getMainCharacter();
        $seller->setStandingsSyncedAt(new \DateTimeImmutable('2026-11-08T18:00:00+00:00'));
        foreach ([CachedCharacterSkill::SKILL_ACCOUNTING, CachedCharacterSkill::SKILL_BROKER_RELATIONS] as $skillId) {
            $this->em->persist(
                (new CachedCharacterSkill())
                    ->setCharacter($seller)
                    ->setSkillId($skillId)
                    ->setLevel(5)
                    ->setIsManual(false)
                    ->setCachedAt(new \DateTimeImmutable('2026-11-08T18:00:00+00:00')),
            );
        }
        $this->em->flush();
    }
}

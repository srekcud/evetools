<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1085')]
final class ProjectPurchasesToDeliverApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int AMARR_SYSTEM_ID = 30_002_187;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int JITA_STATION = 60_003_760;
    private const int AMARR_STATION = 60_008_494;
    private const int CATEGORY_ID = 999_085_001;
    private const int GROUP_ID = 999_085_002;

    private int $nextTransactionId = 1_085_000_001;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedStations();
        $this->purchaseOn($this->tritaniumLine, 1_500, self::JITA_STATION);
        $this->purchaseOn($this->nocxiumLine, 100, self::AMARR_STATION);
        $this->purchaseOn($this->nocxiumLine, 30, null);
        $this->purchaseOn($this->hecateTritaniumLine, 2_000, self::AMARR_STATION);
        $this->em->flush();
    }

    public function testUnreadableContainerStockListsEveryPurchaseWithAnUnknownDeliveryNeverZero(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->deliveriesUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122(), 'unknown', 3], [$body['id'], $body['stockState'], $body['purchaseCount']]);
        self::assertSame(
            [
                ['jita', self::JITA_SYSTEM_ID, 'Jita', 'Tritanium', 1_500, null, 'unknown'],
                ['other', self::AMARR_SYSTEM_ID, 'Amarr', 'Nocxium', 100, null, 'unknown'],
                ['unknown', null, null, 'Nocxium', 30, null, 'unknown'],
            ],
            array_map(
                static fn (array $row): array => [$row['originKind'], $row['solarSystemId'], $row['solarSystemName'], $row['typeName'], $row['purchasedQuantity'], $row['missingQuantity'], $row['deliveryState']],
                $body['rows'],
            ),
        );
    }

    public function testProjectWithoutContainerNameReportsTheNoneState(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->deliveriesUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(['none', 1], [$body['stockState'], $body['purchaseCount']]);
        self::assertSame(
            [['other', self::AMARR_SYSTEM_ID, 2_000, null, 'unknown']],
            array_map(static fn (array $row): array => [$row['originKind'], $row['solarSystemId'], $row['purchasedQuantity'], $row['missingQuantity'], $row['deliveryState']], $body['rows']),
        );
    }

    public function testAcceptedMemberWhoIsNotTheOwnerReadsTheList(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->deliveriesUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->responseJson()['purchaseCount']);
    }

    public function testPendingMemberAndOutsiderAreDenied(): void
    {
        foreach ([$this->pendingMember, $this->outsider] as $user) {
            $this->actAs($user);
            $this->request('GET', $this->deliveriesUri($this->ishtarBatch));

            self::assertResponseStatusCodeSame(403);
            self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        }
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.Uuid::v4()->toRfc4122().'/purchases-to-deliver');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function deliveriesUri(IndustryProject $project): string
    {
        return $this->projectUri($project).'/purchases-to-deliver';
    }

    private function purchaseOn(IndustryProjectBomLine $line, int $quantity, ?int $locationId): void
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($line->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice(1.0)
            ->setTotalPrice((float) $quantity)
            ->setSource(null === $locationId ? 'manual' : 'esi_wallet');
        if (null !== $locationId) {
            $buyer = $this->owner->getMainCharacter();
            self::assertNotNull($buyer);
            $transaction = (new CachedWalletTransaction())
                ->setCharacter($buyer)
                ->setTransactionId($this->nextTransactionId++)
                ->setTypeId($line->getTypeId())
                ->setQuantity($quantity)
                ->setUnitPrice(1.0)
                ->setIsBuy(true)
                ->setLocationId($locationId)
                ->setDate(new \DateTimeImmutable('-1 hour'));
            $this->em->persist($transaction);
            $purchase->setTransaction($transaction);
        }
        $line->addPurchase($purchase);
    }

    private function seedStations(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Material');
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Mineral')->setCategory($category);
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        foreach ([$category, $group, $region, $constellation] as $staticRow) {
            $this->em->persist($staticRow);
        }
        foreach ([self::TRITANIUM_TYPE_ID => 'Tritanium', self::NOCXIUM_TYPE_ID => 'Nocxium'] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume(0.01));
        }
        $solarSystems = [];
        foreach ([self::JITA_SYSTEM_ID => 'Jita', self::AMARR_SYSTEM_ID => 'Amarr'] as $solarSystemId => $name) {
            $solarSystems[$solarSystemId] = (new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name)->setConstellation($constellation)->setRegionId(self::REGION_ID);
            $this->em->persist($solarSystems[$solarSystemId]);
        }
        foreach ([self::JITA_STATION => [self::JITA_SYSTEM_ID, 'Jita IV - Moon 4'], self::AMARR_STATION => [self::AMARR_SYSTEM_ID, 'Amarr VIII (Oris)']] as $stationId => [$solarSystemId, $stationName]) {
            $this->em->persist((new StaStation())
                ->setStationId($stationId)
                ->setStationName($stationName)
                ->setSolarSystem($solarSystems[$solarSystemId])
                ->setConstellationId(self::CONSTELLATION_ID)
                ->setRegionId(self::REGION_ID));
        }
    }
}

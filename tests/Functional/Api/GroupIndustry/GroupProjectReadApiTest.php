<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\GroupIndustry;

use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class GroupProjectReadApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_STATION_ID = 60003760;
    private const int CONTAINER_ITEM_ID = 1_020_600_001;
    private const int SUB_CONTAINER_ITEM_ID = 1_020_600_002;
    private const int STATION_CONTAINER_TYPE_ID = 17366;
    private const int SMALL_SECURE_CONTAINER_TYPE_ID = 11488;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberReadsTheBomWithMaterialLinesInUnitsAndJobLinesInRunsAndUnits(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000, 0, 12_000, 0.0, 4.25, 51_000.0, false, null, null, null, null, null, null, false],
                [self::NOCXIUM_TYPE_ID, 'Nocxium', 600, 0, 600, 0.0, null, null, false, null, null, null, null, null, null, false],
                [self::MORPHITE_TYPE_ID, 'Morphite', 150, 3, 147, 2.0, 18_500.0, 2_775_000.0, false, null, null, null, null, null, null, false],
                [self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 300, 0.0, null, null, true, 'component', 'manufacturing', self::ISHTAR_TYPE_ID, 10, 20, 3, false],
                [self::ISHTAR_TYPE_ID, 'Ishtar', 2, 2, 0, 100.0, null, null, true, 'final', 'manufacturing', null, 4, 8, 2, true],
            ],
            $this->bomRows(),
        );
    }

    public function testCorpmateOfTheOwnerWhoIsNotAMemberReadsTheBom(): void
    {
        $this->client->loginUser($this->ownerCorpmate, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(5, $this->responseJson());
    }

    public function testOutsiderIsRefusedTheBom(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testPendingMemberOutsideTheOwnerCorporationIsRefusedTheBom(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testAcceptedMemberReadsTheDistributionOfTheApprovedContributionsAgainstTheSalesOfTheProjectOnly(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/distribution');

        self::assertResponseStatusCodeSame(200);
        $distribution = $this->responseJson();
        self::assertSame(
            [3_000_000.0, 90_000.0, 135_000.0, 2_775_000.0, 555_000.0, 400.0],
            array_map(
                static fn (string $key): float => (float) $distribution[$key],
                ['totalRevenue', 'brokerFee', 'salesTax', 'netRevenue', 'totalProjectCost', 'marginPercent'],
            ),
        );
        self::assertSame(
            [
                [$this->idOf($this->ownerMembership), 'Aria Solberg', 499_500.0, 0.0, 499_500.0, 0.0, 0.0, 90.0, 1_998_000.0, 2_497_500.0],
                [$this->idOf($this->memberMembership), 'Brann Holt', 55_500.0, 55_500.0, 0.0, 0.0, 0.0, 10.0, 222_000.0, 277_500.0],
            ],
            array_map(
                static fn (array $member): array => [
                    $member['memberId'],
                    $member['characterName'],
                    ...array_map(
                        static fn (string $key): float => (float) $member[$key],
                        ['totalCostsEngaged', 'materialCosts', 'jobInstallCosts', 'bpcCosts', 'lineRentalCosts', 'sharePercent', 'profitPart', 'payoutTotal'],
                    ),
                ],
                $this->rowsSortedBy('characterName', $distribution['members']),
            ),
        );
    }

    public function testCorpmateOfTheOwnerWhoIsNotAMemberReadsTheDistributionAndItsPayouts(): void
    {
        $this->client->loginUser($this->ownerCorpmate, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/distribution');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [2_497_500.0, 277_500.0],
            array_map(static fn (array $member): float => (float) $member['payoutTotal'], $this->rowsSortedBy('characterName', $this->responseJson()['members'])),
        );
    }

    public function testOutsiderIsRefusedTheDistribution(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/distribution');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testAcceptedMemberListsTheSalesOfTheProjectOnly(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/sales');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->idOf($this->ishtarSale), self::ISHTAR_TYPE_ID, 'Ishtar', 2, 1_500_000.0, 3_000_000.0, 'Jita IV - Moon 4', '2026-10-31T18:20:00+00:00', 'Aria Solberg', null, false]],
            array_map(
                static fn (array $sale): array => [
                    $sale['id'],
                    $sale['typeId'],
                    $sale['typeName'],
                    $sale['quantity'],
                    (float) $sale['unitPrice'],
                    (float) $sale['totalPrice'],
                    $sale['venue'],
                    $sale['soldAt'],
                    $sale['recordedByCharacterName'],
                    $sale['sellerCharacterName'] ?? null,
                    $sale['linkedToWalletTransaction'],
                ],
                $this->responseJson(),
            ),
        );
    }

    public function testOutsiderIsRefusedTheSales(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/sales');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testOwnerVerifiesTheMaterialLinesAgainstTheNamedCorporationContainerAndItsSubContainer(): void
    {
        $this->persistIshtarContainerInTheFirstDivision();
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->containerVerificationUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [$this->idOf($this->tritaniumLine), self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000, 12_000, 'verified', null],
                [$this->idOf($this->nocxiumLine), self::NOCXIUM_TYPE_ID, 'Nocxium', 600, 0, 'missing', null],
                [$this->idOf($this->morphiteLine), self::MORPHITE_TYPE_ID, 'Morphite', 150, 100, 'partial', null],
            ],
            $this->containerRows(),
        );
    }

    public function testOwnerVerifyingWithoutSyncedCorporationAssetsGetsEveryMaterialLineUnknown(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->containerVerificationUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [$this->idOf($this->tritaniumLine), self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000, null, 'unknown', 'corporation_assets_not_synced'],
                [$this->idOf($this->nocxiumLine), self::NOCXIUM_TYPE_ID, 'Nocxium', 600, null, 'unknown', 'corporation_assets_not_synced'],
                [$this->idOf($this->morphiteLine), self::MORPHITE_TYPE_ID, 'Morphite', 150, null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $this->containerRows(),
        );
    }

    public function testPlainMemberIsRefusedTheContainerVerification(): void
    {
        $this->persistIshtarContainerInTheFirstDivision();
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->containerVerificationUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
    }

    public function testOutsiderIsRefusedTheContainerVerification(): void
    {
        $this->persistIshtarContainerInTheFirstDivision();
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->containerVerificationUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
    }

    #[Group('issue-206')]
    #[DataProvider('projectRoutes')]
    public function testProjectRouteOnAProjectIdentifierThatIsNotAUuidCurrentlyAnswersAServerErrorIssue206(string $method, string $uriAfterTheProject, ?array $body): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request($method, '/api/group-industry/projects/'.self::NOT_A_UUID.$uriAfterTheProject, $body);

        self::assertResponseStatusCodeSame(500);
        self::assertSame([500, 'Invalid UUID.'], $this->errorDetail());
    }

    public static function projectRoutes(): iterable
    {
        $uuid = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';

        yield 'members' => ['GET', '/members', null];
        yield 'member update' => ['PATCH', '/members/'.$uuid, ['status' => 'accepted']];
        yield 'member kick' => ['DELETE', '/members/'.$uuid, null];
        yield 'leave' => ['POST', '/leave', []];
        yield 'contributions' => ['GET', '/contributions', null];
        yield 'contribution submission' => ['POST', '/contributions', ['type' => 'material', 'bomItemId' => $uuid, 'quantity' => 10]];
        yield 'contribution review' => ['PATCH', '/contributions/'.$uuid, ['status' => 'approved']];
        yield 'bom' => ['GET', '/bom', null];
        yield 'distribution' => ['GET', '/distribution', null];
        yield 'container verification' => ['GET', '/container-verification', null];
        yield 'sales' => ['GET', '/sales', null];
    }

    private function containerVerificationUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/container-verification';
    }

    private function persistIshtarContainerInTheFirstDivision(): void
    {
        $this->em->persist((new CorpAssetVisibility())->setCorporationId(self::OWNER_CORPORATION_ID)->setVisibleDivisions([1])->setConfiguredBy($this->owner));
        $this->persistCorporationAsset(self::CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 1, self::JITA_STATION_ID, 'station', 'CorpSAG1', self::CONTAINER_NAME);
        $this->persistCorporationAsset(1_020_600_011, self::TRITANIUM_TYPE_ID, 7_000, self::CONTAINER_ITEM_ID, 'item', null, null);
        $this->persistCorporationAsset(1_020_600_012, self::MORPHITE_TYPE_ID, 100, self::CONTAINER_ITEM_ID, 'item', null, null);
        $this->persistCorporationAsset(self::SUB_CONTAINER_ITEM_ID, self::SMALL_SECURE_CONTAINER_TYPE_ID, 1, self::CONTAINER_ITEM_ID, 'item', null, 'Spare');
        $this->persistCorporationAsset(1_020_600_021, self::TRITANIUM_TYPE_ID, 5_000, self::SUB_CONTAINER_ITEM_ID, 'item', null, null);
        $this->persistCorporationAsset(1_020_600_031, self::NOCXIUM_TYPE_ID, 600, self::JITA_STATION_ID, 'station', 'CorpSAG1', null);
        $this->em->flush();
    }

    private function persistCorporationAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, ?string $locationFlag, ?string $itemName): void
    {
        $this->em->persist(
            (new CachedAsset())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setQuantity($quantity)
                ->setLocationId($locationId)
                ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
                ->setLocationType($locationType)
                ->setLocationFlag($locationFlag)
                ->setItemName($itemName)
                ->setCorporationId(self::OWNER_CORPORATION_ID)
                ->setIsCorporationAsset(true),
        );
    }

    private function bomRows(): array
    {
        return array_map(
            static fn (array $line): array => [
                $line['typeId'],
                $line['typeName'],
                $line['requiredQuantity'],
                $line['fulfilledQuantity'],
                $line['remainingQuantity'],
                (float) $line['fulfillmentPercent'],
                isset($line['estimatedPrice']) ? (float) $line['estimatedPrice'] : null,
                isset($line['estimatedTotal']) ? (float) $line['estimatedTotal'] : null,
                $line['isJob'],
                $line['jobGroup'] ?? null,
                $line['activityType'] ?? null,
                $line['parentTypeId'] ?? null,
                $line['meLevel'] ?? null,
                $line['teLevel'] ?? null,
                $line['runs'] ?? null,
                $line['isFulfilled'],
            ],
            $this->rowsSortedBy('typeId', $this->responseJson()),
        );
    }

    private function containerRows(): array
    {
        return array_map(
            static fn (array $line): array => [
                $line['bomItemId'],
                $line['typeId'],
                $line['typeName'],
                $line['requiredQuantity'],
                $line['containerQuantity'] ?? null,
                $line['status'],
                $line['unknownReason'] ?? null,
            ],
            $this->rowsSortedBy('typeId', $this->responseJson()),
        );
    }
}

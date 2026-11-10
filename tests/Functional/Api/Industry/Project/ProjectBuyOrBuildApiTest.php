<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-343')]
final class ProjectBuyOrBuildApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int RAM_DEMAND = 298;
    private const int RAM_SURPLUS = 2;

    private const array ROW_KEYS = [
        'activity', 'blacklisted', 'buildPerUnit', 'buildPerUnitIfSurplusReused', 'buyPerUnit', 'choice', 'choiceWithoutImport',
        'importPerUnit', 'jitaPerUnit', 'jumps', 'missingData', 'producedQuantity', 'quantity', 'runs',
        'saving', 'savingIfSurplusReused', 'surplus', 'typeId', 'typeName', 'volumePerUnit', 'warnings',
    ];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(self::RAM_STARSHIP_TECH_TYPE_ID, self::TRITANIUM_TYPE_ID);
    }

    public function testAcceptedParticipantReadsTheBuyOrBuildRowOfTheComponentWithUnknownValuesAsNull(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122(), 150.0, 1], [$body['id'], $body['importIskPerCubicMeterJump'], \count($body['rows'])]);
        $row = $body['rows'][0];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(self::ROW_KEYS, $keys);
        self::assertArrayHasKey('blacklisted', $row);
        self::assertFalse($row['blacklisted']);
        self::assertSame(
            [
                self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 'manufacturing',
                self::RAM_DEMAND, self::RAM_RUNS, self::RAM_RUNS * self::RAM_UNITS_PER_RUN, self::RAM_SURPLUS,
                null, null, null, null, null, null, null,
                'unknown', 'unknown',
            ],
            [
                $row['typeId'], $row['typeName'], $row['activity'],
                $row['quantity'], $row['runs'], $row['producedQuantity'], $row['surplus'],
                $row['volumePerUnit'], $row['jumps'], $row['buildPerUnit'], $row['jitaPerUnit'], $row['importPerUnit'], $row['buyPerUnit'], $row['saving'],
                $row['choice'], $row['choiceWithoutImport'],
            ],
        );
    }

    public function testComponentBlacklistedByTypeKeepsItsRowWithTheSameAmountsAndTheBlacklistedFlag(): void
    {
        $jitaSellOrderBooks = [
            self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => 2_150.0, 'volume' => 10_000]],
            self::TRITANIUM_TYPE_ID => [['price' => 5.5, 'volume' => 10_000_000]],
        ];
        $this->seedJitaSellOrderBooks($jitaSellOrderBooks);
        $this->client->loginUser($this->member, 'api');
        $uri = $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122());

        $this->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);
        $rowsOfThePlan = $this->responseJson()['rows'];

        $this->ishtarBatch->setBlacklistTypeIds([self::RAM_STARSHIP_TECH_TYPE_ID]);
        $this->em->flush();
        $this->actAs($this->member);
        $this->seedJitaSellOrderBooks($jitaSellOrderBooks);
        $this->request('GET', $uri);

        self::assertResponseStatusCodeSame(200);
        $rows = $this->responseJson()['rows'];
        self::assertCount(1, $rowsOfThePlan);
        self::assertSame(2_150.0, $rowsOfThePlan[0]['jitaPerUnit']);
        self::assertArrayHasKey('blacklisted', $rowsOfThePlan[0]);
        self::assertFalse($rowsOfThePlan[0]['blacklisted']);
        self::assertCount(1, $rows);
        self::assertArrayHasKey('blacklisted', $rows[0]);
        self::assertTrue($rows[0]['blacklisted']);
        self::assertSame([...$rowsOfThePlan[0], 'blacklisted' => true], $rows[0]);
    }

    public function testBlacklistedTypeThatIsNotAnIntermediateHasNoRowAndThePlanRowsAreNotBlacklisted(): void
    {
        $this->ishtarBatch->setBlacklistTypeIds([self::TRITANIUM_TYPE_ID]);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $rows = $this->responseJson()['rows'];
        self::assertCount(1, $rows);
        self::assertArrayHasKey('blacklisted', $rows[0]);
        self::assertSame(
            [[self::RAM_STARSHIP_TECH_TYPE_ID, false]],
            array_map(static fn (array $row): array => [$row['typeId'], $row['blacklisted']], $rows),
        );
    }

    public function testPilotOfAnotherCorporationIsRefusedTheBuyOrBuild(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testBuyOrBuildOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->buyOrBuildUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function buyOrBuildUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/buy-or-build';
    }
}

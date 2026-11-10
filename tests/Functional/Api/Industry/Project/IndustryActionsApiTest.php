<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-335')]
final class IndustryActionsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string ACTIONS_URI = '/api/industry/actions';

    private JitaMarketService&Stub $jitaMarket;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->jitaMarket = $this->createStub(JitaMarketService::class);
        self::getContainer()->set(JitaMarketService::class, $this->jitaMarket);
    }

    public function testOwnerOfTwoProjectsReadsTheBlockedProjectBeforeTheContributionsToReview(): void
    {
        $this->givenJitaWeightedSellPrices([self::TRITANIUM_TYPE_ID => ['weightedPrice' => 4.25, 'coverage' => 1.0, 'ordersUsed' => 3]]);
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [
                    'projectId' => $this->hecateBatch->getId()->toRfc4122(),
                    'projectName' => 'Hecate batch',
                    'code' => 'source_material',
                    'blocked' => true,
                    'tab' => 'materials',
                    'targetStatus' => null,
                    'bomLineId' => $this->idOf($this->hecateTritaniumLine),
                    'typeId' => self::TRITANIUM_TYPE_ID,
                    'typeName' => 'Tritanium',
                    'quantity' => 40_000,
                    'severity' => 'warning',
                    'impactIsk' => 170_000.0,
                ],
                [
                    'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
                    'projectName' => 'Ishtar batch',
                    'code' => 'review_contributions',
                    'blocked' => false,
                    'tab' => 'contributions',
                    'targetStatus' => null,
                    'bomLineId' => null,
                    'typeId' => null,
                    'typeName' => null,
                    'quantity' => 1,
                    'severity' => 'info',
                    'impactIsk' => null,
                ],
            ],
            $this->responseJson(),
        );
    }

    public function testBlockedMaterialWithoutJitaPriceKeepsAnUnknownImpactInsteadOfZero(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['source_material', 'warning', null], ['review_contributions', 'info', null]],
            array_map(
                static fn (array $entry): array => [$entry['code'] ?? null, $entry['severity'] ?? null, array_key_exists('impactIsk', $entry) ? $entry['impactIsk'] : 'absent'],
                $this->responseJson(),
            ),
        );
    }

    public function testAcceptedMemberReadsOnlyTheProjectHeBelongsTo(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->ishtarBatch->getId()->toRfc4122(), 'source_material', true, self::TRITANIUM_TYPE_ID, 12_000]],
            array_map(
                static fn (array $entry): array => [$entry['projectId'] ?? null, $entry['code'] ?? null, $entry['blocked'] ?? null, $entry['typeId'] ?? null, $entry['quantity'] ?? null],
                $this->responseJson(),
            ),
        );
    }

    public function testPendingParticipantReadsAnEmptyQueue(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testOutsiderReadsAnEmptyQueue(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testQueueIsRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<int, array{weightedPrice: float, coverage: float, ordersUsed: int}|null> $pricesByTypeId
     */
    private function givenJitaWeightedSellPrices(array $pricesByTypeId): void
    {
        $this->jitaMarket->method('getWeightedSellPricesWithFallback')->willReturn($pricesByTypeId);
    }
}

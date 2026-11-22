<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Enum\ParticipantStatus;
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
        $this->addJobLine($this->hecateBatch, self::HECATE_TYPE_ID, 'Hecate', 'final', 1, 1, 4, 8, null)->setFulfilledQuantity(1);
        $this->em->flush();
        $this->jitaMarket = $this->createStub(JitaMarketService::class);
        self::getContainer()->set(JitaMarketService::class, $this->jitaMarket);
    }

    public function testOwnerOfTwoProjectsReadsTheBlockedProjectBeforeTheContributionsToReview(): void
    {
        $this->acceptThePendingMember();
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
                    'endsAt' => null,
                    'severity' => 'warning',
                    'impactIsk' => 170_000.0,
                    'jitaUnitPrice' => null,
                    'breakEvenPerUnit' => null,
                    'undercutOrder' => null,
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
                    'endsAt' => null,
                    'severity' => 'info',
                    'impactIsk' => null,
                    'jitaUnitPrice' => null,
                    'breakEvenPerUnit' => null,
                    'undercutOrder' => null,
                ],
            ],
            $this->responseJson(),
        );
    }

    public function testBlockedMaterialWithoutJitaPriceKeepsAnUnknownImpactInsteadOfZero(): void
    {
        $this->acceptThePendingMember();
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

    #[Group('issue-1219')]
    public function testOwnerReadsThePendingJoinRequestsBeforeTheContributionsToReview(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $ishtarAction = array_values(array_filter(
            $this->responseJson(),
            fn (array $entry): bool => $entry['projectId'] === $this->ishtarBatch->getId()->toRfc4122(),
        ))[0];
        self::assertSame(
            ['review_join_requests', 'members', 1, 'info'],
            [$ishtarAction['code'], $ishtarAction['tab'], $ishtarAction['quantity'], $ishtarAction['severity']],
        );
    }

    public function testAcceptedMemberReadsOnlyTheProjectHeBelongsTo(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->ishtarBatch->getId()->toRfc4122(), 'source_material', true, self::MORPHITE_TYPE_ID, 147]],
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

    private function acceptThePendingMember(): void
    {
        $this->pendingMembership->setStatus(ParticipantStatus::Accepted);
        $this->em->flush();
    }
}

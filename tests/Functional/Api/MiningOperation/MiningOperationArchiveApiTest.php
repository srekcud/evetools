<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-265')]
final class MiningOperationArchiveApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const string COMPLETED_AT = '2026-11-05T21:58:00+00:00';
    private const string PAID_AT = '2026-11-05T22:24:00+00:00';
    private const string SETTLED_AT = '2026-11-05T23:35:00+00:00';
    private const string P2_LABEL = 'Ceinture Veldspar — mardi';
    private const string P2_ARCHIVED_AT = '2026-11-04T22:10:00+00:00';
    private const string P3_LABEL = 'Ochre belt — samedi';
    private const int P2_ARIA_MINED_VALUE = 812_300;

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testOwnerCompletesAnEachForThemselvesOperationWhichFreezesItsPricesAndValuesWithoutPayment(): void
    {
        $this->closingE1(splitMode: MiningSplitMode::EachForThemselves);
        $this->updateValuation(['valuationSide' => 'sell']);
        self::mockTime(new \DateTimeImmutable(self::COMPLETED_AT));
        $this->as($this->aria);

        $this->post('complete');
        $response = [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['status', 'archivedAt', 'archivedByName', 'sharesFrozenAt'])];
        $this->as($this->aria);
        $this->request('GET', sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId()));

        self::assertSame(
            [
                'response' => [200, 'completed', self::COMPLETED_AT, 'Aria Solberg', self::COMPLETED_AT],
                'frozen' => [
                    'unitPrices' => [[self::VELDSPAR, 19.61], [self::BITUMENS, 221.45]],
                    'minedValues' => [[0, 'Aria Solberg', 2_699_093.0], [1, 'Brann Holt', 2_868_460.0], [2, 'Cyra Lune', 176.0]],
                ],
                'payments' => [200, []],
            ],
            [
                'response' => $response,
                'frozen' => $this->frozenRows(),
                'payments' => [$this->client->getResponse()->getStatusCode(), $this->responseJson()],
            ],
        );
    }

    public function testCompletedOperationKeepsItsValuationAfterTheNextJitaSync(): void
    {
        $this->completedE1();
        $this->jitaSellPrices[self::COMPRESSED_VELDSPAR] = 25.0;
        self::mockTime(new \DateTimeImmutable('2026-11-05T22:30:00+00:00'));
        $this->as($this->brann);

        $this->request('GET', $this->valuationUri());

        $valuation = $this->responseJson();
        self::assertSame(
            [true, self::E1_TOTAL_VALUE, null, false, [], 19.61, [null, null, null]],
            [
                ...$this->fields($valuation, ['frozen', 'totalValue', 'boosterCut', 'canComplete', 'completionBlockers']),
                array_column($valuation['unitPrices'] ?? [], 'unitPrice', 'typeName')['Veldspar'] ?? null,
                array_column($valuation['participants'] ?? [], 'share'),
            ],
        );
    }

    public function testUnknownQuantityOfBrannBlocksTheCompletionAndNothingIsFrozen(): void
    {
        $this->closingE1(unreadStarts: ['Brann II'], splitMode: MiningSplitMode::EachForThemselves);
        $this->updateValuation(['valuationSide' => 'sell']);
        $this->as($this->aria);

        $this->post('complete');

        self::assertSame(
            [[400, 'Every mined quantity must be known before completing the operation: Brann Holt'], MiningOperationStatus::Closing, ['unitPrices' => [], 'minedValues' => []]],
            [$this->errorDetail(), $this->reloadedOperation()->getStatus(), $this->frozenRows()],
        );
    }

    public function testOnlyAClosingOperationCanBeCompleted(): void
    {
        $this->spodumain->setSplitMode(MiningSplitMode::EachForThemselves)->start(new \DateTimeImmutable(self::STARTED_AT), null);
        $this->em->flush();
        $this->as($this->aria);

        $this->post('complete');

        self::assertSame([400, 'Only a closing operation can be completed'], $this->errorDetail());
    }

    public function testValueProrataOperationIsSettledThroughItsSharesAndPayments(): void
    {
        $this->closingE1();
        $this->as($this->aria);

        $this->post('complete');

        self::assertSame([400, 'A value prorata operation is settled through its shares and payments'], $this->errorDetail());
    }

    public function testPlainMemberCannotCompleteTheOperation(): void
    {
        $this->closingE1(splitMode: MiningSplitMode::EachForThemselves);
        $this->as($this->brann);

        $this->post('complete');

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());
    }

    public function testValuationExposesWhatBlocksTheCompletion(): void
    {
        $this->closingE1(unreadStarts: ['Brann II'], splitMode: MiningSplitMode::EachForThemselves);
        $this->as($this->aria);
        $this->request('GET', $this->valuationUri());
        $eachForThemselves = $this->fields($this->responseJson(), ['canComplete', 'completionBlockers', 'freezeBlockers']);
        $this->spodumain = $this->reloadedOperation()->setSplitMode(MiningSplitMode::ValueProrata);
        $this->em->flush();
        $this->as($this->aria);
        $this->request('GET', $this->valuationUri());
        $valueProrata = $this->fields($this->responseJson(), ['canComplete', 'completionBlockers']);

        self::assertSame(
            ['eachForThemselves' => [false, ['quantities_not_known'], ['each_for_themselves']], 'valueProrata' => [false, null]],
            ['eachForThemselves' => $eachForThemselves, 'valueProrata' => $valueProrata],
        );
    }

    public function testArchiveListsTheSettledAndCompletedOperationsLatestFirstWhileMyOperationsKeepsTheOthers(): void
    {
        $this->persistMiningOperation($this->em, $this->aria, self::P3_LABEL, '2026-11-07T18:00:00+00:00', status: MiningOperationStatus::Shares);
        $this->em->flush();
        $this->settledG1();
        $this->completedP2();

        $this->as($this->aria);
        $this->request('GET', '/api/mining-operations/archive');
        $archive = array_column($this->responseJson(), 'label');
        $this->as($this->aria);
        $this->request('GET', '/api/mining-operations');
        $mine = array_column($this->responseJson(), 'label');

        self::assertSame(
            ['archive' => [self::SPODUMAIN_LABEL, self::P2_LABEL], 'mine' => [self::P3_LABEL]],
            ['archive' => $archive, 'mine' => $mine],
        );
    }

    public function testArchiveSummaryOfTheSettledOperationGivesTheValueTheShareAndThePaidPaymentsOfTheOwner(): void
    {
        $this->settledG1();
        $this->completedP2();

        self::assertSame(
            [
                self::SPODUMAIN_LABEL => [
                    'archivedAt' => self::SETTLED_AT,
                    'archivedByName' => 'Aria Solberg',
                    'totalValue' => self::E1_TOTAL_VALUE,
                    'myMinedValue' => 2_699_093,
                    'myShare' => 2_294_229,
                    'paymentsCount' => 3,
                    'paidPaymentsCount' => 3,
                ],
                self::P2_LABEL => [
                    'archivedAt' => self::P2_ARCHIVED_AT,
                    'archivedByName' => 'Aria Solberg',
                    'totalValue' => self::P2_ARIA_MINED_VALUE,
                    'myMinedValue' => self::P2_ARIA_MINED_VALUE,
                    'myShare' => null,
                    'paymentsCount' => 0,
                    'paidPaymentsCount' => 0,
                ],
            ],
            $this->archiveSummariesSeenBy($this->aria),
        );
    }

    public function testCorporationMemberReadsTheResultOfAnArchivedOperationOpenToTheCorporationButNotItsLedger(): void
    {
        $this->settledG1();

        $summary = $this->archiveSummariesSeenBy($this->elia)[self::SPODUMAIN_LABEL] ?? [];
        $outcomes = [];
        foreach (['valuation', 'payments', 'ledger'] as $resource) {
            $this->as($this->elia);
            $this->request('GET', sprintf('/api/mining-operations/%s/%s', $this->spodumain->getId(), $resource));
            $outcomes[$resource] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(
            ['summary' => [self::E1_TOTAL_VALUE, null, null], 'outcomes' => ['valuation' => 200, 'payments' => 200, 'ledger' => 403]],
            ['summary' => $this->fields($summary, ['totalValue', 'myMinedValue', 'myShare']), 'outcomes' => $outcomes],
        );
    }

    public function testCorporationMemberNeitherListsNorReadsAnArchivedPrivateOperation(): void
    {
        $this->settledG1();
        $this->reloadedOperation()->setVisibility(MiningOperationVisibility::Private);
        $this->em->flush();

        $listed = array_keys($this->archiveSummariesSeenBy($this->elia));
        $this->as($this->elia);
        $this->request('GET', $this->valuationUri());

        self::assertSame(
            [[], [403, 'You do not have access to this operation']],
            [$listed, $this->errorDetail()],
        );
    }

    public function testPendingMemberOfAnArchivedPrivateOperationSeesItNowhere(): void
    {
        $archivedPrivate = $this->persistMiningOperation($this->em, $this->elia, self::P2_LABEL, '2026-11-04T20:00:00+00:00', visibility: MiningOperationVisibility::Private, ownerCharacterNames: ['Elia Varr']);
        $this->addMiningMembership($archivedPrivate, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $archivedPrivate->complete(new \DateTimeImmutable(self::P2_ARCHIVED_AT), null, [], [], 'Elia Varr');
        $archivedPrivate->setStatus(MiningOperationStatus::Completed);
        $this->em->flush();

        $this->as($this->cyra);
        $this->request('GET', '/api/mining-operations/archive');
        $archive = array_column($this->responseJson(), 'label');
        $this->as($this->cyra);
        $this->request('GET', '/api/mining-operations');
        $mine = array_column($this->responseJson(), 'label');

        self::assertSame(['archive' => [], 'mine' => [self::SPODUMAIN_LABEL]], ['archive' => $archive, 'mine' => $mine]);
    }

    public function testCorporationMemberCannotReadTheValuationOfAnOperationStillInItsShares(): void
    {
        $this->g1();
        $this->as($this->elia);

        $this->request('GET', $this->valuationUri());

        self::assertSame([403, 'You do not have access to this operation'], $this->errorDetail());
    }

    private function completedE1(): void
    {
        $this->closingE1(splitMode: MiningSplitMode::EachForThemselves);
        $this->updateValuation(['valuationSide' => 'sell']);
        self::mockTime(new \DateTimeImmutable(self::COMPLETED_AT));
        $this->as($this->aria);
        $this->post('complete');
        self::assertResponseStatusCodeSame(200);
    }

    private function settledG1(): void
    {
        $this->g1();
        $this->as($this->aria);
        $this->request('GET', sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId()));
        $paymentIds = array_column($this->responseJson(), 'id');
        self::mockTime(new \DateTimeImmutable(self::PAID_AT));
        foreach ($paymentIds as $paymentId) {
            $this->as($this->aria);
            $this->request('PATCH', sprintf('/api/mining-operations/%s/payments/%s', $this->spodumain->getId(), $paymentId), ['paid' => true]);
        }
        self::mockTime(new \DateTimeImmutable(self::SETTLED_AT));
        $this->as($this->aria);
        $this->post('settle');
        self::assertResponseStatusCodeSame(200);
    }

    private function completedP2(): MiningOperation
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $aria = $em->find(User::class, $this->aria->getId());
        \assert($aria instanceof User);
        $p2 = $this->persistMiningOperation($em, $aria, self::P2_LABEL, '2026-11-04T20:00:00+00:00');
        $p2->setSplitMode(MiningSplitMode::EachForThemselves);
        $p2->complete(
            new \DateTimeImmutable(self::P2_ARCHIVED_AT),
            new \DateTimeImmutable('2026-11-04T22:00:00+00:00'),
            [],
            [new MiningFrozenMinedValue($p2, $p2->membershipOf($aria), 'Aria Solberg', 0, (float) self::P2_ARIA_MINED_VALUE)],
            'Aria Solberg',
        );
        $p2->setStatus(MiningOperationStatus::Completed);
        $em->flush();

        return $p2;
    }

    private function archiveSummariesSeenBy(User $user): array
    {
        $this->as($user);
        $this->request('GET', '/api/mining-operations/archive');

        return array_column($this->responseJson(), 'archiveSummary', 'label');
    }

    private function valuationUri(): string
    {
        return sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId());
    }

    private function frozenRows(): array
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $operationId = (string) $this->spodumain->getId();

        return [
            'unitPrices' => array_map(
                static fn (array $row): array => [(int) $row['type_id'], (float) $row['unit_price']],
                $connection->fetchAllAssociative('SELECT type_id, unit_price FROM mining_frozen_unit_prices WHERE operation_id::text = ? ORDER BY type_id', [$operationId]),
            ),
            'minedValues' => array_map(
                static fn (array $row): array => [(int) $row['position'], $row['participant_name'], (float) $row['mined_value']],
                $connection->fetchAllAssociative('SELECT position, participant_name, mined_value FROM mining_frozen_mined_values WHERE operation_id::text = ? ORDER BY position', [$operationId]),
            ),
        ];
    }
}

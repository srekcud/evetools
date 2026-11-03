<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Service\Account\AccountDeletionService;
use App\Service\JitaMarketService;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReading;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-264')]
final class MiningOperationValuationApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;

    private const string SPODUMAIN_LABEL = 'Anomalie Spodumain — 20:00';
    private const string STARTED_AT = '2026-11-05T20:00:00+00:00';
    private const string BRANN_DEPARTED_AT = '2026-11-05T21:05:00+00:00';
    private const string ENDED_AT = '2026-11-05T21:30:00+00:00';
    private const string READ_AT = '2026-11-05T21:50:00+00:00';
    private const string FROZEN_AT = '2026-11-05T21:52:00+00:00';
    private const string JITA_SYNCED_AT = '2026-11-05T21:00:00+00:00';
    private const int S1 = 30_003_504;
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int BITUMENS = 45492;
    private const int COMPRESSED_BITUMENS = 62454;
    private const int REFINERY = 1_000_000_000_001;
    private const int E1_TOTAL_VALUE = 5_567_729;
    private const array S1_SHARES = [2_429_184, 2_581_614, 158];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;
    private User $elia;
    private MiningOperation $spodumain;
    private MiningOperationMember $cyraMembership;
    private MapSolarSystem $aldranette;
    private array $jitaSellPrices = [
        self::VELDSPAR => 18.37,
        self::COMPRESSED_VELDSPAR => 19.61,
        self::BITUMENS => 213.9,
        self::COMPRESSED_BITUMENS => 221.45,
    ];

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::READ_AT));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedStaticData();
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);
        $this->elia = $this->persistMiningPilot($this->em, 'Elia Varr', self::C1);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, self::SPODUMAIN_LABEL, self::STARTED_AT, ownerCharacterNames: ['Aria Solberg', 'Aria II']);
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->cyraMembership = $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->em->flush();
    }

    public function testNewOperationIsValuedOnCompressedJitaBuyWithoutAnyCut(): void
    {
        $this->as($this->aria);

        $this->request('GET', $this->valuationUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->settings(), $this->responseJson()['settings'] ?? null);
    }

    public function testOwnerChoosesTheSellSideAndCyraAsBoosterAndTheClosingPreviewSplitsE1(): void
    {
        $this->closingE1();
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);

        self::assertResponseStatusCodeSame(200);
        $valuation = $this->responseJson();
        self::assertSame(
            [
                'settings' => $this->settings(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId(), 'boosterCharacterName' => 'Cyra Lune']),
                'totals' => [self::E1_TOTAL_VALUE, 556_773, 0, 2],
                'shares' => self::S1_SHARES,
                'unitPrices' => [['typeId' => self::BITUMENS, 'typeName' => 'Bitumens', 'unitPrice' => 221.45], ['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'unitPrice' => 19.61]],
                'prices' => [self::READ_AT, self::JITA_SYNCED_AT],
                'freeze' => [true, []],
            ],
            [
                'settings' => $valuation['settings'] ?? null,
                'totals' => $this->fields($valuation, ['totalValue', 'boosterCut', 'corporationCut', 'roundingRemainder']),
                'shares' => array_column($valuation['participants'] ?? [], 'share'),
                'unitPrices' => $valuation['unitPrices'] ?? null,
                'prices' => $this->fields($valuation, ['pricesReadAt', 'pricesSyncedAt']),
                'freeze' => $this->fields($valuation, ['canFreeze', 'freezeBlockers']),
            ],
        );

        $this->as($this->brann);
        $this->request('GET', '/api/mining-operations/' . $this->spodumain->getId());
        self::assertSame('Cyra Lune', $this->responseJson()['valuationSettings']['boosterCharacterName'] ?? null);
    }

    public function testStructureMarketWithoutStructureIsRefused(): void
    {
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['valuationMarket' => 'structure']);

        self::assertSame([400, 'A structure market needs a structure'], $this->errorDetail());
    }

    public function testBackToJitaForgetsTheStructure(): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationMarket' => 'structure', 'structureId' => self::REFINERY, 'structureName' => 'Refinery B-1']);
        self::assertSame(['structure', self::REFINERY, 'Refinery B-1'], $this->fields($this->responseJson()['settings'] ?? [], ['valuationMarket', 'structureId', 'structureName']));

        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationMarket' => 'jita']);

        self::assertSame([200, null, null], [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson()['settings'] ?? [], ['structureId', 'structureName'])]);
    }

    public function testReprocessedValuationNeedsAYieldWhichIsForgottenWhenLeavingTheReprocessedForm(): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationForm' => 'reprocessed']);
        self::assertSame([400, 'A reprocessed valuation needs a reprocessing yield'], $this->errorDetail());

        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationForm' => 'reprocessed', 'reprocessYield' => 0.72358]);
        self::assertSame(0.72358, $this->responseJson()['settings']['reprocessYield'] ?? null);

        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationForm' => 'compressed']);
        self::assertSame(['compressed', null], $this->fields($this->responseJson()['settings'] ?? [], ['valuationForm', 'reprocessYield']));
    }

    public function testOutOfBoundsSettingsAreViolationsOnTheirField(): void
    {
        $outcomes = [];
        foreach ([
            ['reprocessYield' => 0],
            ['reprocessYield' => 1.5],
            ['boosterCutPercent' => 101],
            ['boosterCutPercent' => -1],
            ['valuationForm' => 'best'],
            ['valuationSide' => 'mid'],
        ] as $body) {
            $this->as($this->aria);
            $this->request('PATCH', $this->valuationUri(), $body);
            $outcomes[] = [$this->client->getResponse()->getStatusCode(), $this->violatedPaths()];
        }

        self::assertSame(
            [[422, ['reprocessYield']], [422, ['reprocessYield']], [422, ['boosterCutPercent']], [422, ['boosterCutPercent']], [422, ['valuationForm']], [422, ['valuationSide']]],
            $outcomes,
        );
    }

    public function testCutsAboveOneHundredPercentAreRefused(): void
    {
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boosterCutPercent' => 60, 'corporationCutPercent' => 41]);

        self::assertSame([400, 'Booster and corporation cuts cannot exceed 100 %'], $this->errorDetail());
    }

    public function testCorporationCutOfAPrivateOperationWithoutCorporationIsRefused(): void
    {
        $ochreBelt = $this->persistMiningOperation($this->em, $this->dorn, 'Ochre belt — samedi', '2026-11-07T18:00:00+00:00', visibility: MiningOperationVisibility::Private, corporationId: null, ownerCharacterNames: ['Dorn Achar']);
        $this->em->flush();
        $this->as($this->dorn);

        $this->request('PATCH', sprintf('/api/mining-operations/%s/valuation', $ochreBelt->getId()), ['corporationCutPercent' => 5]);

        self::assertSame([400, 'A corporation cut needs a known corporation'], $this->errorDetail());
    }

    public function testOnlyAnAcceptedParticipantOfTheOperationCanBeTheBooster(): void
    {
        $eliaMembership = $this->addMiningMembership($this->spodumain, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $iceBelt = $this->persistMiningOperation($this->em, $this->cyra, 'Ice belt — dimanche', '2026-11-08T18:00:00+00:00', corporationId: self::C2);
        $this->em->flush();

        $outcomes = [];
        foreach ([(string) $eliaMembership->getId(), (string) $iceBelt->getMembers()->first()->getId()] as $boosterMemberId) {
            $this->as($this->aria);
            $this->request('PATCH', $this->valuationUri(), ['boosterMemberId' => $boosterMemberId]);
            $outcomes[] = $this->errorDetail();
        }

        self::assertSame(array_fill(0, 2, [400, 'The booster must be an accepted participant']), $outcomes);
    }

    public function testPlainMemberIsRefusedBeforeHisInputIsValidated(): void
    {
        $outcomes = [];
        foreach ([['boosterCutPercent' => 10], ['boosterCutPercent' => 101]] as $body) {
            $this->as($this->brann);
            $this->request('PATCH', $this->valuationUri(), $body);
            $outcomes[] = $this->errorDetail();
        }

        self::assertSame(array_fill(0, 2, [403, 'Only the operation owner or an admin can perform this action']), $outcomes);
    }

    public function testSettingsCanChangeWhileTheOperationIsInProgress(): void
    {
        $this->spodumain->start(new \DateTimeImmutable(self::STARTED_AT), $this->aldranette);
        $this->em->flush();
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boosterCutPercent' => 10]);

        self::assertSame([200, 10], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['settings']['boosterCutPercent'] ?? null]);
    }

    public function testPendingPilotCannotReadTheValuation(): void
    {
        $this->addMiningMembership($this->spodumain, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $this->em->flush();
        $this->as($this->elia);

        $this->request('GET', $this->valuationUri());

        self::assertSame([403, 'You do not have access to this operation'], $this->errorDetail());
    }

    public function testEachForThemselvesOperationHasNoCutNoShareAndNoFreeze(): void
    {
        $this->spodumain->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->closingE1();

        $outcomes = [];
        foreach ([['boosterCutPercent' => 10], ['boosterMemberId' => (string) $this->cyraMembership->getId()]] as $body) {
            $this->as($this->aria);
            $this->request('PATCH', $this->valuationUri(), $body);
            $outcomes[] = $this->errorDetail();
        }
        $this->as($this->aria);
        $this->post('freeze-shares');
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationForm' => 'raw', 'valuationSide' => 'sell']);
        $rawValuation = $this->responseJson();

        self::assertSame(
            [
                [400, 'An each-for-themselves operation has no booster or corporation cut'],
                [400, 'An each-for-themselves operation has no booster or corporation cut'],
                [400, 'An each-for-themselves operation has no shares'],
                ['raw', 2_549_929, [null, null, null], ['each_for_themselves'], false],
            ],
            [
                ...$outcomes,
                [
                    $rawValuation['settings']['valuationForm'] ?? null,
                    $rawValuation['participants'][0]['minedValue'] ?? null,
                    array_column($rawValuation['participants'] ?? [], 'share'),
                    $rawValuation['freezeBlockers'] ?? null,
                    $rawValuation['canFreeze'] ?? null,
                ],
            ],
        );
    }

    public function testOwnerFreezesTheSharesWhichCopiesThePricesAndTheMinedValues(): void
    {
        $this->closingE1();
        $this->cyraBoostsForTenPercentOnTheSellSide();
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame([200, 'shares', self::FROZEN_AT], [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['status', 'sharesFrozenAt'])]);
        self::assertSame(
            [
                'unitPrices' => [[self::VELDSPAR, 19.61], [self::BITUMENS, 221.45]],
                'minedValues' => [[0, 'Aria Solberg', 2_699_093.0], [1, 'Brann Holt', 2_868_460.0], [2, 'Cyra Lune', 176.0]],
            ],
            $this->frozenRows(),
        );
    }

    public function testFrozenSharesKeepTheirPricesAfterTheNextJitaSync(): void
    {
        $this->frozenE1();
        $this->jitaSellPrices[self::COMPRESSED_VELDSPAR] = 25.0;
        self::mockTime(new \DateTimeImmutable('2026-11-05T22:30:00+00:00'));
        $this->as($this->brann);

        $this->request('GET', $this->valuationUri());

        $valuation = $this->responseJson();
        self::assertSame(
            [true, self::FROZEN_AT, self::FROZEN_AT, self::JITA_SYNCED_AT, self::E1_TOTAL_VALUE, 556_773, self::S1_SHARES, 19.61],
            [
                ...$this->fields($valuation, ['frozen', 'sharesFrozenAt', 'pricesReadAt', 'pricesSyncedAt', 'totalValue', 'boosterCut']),
                array_column($valuation['participants'] ?? [], 'share'),
                array_column($valuation['unitPrices'] ?? [], 'unitPrice', 'typeName')['Veldspar'] ?? null,
            ],
        );
    }

    public function testFrozenValueOfADeletedAccountKeepsItsShareUnderItsFrozenName(): void
    {
        $this->frozenE1();
        $deletedAccount = $this->em->find(User::class, $this->brann->getId());
        \assert($deletedAccount instanceof User);
        self::getContainer()->get(AccountDeletionService::class)->delete($deletedAccount);
        $this->as($this->aria);

        $this->request('GET', $this->valuationUri());

        $valuation = $this->responseJson();
        self::assertSame(
            [[null, 'Brann Holt', 2_868_460, 2_581_614], self::S1_SHARES],
            [
                $this->fields($valuation['participants'][1] ?? [], ['memberId', 'characterName', 'minedValue', 'share']),
                array_column($valuation['participants'] ?? [], 'share'),
            ],
        );
    }

    public function testOperationThatIsNotClosingCannotHaveItsSharesFrozen(): void
    {
        $this->spodumain->start(new \DateTimeImmutable(self::STARTED_AT), $this->aldranette);
        $this->em->flush();
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame([400, 'Only a closing operation can have its shares frozen'], $this->errorDetail());
    }

    public function testUnknownQuantityOfBrannBlocksTheFreezeAndNothingIsFrozen(): void
    {
        $this->closingE1(unreadStarts: ['Brann II']);
        $this->cyraBoostsForTenPercentOnTheSellSide();
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame(
            [[400, 'Every mined quantity must be known before freezing the shares: Brann Holt'], MiningOperationStatus::Closing, ['unitPrices' => [], 'minedValues' => []]],
            [$this->errorDetail(), $this->reloadedOperation()->getStatus(), $this->frozenRows()],
        );
    }

    public function testOreWithoutPriceOnTheBasisBlocksTheFreeze(): void
    {
        unset($this->jitaSellPrices[self::COMPRESSED_BITUMENS]);
        $this->closingE1();
        $this->cyraBoostsForTenPercentOnTheSellSide();
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame([400, 'Some ores have no price on the valuation basis: Bitumens'], $this->errorDetail());
    }

    public function testBoosterCutWithoutBoosterBlocksTheFreeze(): void
    {
        $this->closingE1();
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationSide' => 'sell', 'boosterCutPercent' => 10]);
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame([400, 'Choose the booster who receives the booster cut'], $this->errorDetail());
    }

    public function testPlainMemberCannotFreezeTheShares(): void
    {
        $this->closingE1();
        $this->as($this->brann);

        $this->post('freeze-shares');

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());
    }

    public function testFrozenSharesCannotBeFrozenAgainNorHaveTheirSettingsOrQuantitiesChanged(): void
    {
        $this->frozenE1();

        $outcomes = [];
        $this->as($this->aria);
        $this->post('freeze-shares');
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['boosterCutPercent' => 20]);
        $outcomes[] = $this->errorDetail();
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann II')), ['typeId' => self::VELDSPAR, 'quantity' => 40_000]);
        $outcomes[] = $this->errorDetail();

        self::assertSame(
            [
                [400, 'Only a closing operation can have its shares frozen'],
                [400, 'The shares of this operation are frozen'],
                [400, 'Quantities can only be corrected while the operation is closing'],
            ],
            $outcomes,
        );
    }

    public function testFailedEndSnapshotCannotBeRetriedOnceTheSharesAreFrozen(): void
    {
        $this->closingE1(failedEnds: ['Brann II']);
        $this->enrolmentNamed('Brann II')->correct(self::VELDSPAR, null, 33_337, $this->aria, new \DateTimeImmutable('2026-11-05T21:45:00+00:00'));
        $this->em->flush();
        $this->cyraBoostsForTenPercentOnTheSellSide();
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);
        $this->post('freeze-shares');
        self::assertResponseStatusCodeSame(200);
        $this->as($this->aria);

        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));

        self::assertSame([400, 'This snapshot cannot be retried'], $this->errorDetail());
    }

    public function testCharacterOfFrozenSharesIsFreeToJoinAnotherOperation(): void
    {
        $nextOperation = $this->persistMiningOperation($this->em, $this->elia, 'Ochre belt — samedi', '2026-11-07T18:00:00+00:00', ownerCharacterNames: ['Elia Varr']);
        $this->em->flush();
        $this->frozenE1();
        $this->as($this->brann);

        $this->request('POST', '/api/mining-operations/join/' . $nextOperation->getJoinCode(), ['characterIds' => [(string) $this->miningCharacters['Brann Holt']->getId()]]);

        self::assertSame([200, 'accepted'], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['myStatus'] ?? null]);
    }

    private function closingE1(array $unreadStarts = [], array $failedEnds = []): void
    {
        $operation = $this->em->find(MiningOperation::class, $this->spodumain->getId());
        \assert($operation instanceof MiningOperation);
        $this->spodumain = $operation;
        $startedAt = new \DateTimeImmutable(self::STARTED_AT);
        $operation->start($startedAt, $this->em->find(MapSolarSystem::class, self::S1));
        $startLedgers = [
            'Aria Solberg' => [[self::VELDSPAR, 5_000]],
            'Aria II' => [],
            'Brann Holt' => [[self::BITUMENS, 1_000]],
            'Brann II' => [],
            'Cyra Lune' => [[self::VELDSPAR, 2]],
        ];
        foreach ($startLedgers as $characterName => $rows) {
            $start = $this->enrolmentNamed($characterName)->scheduleStartSnapshot($startedAt);
            if (in_array($characterName, $unreadStarts, true)) {
                $start->fail(MiningLedgerSnapshotStatus::ReconnectRequired, 'Token revoked');
            } else {
                $start->take(new \DateTimeImmutable('2026-11-05T20:00:02+00:00'), $this->reading($rows));
            }
        }

        $brannDepartedAt = new \DateTimeImmutable(self::BRANN_DEPARTED_AT);
        $this->membershipNamed('Brann Holt')->depart($brannDepartedAt);
        $endedAt = new \DateTimeImmutable(self::ENDED_AT);
        $operation->end($endedAt);
        $endLedgers = [
            'Aria Solberg' => [$endedAt, [[self::VELDSPAR, 105_000]]],
            'Aria II' => [$endedAt, [[self::BITUMENS, 3_333]]],
            'Brann Holt' => [$brannDepartedAt, [[self::BITUMENS, 11_001]]],
            'Brann II' => [$brannDepartedAt, [[self::VELDSPAR, 33_337]]],
            'Cyra Lune' => [$endedAt, [[self::VELDSPAR, 11]]],
        ];
        foreach ($endLedgers as $characterName => [$referenceAt, $rows]) {
            if (in_array($characterName, $unreadStarts, true)) {
                continue;
            }
            $end = $this->enrolmentNamed($characterName)->scheduleEndSnapshot($referenceAt);
            $end->schedule(new \DateTimeImmutable('2026-11-05T21:42:00+00:00'));
            if (in_array($characterName, $failedEnds, true)) {
                $end->fail(MiningLedgerSnapshotStatus::Failed, 'ESI server error');
            } else {
                $end->take(new \DateTimeImmutable('2026-11-05T21:42:01+00:00'), $this->reading($rows));
            }
        }
        $this->em->flush();
    }

    private function frozenE1(): void
    {
        $this->closingE1();
        $this->cyraBoostsForTenPercentOnTheSellSide();
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);
        $this->post('freeze-shares');
        self::assertResponseStatusCodeSame(200);
    }

    private function cyraBoostsForTenPercentOnTheSellSide(): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        self::assertResponseStatusCodeSame(200);
    }

    private function reading(array $rows): MiningLedgerReading
    {
        return new MiningLedgerReading(
            array_map(static fn (array $row): MiningLedgerEntry => new MiningLedgerEntry(new \DateTimeImmutable('2026-11-05T00:00:00+00:00'), $row[0], self::S1, $row[1]), $rows),
            null,
        );
    }

    private function enrolmentNamed(string $characterName): MiningOperationCharacter
    {
        foreach ($this->spodumain->getMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                if ($enrolment->getCharacter()->getName() === $characterName) {
                    return $enrolment;
                }
            }
        }

        throw new \LogicException(sprintf('%s is not enrolled', $characterName));
    }

    private function membershipNamed(string $mainCharacterName): MiningOperationMember
    {
        foreach ($this->spodumain->getMembers() as $member) {
            if ($member->getUser()->getMainCharacter()?->getName() === $mainCharacterName) {
                return $member;
            }
        }

        throw new \LogicException(sprintf('%s is not a member', $mainCharacterName));
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

    private function replaceMarket(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getPrices')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, $this->jitaSellPrices));
        $jitaMarket->method('getBuyPrices')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, []));
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
        self::getContainer()->set('logger', new NullLogger());
    }

    private function pricesOf(array $typeIds, array $prices): array
    {
        return array_combine($typeIds, array_map(static fn (int $typeId): ?float => $prices[$typeId] ?? null, $typeIds));
    }

    private function as(User $user): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function seedStaticData(): void
    {
        $region = (new MapRegion())->setRegionId(10_000_032)->setRegionName('Sinq Laison');
        $constellation = (new MapConstellation())->setConstellationId(20_000_441)->setConstellationName('Ancbeu')->setRegion($region);
        $this->aldranette = (new MapSolarSystem())->setSolarSystemId(self::S1)->setSolarSystemName('Aldranette')->setConstellation($constellation)->setRegionId(10_000_032);
        $category = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $veldsparGroup = (new InvGroup())->setGroupId(462)->setGroupName('Veldspar')->setCategory($category);
        $moonGroup = (new InvGroup())->setGroupId(1884)->setGroupName('Ubiquitous Moon Asteroids')->setCategory($category);
        foreach ([$region, $constellation, $this->aldranette, $category, $veldsparGroup, $moonGroup] as $staticRow) {
            $this->em->persist($staticRow);
        }
        foreach ([
            [self::VELDSPAR, 'Veldspar', $veldsparGroup, 0.1],
            [self::COMPRESSED_VELDSPAR, 'Compressed Veldspar', $veldsparGroup, 0.001],
            [self::BITUMENS, 'Bitumens', $moonGroup, 10.0],
            [self::COMPRESSED_BITUMENS, 'Compressed Bitumens', $moonGroup, 0.1],
        ] as [$typeId, $typeName, $group, $volume]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume($volume)->setPortionSize(100)->setPublished(true));
        }
    }

    private function enrolmentIdOf(string $characterName): string
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (string) $connection->fetchOne(
            'SELECT c.id FROM mining_operation_characters c JOIN mining_operation_members m ON m.id = c.member_id WHERE m.operation_id::text = ? AND c.character_id::text = ?',
            [(string) $this->spodumain->getId(), (string) $this->miningCharacters[$characterName]->getId()],
        );
    }

    private function reloadedOperation(): MiningOperation
    {
        $operation = self::getContainer()->get(EntityManagerInterface::class)->find(MiningOperation::class, $this->spodumain->getId());
        \assert($operation instanceof MiningOperation);

        return $operation;
    }

    private function valuationUri(): string
    {
        return sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId());
    }

    private function settings(array $changes = []): array
    {
        return array_replace([
            'valuationForm' => 'compressed',
            'valuationMarket' => 'jita',
            'valuationSide' => 'buy',
            'structureId' => null,
            'structureName' => null,
            'reprocessYield' => null,
            'boosterCutPercent' => 0,
            'corporationCutPercent' => 0,
            'boosterMemberId' => null,
            'boosterCharacterName' => null,
        ], $changes);
    }

    private function post(string $action, array $body = []): void
    {
        $this->request('POST', sprintf('/api/mining-operations/%s/%s', $this->spodumain->getId(), $action), $body);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function fields(array $resource, array $names): array
    {
        return array_map(static fn (string $name): mixed => $resource[$name] ?? null, $names);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_column($this->responseJson()['violations'] ?? [], 'propertyPath')));
    }
}

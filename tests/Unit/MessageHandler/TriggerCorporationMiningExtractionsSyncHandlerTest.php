<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SyncCorporationMiningExtractions;
use App\Message\TriggerCorporationMiningExtractionsSync;
use App\MessageHandler\TriggerCorporationMiningExtractionsSyncHandler;
use App\Tests\Unit\Service\Sync\CorporationMiningExtractionHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(TriggerCorporationMiningExtractionsSyncHandler::class)]
#[Group('issue-274')]
final class TriggerCorporationMiningExtractionsSyncHandlerTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
    }

    public function testThreeCharactersInTwoCorporationsDispatchOneSyncPerCorporation(): void
    {
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);
        $this->addCharacter($this->userA, 'Tovan Reske', 2_112_274_002, self::CORPORATION_B, [self::MINING_SCOPE], []);
        $this->addCharacter($this->userB, 'Aurel Keth', 2_112_274_003, self::CORPORATION_A, [], []);

        $this->trigger();

        self::assertSame([self::CORPORATION_A, self::CORPORATION_B], $this->dispatchedCorporationIds());
        self::assertSame([SyncCorporationMiningExtractions::class, SyncCorporationMiningExtractions::class], array_map(get_class(...), $this->dispatchedMessages));
    }

    public function testACorporationWithoutAnyStationManagerIsStillDispatchedSoItsDeniedStateIsRecorded(): void
    {
        $this->addCharacter($this->userC, 'Sora Vantel', 2_112_274_004, self::CORPORATION_C, [self::MINING_SCOPE], []);

        $this->trigger();

        self::assertSame([self::CORPORATION_C], $this->dispatchedCorporationIds());
    }

    public function testTheTriggerNeitherCallsEsiNorWritesAnything(): void
    {
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);
        $this->stationManager($this->userB, 'Aurel Keth', 2_112_274_003, self::CORPORATION_B);

        $this->trigger();

        self::assertSame([self::CORPORATION_A, self::CORPORATION_B], $this->dispatchedCorporationIds());
        self::assertSame([], $this->esiCalls);
        self::assertSame([], $this->storedSyncStates);
    }

    public function testTheScheduledRunIsTrackedForTheAdminDashboard(): void
    {
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);

        $this->trigger();

        $trackedRuns = array_values(array_filter(
            $this->syncTracker->getAll(),
            static fn (array $entry): bool => $entry['type'] === 'corporation-mining-extractions',
        ));
        self::assertSame([['ok', 1800]], array_map(static fn (array $entry): array => [$entry['status'], $entry['expected_interval']], $trackedRuns));
    }

    private function trigger(): void
    {
        $this->build(TriggerCorporationMiningExtractionsSyncHandler::class)(new TriggerCorporationMiningExtractionsSync());
    }

    private function dispatchedCorporationIds(): array
    {
        $corporationIds = array_map(
            static fn (object $message): int => $message instanceof SyncCorporationMiningExtractions ? $message->corporationId : 0,
            $this->dispatchedMessages,
        );
        sort($corporationIds);

        return $corporationIds;
    }
}

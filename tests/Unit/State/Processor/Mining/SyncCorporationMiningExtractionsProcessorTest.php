<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\Message\SyncCorporationMiningExtractions;
use App\State\Processor\Mining\SyncCorporationMiningExtractionsProcessor;
use App\Tests\Unit\Service\Sync\CorporationMiningExtractionHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncCorporationMiningExtractionsProcessor::class)]
#[Group('issue-274')]
final class SyncCorporationMiningExtractionsProcessorTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);
        $this->addCharacter($this->userA, 'Tovan Reske', 2_112_274_002, self::CORPORATION_B, [], []);
        $this->addCharacter($this->userA, 'Lysa Moreau', 2_112_274_005, self::CORPORATION_A, [], []);
        $this->stationManager($this->userB, 'Aurel Keth', 2_112_274_003, self::CORPORATION_A);
        $this->stationManager($this->userC, 'Sora Vantel', 2_112_274_004, self::CORPORATION_C);
    }

    public function testTheRefreshDispatchesOneSyncPerCorporationOfTheUserAndNothingElse(): void
    {
        $this->process();

        self::assertSame(
            [[SyncCorporationMiningExtractions::class, self::CORPORATION_A], [SyncCorporationMiningExtractions::class, self::CORPORATION_B]],
            $this->dispatched(),
        );
        self::assertSame([], $this->esiCalls);
        self::assertSame([], $this->storedSyncStates);
        self::assertSame([], $this->storedExtractions);
    }

    public function testTheRefreshOfAUserOfAnotherCorporationDispatchesOnlyHisCorporation(): void
    {
        $this->currentUser = $this->userC;

        $this->process();

        self::assertSame([[SyncCorporationMiningExtractions::class, self::CORPORATION_C]], $this->dispatched());
    }

    public function testTheRefreshOfAUserWithoutCharacterDispatchesNothing(): void
    {
        $this->currentUser = $this->userWithId('0195f3a0-2740-7000-8000-00000000000d');

        $this->process();

        self::assertSame([], $this->dispatched());
    }

    private function process(): void
    {
        $this->build(SyncCorporationMiningExtractionsProcessor::class)->process(new EmptyInput(), new Post());
    }

    private function dispatched(): array
    {
        $messages = array_map(
            static fn (object $message): array => [$message::class, $message instanceof SyncCorporationMiningExtractions ? $message->corporationId : 0],
            $this->dispatchedMessages,
        );
        usort($messages, static fn (array $left, array $right): int => $left[1] <=> $right[1]);

        return $messages;
    }
}

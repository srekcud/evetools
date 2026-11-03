<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SyncCorporationMiningExtractions;
use App\MessageHandler\SyncCorporationMiningExtractionsHandler;
use App\Tests\Unit\Service\Sync\CorporationMiningExtractionHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncCorporationMiningExtractionsHandler::class)]
#[Group('issue-274')]
final class SyncCorporationMiningExtractionsHandlerTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);
        $this->stationManager($this->userA, 'Tovan Reske', 2_112_274_002, self::CORPORATION_B);
        $this->respondWithCorporationMoons(self::CORPORATION_A);
        $this->respondWithCorporationMoons(self::CORPORATION_B);
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [self::rockHollowExtraction()];
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_B)] = [self::deepCoreExtraction()];
    }

    public function testTheMessageSyncsOnlyTheExtractionsOfItsCorporation(): void
    {
        $this->handle(self::CORPORATION_B);

        self::assertSame([self::deepCoreRow(self::CORPORATION_B)], $this->storedExtractionRows());
        self::assertSame(
            ['lastSuccessAt' => '2026-11-05T19:00:00+00:00', 'lastAttemptAt' => '2026-11-05T19:00:00+00:00', 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_B),
        );
        self::assertNull($this->storedSyncStateOf(self::CORPORATION_A));
    }

    public function testAnEsiFailureIsRecordedWithoutFailingTheMessage(): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), 502);

        $this->handle(self::CORPORATION_A);

        self::assertSame(
            ['lastSuccessAt' => null, 'lastAttemptAt' => '2026-11-05T19:00:00+00:00', 'lastFailure' => 'esi_error'],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    private function handle(int $corporationId): void
    {
        $this->build(SyncCorporationMiningExtractionsHandler::class)(new SyncCorporationMiningExtractions($corporationId));
    }
}

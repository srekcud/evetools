<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Service\Sde\ResolvedMoon;
use App\Service\Sync\CorporationMiningExtractionSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationMiningExtractionSyncService::class)]
#[Group('issue-849')]
final class CorporationMiningExtractionSdeMoonNamesTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    private const int ILYRA_ID = 2_112_849_001;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
        $this->stationManager($this->userA, 'Ilyra Castell', self::ILYRA_ID, self::CORPORATION_A);
        $this->respondWithCorporationMoons(self::CORPORATION_A);
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [self::rockHollowExtraction(), self::deepCoreExtraction()];
    }

    public function testAMoonKnownToTheSdeIsNamedWithoutAnyEsiMoonCallAndWithoutWarning(): void
    {
        $this->sdeMoons = [
            self::NOL_M9_MOON_ID => new ResolvedMoon('NOL-M9 VII - Moon 3 (sde)', self::NOL_M9_SYSTEM_ID),
            self::IHAKANA_MOON_ID => new ResolvedMoon('Ihakana II - Moon 1 (sde)', self::IHAKANA_SYSTEM_ID),
        ];

        $this->build(CorporationMiningExtractionSyncService::class)->sync(self::CORPORATION_A);

        self::assertSame([], $this->esiCallsTo(self::moonEndpoint(self::NOL_M9_MOON_ID)));
        self::assertSame([], $this->esiCallsTo(self::moonEndpoint(self::IHAKANA_MOON_ID)));
        self::assertSame(
            [
                array_replace(self::deepCoreRow(self::CORPORATION_A), ['moonName' => 'Ihakana II - Moon 1 (sde)']),
                array_replace(self::rockHollowRow(self::CORPORATION_A), ['moonName' => 'NOL-M9 VII - Moon 3 (sde)']),
            ],
            $this->storedExtractionRows(),
        );
        self::assertSame([], $this->loggedWarnings);
    }

    public function testAMoonMissingFromTheSdeIsLoggedAsAWarningThenNamedByEsiWhileTheKnownMoonIsNotReadFromEsi(): void
    {
        $this->sdeMoons = [self::NOL_M9_MOON_ID => new ResolvedMoon('NOL-M9 VII - Moon 3 (sde)', self::NOL_M9_SYSTEM_ID)];

        $this->build(CorporationMiningExtractionSyncService::class)->sync(self::CORPORATION_A);

        self::assertSame([], $this->esiCallsTo(self::moonEndpoint(self::NOL_M9_MOON_ID)));
        self::assertCount(1, $this->esiCallsTo(self::moonEndpoint(self::IHAKANA_MOON_ID)));
        self::assertSame(
            [self::deepCoreRow(self::CORPORATION_A), array_replace(self::rockHollowRow(self::CORPORATION_A), ['moonName' => 'NOL-M9 VII - Moon 3 (sde)'])],
            $this->storedExtractionRows(),
        );
        self::assertCount(1, $this->loggedWarnings);
        self::assertSame(self::IHAKANA_MOON_ID, $this->loggedWarnings[0]['moon_id']);
    }
}
